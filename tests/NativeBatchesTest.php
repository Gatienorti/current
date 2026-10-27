<?php

namespace Current\Tests;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Redis;

final class NativeBatchesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('queue.batching', ['database' => 'app', 'table' => 'job_batches']);
        Schema::connection('app')->create('job_batches', function ($t) {
            $t->string('id')->primary(); $t->string('name'); $t->integer('total_jobs'); $t->integer('pending_jobs');
            $t->integer('failed_jobs'); $t->longText('failed_job_ids'); $t->mediumText('options')->nullable();
            $t->integer('cancelled_at')->nullable(); $t->integer('created_at'); $t->integer('finished_at')->nullable();
        });
        Gate::define('viewCurrent', fn ($user) => true);
    }

    private function batch(string $label, int $count = 2)
    {
        return Bus::batch(array_map(fn ($i) => new RetryBatchFixture($label.'-'.$i), range(1, $count)))
            ->name($label)->allowFailures()->onConnection('redis')->onQueue('lens-demo')->dispatch();
    }

    public function test_native_batch_browser_asset_is_available_and_still_requires_dashboard_access(): void
    {
        $this->get('/current/assets/batch-retry.js')->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8')
            ->assertSee('data-current-batch-retry', false);
        Gate::define('viewCurrent', fn ($user) => false);
        $this->get('/current/assets/batch-retry.js')->assertForbidden();
    }

    public function test_batch_search_escapes_literals_and_paginates_beyond_the_latest_fifty(): void
    {
        $rows = [];
        for ($i = 1; $i <= 55; $i++) {
            $rows[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i), 'name' => 'Architecture batch '.$i,
                'total_jobs' => 0, 'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]',
                'options' => serialize([]), 'created_at' => time()];
        }
        app('db')->connection('app')->table('job_batches')->insert($rows);
        $first = $this->get('/current/batches?query=Architecture')->assertOk()->assertSee('Older batches')->assertSee('Architecture batch 55')->assertDontSee('Architecture batch 1</td>', false);
        $first->assertViewHas('rows', fn ($rows) => count($rows) === 50);
        $cursor = sprintf('00000000-0000-4000-8000-%012d', 6);
        $this->get('/current/batches?query=Architecture&before_id='.$cursor)->assertOk()->assertSee('Architecture batch 1')->assertDontSee('Older batches')
            ->assertViewHas('rows', fn ($rows) => count($rows) === 5);
        app('db')->connection('app')->table('job_batches')->where('id', $rows[0]['id'])->update(['name' => 'Literal %_! <script>']);
        $this->get('/current/batches?query='.urlencode('%_!'))->assertOk()->assertSee('Literal %_! &lt;script&gt;', false)
            ->assertDontSee('Literal %_! <script>', false)->assertViewHas('rows', fn ($rows) => count($rows) === 1);
        $this->get('/current/batches?query='.$rows[0]['id'])->assertOk()->assertViewHas('rows', fn ($rows) => count($rows) === 1);
        $this->getJson('/current/batches?query[]=bad')->assertUnprocessable();
        $this->getJson('/current/batches?before_id[]=bad')->assertUnprocessable();
        $this->getJson('/current/batches?query='.str_repeat('a', 101))->assertUnprocessable();
    }

    public function test_batch_inspection_and_retries_share_claims_with_individual_jobs(): void
    {
        $batch = $this->batch('batch-one'); $this->work(2);
        $this->assertSame(2, $batch->fresh()->failedJobs);
        $jobs = $this->store()->table('jobs')->where('batch_id', $batch->id)->get();
        $this->assertCount(2, $jobs);
        $this->get('/current/batches')->assertOk()->assertSee('Inspect batch');
        $url = '/current/batches/'.$batch->id;
        $this->get($url)->assertOk()->assertSee('RetryBatchFixture')->assertDontSee('Retry shown failures');
        $input = ['jobs' => $jobs->pluck('id')->all()];
        $this->post($url.'/retry', $input)->assertStatus(419);
        $this->withSession(['_token' => 'batch-csrf'])->withHeader('X-CSRF-TOKEN', 'batch-csrf');
        $this->post($url.'/retry', $input)->assertForbidden();
        Gate::define('operateCurrent', fn ($user) => true);
        $this->get($url)->assertOk()->assertSee('Retry shown failures (2)');
        $this->post('/current/jobs/'.$jobs[0]->id.'/retry')->assertRedirect();
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->assertSame(4, $this->store()->table('jobs')->count());
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->post('/current/jobs/'.$jobs[1]->id.'/retry')->assertRedirect();
        $this->assertSame(4, $this->store()->table('jobs')->count());
        $this->work(2);
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertSame(2, array_sum(Redis::hgetall('batch-success')));
        $this->get($url)->assertOk()->assertDontSee('Retry shown failures');
    }

    public function test_cross_batch_selection_is_rejected_before_any_enqueue_and_stale_payload_is_not_retried(): void
    {
        $first = $this->batch('first', 1); $second = $this->batch('second', 1); $this->work(2);
        Gate::define('operateCurrent', fn ($user) => true);
        $this->withSession(['_token' => 'batch-csrf'])->withHeader('X-CSRF-TOKEN', 'batch-csrf');
        $jobs = $this->store()->table('jobs')->get();
        $this->post('/current/batches/'.$first->id.'/retry', ['jobs' => $jobs->pluck('id')->all()])->assertConflict();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $job = $jobs->firstWhere('batch_id', $first->id);
        $failed = app('queue.failer')->find($job->external_id); $payload = json_decode($failed->payload, true);
        $payload['_current_dispatch_id'] = (string) \Illuminate\Support\Str::ulid();
        app('db')->connection('app')->table('failed_jobs')->where('uuid', $job->external_id)->update(['payload' => json_encode($payload)]);
        $this->post('/current/batches/'.$first->id.'/retry', ['jobs' => [$job->id]])->assertRedirect();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$job->id)->exists());
        Gate::define('viewCurrent', fn ($user) => false);
        $this->get('/current/batches/'.$second->id)->assertForbidden();
    }

    private function operator(): void
    {
        Gate::define('operateCurrent', fn ($user) => true);
        $this->withSession(['_token' => 'batch-csrf'])->withHeader('X-CSRF-TOKEN', 'batch-csrf');
    }

    public function test_full_batch_retry_resumes_beyond_fifty_and_uses_failed_payloads_without_current_history(): void
    {
        $batch = $this->batch('full-batch', 53); $this->work(53);
        $this->operator();
        // Expire and prune observations while retaining Laravel's batch and failures.
        $this->store()->table('jobs')->update(['expires_at' => time() - 1]);
        \Illuminate\Support\Facades\Artisan::call('current:prune');
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $url = '/current/batches/'.$batch->id;
        $view = $this->get($url)->assertOk()->assertSee('Retry batch failures')->assertSee('No retained jobs');
        $input = ['scope' => 'batch', 'retry_pass' => $view->viewData('retryPass')];
        $response = $this->postJson($url.'/retry', $input)->assertOk()->assertJsonPath('inspected', 50)
            ->assertJsonPath('submitted', 50)->assertJsonPath('unconfirmed', 0)->assertJsonPath('complete', false)
            ->assertJsonPath('next.retry_pass', $input['retry_pass']);
        $this->assertSame(50, $this->store()->table('jobs')->count());
        $this->assertSame(3, app('db')->connection('app')->table('failed_jobs')->count());
        $this->post($url.'/retry', $input)->assertRedirect();
        $this->assertSame(50, $this->store()->table('jobs')->count());
        $next = $this->get($response->json('resume_url'))->assertOk()->assertSee('Continue batch retry');
        $final = $this->postJson($url.'/retry', $input + ['retry_after' => $next->viewData('retryAfter')])->assertOk()
            ->assertJsonPath('inspected', 3)->assertJsonPath('submitted', 3)->assertJsonPath('unconfirmed', 0)
            ->assertJsonPath('complete', true)->assertJsonPath('next', null);
        $this->assertStringContainsString('retry_pass=', $final->json('resume_url'));
        $this->get($final->json('resume_url'))->assertOk()->assertViewHas('retryPass', $input['retry_pass']);
        $this->assertSame(53, $this->store()->table('native_retry_claims')->count());
        $this->assertSame(53, $this->store()->table('jobs')->count());
        $this->assertSame(0, app('db')->connection('app')->table('failed_jobs')->count());
        $this->work(53);
        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertSame(53, array_sum(Redis::hgetall('batch-success')));
    }

    public function test_full_batch_pass_replay_excludes_new_failures_and_validates_authorization_and_scope(): void
    {
        $batch = $this->batch('pass-replay', 1); $this->work();
        $other = $this->batch('other-pass', 1); $this->work();
        $url = '/current/batches/'.$batch->id;
        $pass = $this->get($url)->viewData('retryPass');
        $input = ['scope' => 'batch', 'retry_pass' => $pass];
        $this->post($url.'/retry', $input)->assertStatus(419);
        $this->withSession(['_token' => 'batch-csrf'])->withHeader('X-CSRF-TOKEN', 'batch-csrf');
        $this->post($url.'/retry', $input)->assertForbidden();
        $this->operator();
        $this->post('/current/batches/'.$other->id.'/retry', $input)->assertUnprocessable();
        $this->post($url.'/retry', ['scope' => 'batch', 'retry_pass' => 'forged'])->assertUnprocessable();
        $this->getJson($url.'?retry_after[]=bad')->assertUnprocessable();
        $this->post($url.'/retry', $input)->assertRedirect($url);
        Redis::del('batch-first:pass-replay-1');
        $this->work(); // A new failed dispatch with the same Laravel UUID.
        $this->assertSame(3, $this->store()->table('jobs')->count());
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->assertSame(3, $this->store()->table('jobs')->count());
        config()->set('current.allow_retries', false);
        $this->post($url.'/retry', $input)->assertForbidden();
    }

    public function test_missing_history_retry_refuses_wrong_executable_membership_identity_and_connection(): void
    {
        $batch = $this->batch('bad-membership', 4); $this->work(4);
        $this->operator();
        $jobs = $this->store()->table('jobs')->get();
        foreach ($jobs as $index => $job) {
            $failed = app('queue.failer')->find($job->external_id); $payload = json_decode($failed->payload, true);
            if ($index === 0) { $payload['data']['batchId'] = 'other-batch'; }
            if ($index === 1) { $payload['uuid'] = 'wrong-uuid'; }
            if ($index === 2) {
                // Matching JSON metadata cannot override the executable membership.
                $payload['data']['command'] = str_replace($batch->id, str_repeat('x', strlen($batch->id)), $payload['data']['command']);
            }
            $updates = ['payload' => json_encode($payload)];
            if ($index === 3) { $updates['connection'] = 'unconfigured-connection'; }
            app('db')->connection('app')->table('failed_jobs')->where('uuid', $job->external_id)->update($updates);
        }
        $this->store()->table('jobs')->update(['expires_at' => time() - 1]);
        \Illuminate\Support\Facades\Artisan::call('current:prune');
        $url = '/current/batches/'.$batch->id;
        $pass = $this->get($url)->viewData('retryPass');
        $this->post($url.'/retry', ['scope' => 'batch', 'retry_pass' => $pass])->assertRedirect($url)
            ->assertSessionHas('current_batch_notice', fn ($notice) => str_contains($notice, '4 unavailable'));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertSame(0, $this->store()->table('native_retry_claims')->count());
        $this->assertSame(4, app('db')->connection('app')->table('failed_jobs')->count());
    }

    public function test_concurrent_batch_and_individual_retry_share_claim_and_block_forgetting(): void
    {
        $batch = $this->batch('concurrent-batch', 1); $this->work(); $this->operator();
        $job = $this->store()->table('jobs')->sole();
        $pass = $this->get('/current/batches/'.$batch->id)->viewData('retryPass');
        $cutoff = app(\Current\Native\BatchRetryPass::class)->cutoff($pass, $batch->id);
        $checked = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobRetryRequested::class, function () use ($batch, $job, $cutoff, &$checked) {
            $candidate = app(\Current\Native\Retry::class)->batchJob($batch->id, $job->external_id, $cutoff);
            $this->assertSame('requested', app(\Current\RetrySubmission::class)->submit($candidate)['state']);
            try {
                app(\Current\Native\Maintenance::class)->forget($job->id, true);
                $this->fail('Forget must refuse a retry with an unknown enqueue outcome.');
            } catch (\Current\Native\MaintenanceException $error) {
                $this->assertStringContainsString('unconfirmed', $error->getMessage());
            }
            $checked = true;
        });
        $this->post('/current/jobs/'.$job->id.'/retry')->assertRedirect();
        $this->assertTrue($checked);
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(1, $this->store()->table('native_retry_claims')->count());
        $this->assertSame(0, $this->store()->table('job_actions')->count());
    }

    public function test_batch_retry_lost_ack_is_durable_and_forget_markers_survive_missing_history(): void
    {
        $batch = $this->batch('lost-ack', 2); $this->work(2); $this->operator();
        $jobs = $this->store()->table('jobs')->get();
        app(\Current\Native\Maintenance::class)->forget($jobs[0]->id, true);
        $lost = false;
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Database\Events\QueryExecuted::class, function ($event) use (&$lost) {
            if (! $lost && $event->connectionName === 'app' && str_starts_with(strtolower($event->sql), 'delete from "failed_jobs"')) {
                $lost = true;
                throw new \RuntimeException('Synthetic acknowledgement lost after enqueue and failed-payload deletion.');
            }
        });
        $url = '/current/batches/'.$batch->id; $pass = $this->get($url)->viewData('retryPass');
        $input = ['scope' => 'batch', 'retry_pass' => $pass];
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->assertTrue($lost);
        $this->assertSame(2, $this->store()->table('jobs')->count()); // forgotten original absent; one retry enqueued
        $this->assertSame('requested', json_decode($this->store()->table('native_retry_claims')->sole()->value, true)['state']);
        $this->post($url.'/retry', $input)->assertRedirect($url);
        $this->post('/current/jobs/'.$jobs[1]->id.'/retry')->assertRedirect();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count()); // forgotten payload retained
        $this->store()->table('native_retry_claims')->update(['expires_at' => time() - 1]);
        \Illuminate\Support\Facades\Artisan::call('current:prune');
        $this->assertSame(0, $this->store()->table('native_retry_claims')->count());
    }

    public function test_retry_refuses_payload_replacement_after_claim_without_executing_the_replacement(): void
    {
        $batch = $this->batch('replacement-race', 1); $this->work(); $this->operator();
        $job = $this->store()->table('jobs')->sole();
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobRetryRequested::class, function () use ($job) {
            $failed = app('queue.failer')->find($job->external_id);
            $payload = json_decode($failed->payload, true);
            // Keep the UUID and dispatch marker while replacing executable bytes.
            $payload['data']['command'] = str_replace('replacement-race-1', 'replacement-race-2', $payload['data']['command']);
            app('db')->connection('app')->table('failed_jobs')->where('uuid', $job->external_id)->update(['payload' => json_encode($payload)]);
        });
        $this->post('/current/jobs/'.$job->id.'/retry')->assertRedirect();
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame('requested', json_decode($this->store()->table('native_retry_claims')->sole()->value, true)['state']);
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
    }

    public function test_retained_retry_adapter_preserves_laravel_attempt_reset_and_retry_until_refresh(): void
    {
        $batch = Bus::batch([new RetryUntilBatchFixture('laravel-retry-policy')])->allowFailures()
            ->onConnection('redis')->onQueue('lens-demo')->dispatch();
        $this->work(); $this->operator();
        $job = $this->store()->table('jobs')->sole();
        $failed = app('queue.failer')->find($job->external_id); $payload = json_decode($failed->payload, true);
        $payload['retryUntil'] = 1; $payload['attempts'] = 9;
        app('db')->connection('app')->table('failed_jobs')->where('uuid', $job->external_id)->update(['payload' => json_encode($payload)]);
        $url = '/current/batches/'.$batch->id;
        $pass = $this->get($url)->viewData('retryPass');
        $this->post($url.'/retry', ['scope' => 'batch', 'retry_pass' => $pass])->assertRedirect();
        $queued = json_decode(Redis::lindex('queues:lens-demo', 0), true);
        $this->assertSame(0, $queued['attempts']);
        $this->assertGreaterThan(time() + 60, $queued['retryUntil']);
        $this->assertNotSame($job->dispatch_id, $queued['_current_dispatch_id']);
        $this->work();
        $this->assertSame([], $batch->fresh()->failedJobIds);
    }

    public function test_encrypted_batch_retry_authenticates_membership_without_current_history_or_plaintext_snapshots(): void
    {
        $secret = 'synthetic-encrypted-membership-value';
        $batch = Bus::batch([new EncryptedRetryBatchFixture($secret)])->allowFailures()
            ->onConnection('redis')->onQueue('lens-demo')->dispatch();
        $this->work(); $this->operator();
        $original = $this->store()->table('jobs')->sole();
        $failed = app('queue.failer')->find($original->external_id);
        $this->assertStringNotContainsString($secret, $failed->payload);
        $this->assertFalse(str_starts_with(json_decode($failed->payload, true)['data']['command'], 'O:'));
        $this->store()->table('jobs')->update(['expires_at' => time() - 1]);
        \Illuminate\Support\Facades\Artisan::call('current:prune');
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $url = '/current/batches/'.$batch->id;
        $pass = $this->get($url)->viewData('retryPass');
        $this->postJson($url.'/retry', ['scope' => 'batch', 'retry_pass' => $pass])->assertOk()
            ->assertJsonPath('submitted', 1)->assertJsonPath('unconfirmed', 0)->assertJsonPath('complete', true);
        $retry = $this->store()->table('jobs')->sole();
        $this->assertNull($retry->arguments_json);
        $this->assertStringNotContainsString($secret, json_encode($retry));
        $this->assertStringNotContainsString($secret, json_encode($this->store()->table('native_retry_claims')->get()));
        $this->assertNull(app('queue.failer')->find($original->external_id));
        $this->work();
        $this->assertSame([], $batch->fresh()->failedJobIds);
        $this->assertSame('1', Redis::hget('batch-success', $secret));
    }

    public function test_encrypted_membership_refuses_bad_authentication_large_plaintext_and_object_envelopes_without_wakeup(): void
    {
        $batch = $this->batch('encrypted-refusals', 4); $this->work(4); $this->operator();
        $encrypter = app(\Illuminate\Contracts\Encryption\Encrypter::class);
        $otherKey = new \Illuminate\Encryption\Encrypter(random_bytes(32), 'AES-256-CBC');
        $wrappedObject = new EncryptedMembershipWakeupFixture('never-wake-during-inspection');
        $wrappedObject->batchId = $batch->id;
        EncryptedMembershipWakeupFixture::$wakeups = 0;
        foreach ($this->store()->table('jobs')->get() as $index => $job) {
            $failed = app('queue.failer')->find($job->external_id); $payload = json_decode($failed->payload, true);
            if ($index === 0) {
                $cipher = json_decode(base64_decode($encrypter->encrypt($payload['data']['command'])), true);
                $cipher['mac'] = str_repeat('0', 64);
                $payload['data']['command'] = base64_encode(json_encode($cipher));
            } elseif ($index === 1) { $payload['data']['command'] = $otherKey->encrypt($payload['data']['command']); }
            elseif ($index === 2) { $payload['data']['command'] = $encrypter->encrypt(str_repeat('x', 262145)); }
            else { $payload['data']['command'] = $encrypter->encrypt($wrappedObject); }
            app('db')->connection('app')->table('failed_jobs')->where('uuid', $job->external_id)->update(['payload' => json_encode($payload)]);
        }
        $this->store()->table('jobs')->update(['expires_at' => time() - 1]);
        \Illuminate\Support\Facades\Artisan::call('current:prune');
        $url = '/current/batches/'.$batch->id;
        $pass = $this->get($url)->viewData('retryPass');
        $this->postJson($url.'/retry', ['scope' => 'batch', 'retry_pass' => $pass])->assertOk()
            ->assertJsonPath('submitted', 0)->assertJsonPath('unconfirmed', 4);
        $this->assertSame(0, EncryptedMembershipWakeupFixture::$wakeups);
        $this->assertSame(0, $this->store()->table('native_retry_claims')->count());
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertSame(4, app('db')->connection('app')->table('failed_jobs')->count());
    }
}

class RetryBatchFixture implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable, \Illuminate\Bus\Batchable;
    public int $tries = 1;
    public function __construct(public string $label) {}
    public function handle(): void
    {
        if (Redis::setnx('batch-first:'.$this->label, 1)) { throw new \RuntimeException('Synthetic first-dispatch failure'); }
        Redis::hincrby('batch-success', $this->label, 1);
    }
}

class RetryUntilBatchFixture extends RetryBatchFixture
{
    public function retryUntil(): \DateTimeInterface { return now()->addMinutes(2); }
    public function handle(): void
    {
        if (Redis::setnx('batch-first:'.$this->label, 1)) { $this->fail(new \RuntimeException('Synthetic explicit terminal failure')); return; }
        Redis::hincrby('batch-success', $this->label, 1);
    }
}

class EncryptedRetryBatchFixture extends RetryBatchFixture implements \Illuminate\Contracts\Queue\ShouldBeEncrypted {}

class EncryptedMembershipWakeupFixture extends RetryBatchFixture
{
    public static int $wakeups = 0;
    public function __wakeup(): void { self::$wakeups++; }
}
