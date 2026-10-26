<?php

namespace Current\Tests;

use App\Jobs\WarmVehicleBasicCache;
use App\Services\VinLookupService;
use Current\Current;
use Current\Prototype\Jobs\PaymentJob;
use Current\Recorder;
use Illuminate\Auth\GenericUser;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Horizon\Events\JobPushed;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\Jobs\RetryFailedJob;
use Mockery;
use PHPUnit\Framework\Attributes\Group;

final class IntegrationTest extends TestCase
{
    public function test_real_horizon_jobs_capture_completed_steps_and_safe_metadata(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $job = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $job->status);
        $this->assertSame(1, $job->attempt_count);
        $this->assertSame(4, $this->store()->table('steps')->where('status', 'completed')->count());
        $this->assertSame(2, $this->store()->table('tags')->count());
        $this->assertFalse($this->store()->table('tags')->where('tag', 'like', 'private:%')->exists());
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee('Validate order')->assertSee('POSSIBLE OPERATIONS', false);
    }

    public function test_late_enqueue_observation_reuses_an_already_completed_worker_cycle(): void
    {
        $pushed = null;
        $queued = null;
        $events = app('events');
        $events->forget(JobPushed::class);
        $events->listen(JobPushed::class, function ($event) use (&$pushed) {
            $pushed = $event;
        });
        $events->listen(JobQueued::class, function ($event) use (&$queued) {
            $queued = $event;
        });

        dispatch(new PaymentJob('success'));
        $this->work();
        $before = $this->store()->table('jobs')->first();
        $this->assertSame('start_only', $before->coverage);
        $this->assertSame('completed', $before->status);

        app(Recorder::class)->pushed($pushed);
        app(Recorder::class)->queued($queued);

        $after = $this->store()->table('jobs')->sole();
        $this->assertSame($before->id, $after->id);
        $this->assertSame('observed_enqueue', $after->coverage);
        $this->assertSame('completed', $after->status);
        $this->assertSame(1, $after->attempt_count);
        $this->assertSame('captured', $after->arguments_state);
        $this->assertSame(4, $this->store()->table('steps')->count());
        $this->assertSame($after->id, $this->store()->table('attempts')->sole()->job_id);
    }

    public function test_failure_highlights_step_and_sanitizes_before_storage(): void
    {
        dispatch(new PaymentJob('failure'));
        $this->work(3);
        $job = $this->store()->table('jobs')->first();
        $this->assertSame('failed', $job->status);
        $attempt = $this->store()->table('attempts')->orderByDesc('started_at')->first();
        $this->assertStringContainsString('[REDACTED]', $attempt->exception_message);
        $this->assertStringNotContainsString('demo-secret', json_encode($this->store()->table('attempts')->get()));
        $this->assertStringNotContainsString('demo-secret', json_encode($this->store()->table('steps')->get()));
        $this->assertStringNotContainsString('args', $attempt->exception_trace);
        $this->get('/current/jobs/'.$job->id)->assertOk()->assertSee('Jump to failed step')->assertSee('Exception location')->assertDontSee('demo-secret');
    }

    public function test_automatic_retry_keeps_distinct_executions_and_error_history(): void
    {
        dispatch(new PaymentJob('retry'));
        $this->work(2);
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame('completed', $this->store()->table('jobs')->first()->status);
        $attempts = $this->store()->table('attempts')->orderBy('started_at')->get();
        $this->assertSame(['released', 'completed'], $attempts->pluck('status')->all());
        $this->assertNotSame($attempts[0]->id, $attempts[1]->id);
        $this->assertNotNull($attempts[0]->exception_class);
        $this->assertNull($attempts[1]->exception_class);
        $response = $this->get('/current/jobs/'.$attempts[0]->job_id)->assertOk()->assertSee('Attempt 1 · Retried');
        $this->assertSame('failed', $response->viewData('attempts')->last()->display_status);
    }

    public function test_explicit_release_is_not_marked_completed(): void
    {
        dispatch(new PaymentJob('release'));
        $this->work();
        $this->assertSame('pending', $this->store()->table('jobs')->first()->status);
        $this->assertSame('released', $this->store()->table('attempts')->first()->status);
        $response = $this->get('/current/jobs/'.$this->store()->table('jobs')->first()->id)->assertOk()->assertSee('Attempt 1 · Released');
        $this->assertSame('released', $response->viewData('attempts')->first()->display_status);
        $this->work();
        $this->assertSame('completed', $this->store()->table('jobs')->first()->status);
    }

    public function test_horizon_retry_creates_explicit_new_cycle_and_parent_reference(): void
    {
        dispatch(new PaymentJob('failure'));
        $this->work(3);
        $original = $this->store()->table('jobs')->first();
        app()->call([new RetryFailedJob($original->external_id), 'handle']);
        $retry = $this->store()->table('jobs')->where('id', '!=', $original->id)->first();
        $this->assertNotNull($retry);
        $this->assertNotSame($original->external_id, $retry->external_id);
        $this->assertSame($original->external_id, $retry->retry_of);
    }

    public function test_artisan_retry_reusing_uuid_and_attempt_one_never_overwrites_history(): void
    {
        dispatch(new PaymentJob('failure'));
        $this->work(3);
        $original = $this->store()->table('jobs')->first();
        $this->artisan('queue:retry', ['id' => [$original->external_id]])->assertSuccessful();
        $this->work();
        $jobs = $this->store()->table('jobs')->orderBy('first_observed_at')->get();
        $this->assertCount(2, $jobs);
        $this->assertSame($jobs[0]->external_id, $jobs[1]->external_id);
        $this->assertSame(4, $this->store()->table('attempts')->count());
        $this->assertSame(2, $this->store()->table('attempts')->where('reported_attempt', 1)->count());
    }

    #[Group('vehify')]
    public function test_vehify_actual_job_executes_without_modification_or_external_calls(): void
    {
        $lookup = Mockery::mock(VinLookupService::class);
        $lookup->shouldReceive('basic')->once()->with('SYNTHETIC-LENS')->andReturnNull();
        $this->app->instance(VinLookupService::class, $lookup);
        $job = new WarmVehicleBasicCache('SYNTHETIC-LENS');
        $job->onQueue('lens-demo');
        Queue::push($job);
        $this->work();
        $row = $this->store()->table('jobs')->first();
        $this->assertSame(WarmVehicleBasicCache::class, $row->job_class);
        $this->assertSame('completed', $row->status);
        $this->assertSame(0, $this->store()->table('steps')->count());
        $outline = $this->store()->table('attempts')->first();
        $this->assertNotNull($outline->source_hash);
        $this->get('/current/jobs/'.$row->id)->assertOk()->assertDontSee('No internal steps captured')->assertDontSee('Execution map')->assertDontSee('aria-label="Execution steps"', false)->assertDontSee('Open in Horizon')->assertSee('$lookup-&gt;basic()', false);
        Http::assertNothingSent();
    }

    public function test_redelivery_completion_keeps_earlier_missing_finish_separate_from_outcome(): void
    {
        dispatch(new PaymentJob('success'));
        $reserved = Queue::connection('redis')->pop('lens-demo');
        app('events')->dispatch(new JobProcessing('redis', $reserved));
        $first = $this->store()->table('attempts')->first();
        $this->store()->table('attempts')->where('id', $first->id)->update(['started_at' => microtime(true) - 600]);
        $this->get('/current/monitoring?status=processing&history=unfinished')->assertOk()->assertSee('PaymentJob')->assertSee('Finish not observed');
        // Redis reservation genuinely expires; another worker execution sees the same UUID.
        sleep(6);
        $this->work();
        $this->assertSame(2, $this->store()->table('attempts')->count());
        $this->assertNull($this->store()->table('attempts')->where('id', $first->id)->first()->finished_at);
        $record = $this->store()->table('jobs')->first();
        $this->assertSame('completed', $record->status);
        $response = $this->get('/current/jobs/completed?history=unfinished')->assertOk()->assertSee('PaymentJob')->assertSee('Earlier attempt unfinished');
        $this->assertSame('completed', $response->viewData('jobs')->first()->display_status);
        $this->assertSame(1, (int) $response->viewData('jobs')->first()->earlier_unfinished_attempts);
        $this->get('/current/jobs/'.$record->id)->assertOk()->assertSee('Earlier attempt unfinished');
    }

    public function test_recorder_database_failure_does_not_fail_the_job_or_skip_callback(): void
    {
        Schema::connection('lens')->drop('lens_steps');
        dispatch(new PaymentJob('success'));
        $this->work();
        $this->assertSame(0, DB::connection('app')->table('failed_jobs')->count());
        $this->assertFileExists(config('current.diagnostic_path'));
        $called = 0;
        $this->assertSame(42, Current::step('Recorder down', function () use (&$called) {
            $called++;

            return 42;
        }));
        $this->assertSame(1, $called);
    }

    public function test_combined_filters_search_past_first_page_and_restore_query_state(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->store()->newJob('uuid-'.$i, 'redis', $i === 0 ? 'needle' : 'other', ['data' => ['commandName' => 'ExampleJob'], 'tags' => ['order:needle']]);
        }
        $this->get('/current/monitoring?queue=needle&status=pending&tags=order%3Aneedle&job_class=ExampleJob')
            ->assertOk()->assertSee('uuid-0')->assertDontSee('uuid-24')->assertSee('order:needle');
        $this->get('/current/monitoring')->assertOk()->assertSee('Next');
    }

    public function test_guest_and_non_admin_are_denied_including_local_environment(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/current')->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->get('/current')->assertForbidden();
        $this->get('/current/assets/lens.css')->assertForbidden();
        Horizon::auth(fn () => true);
        $this->get('/current')->assertForbidden();
    }

    public function test_retention_hides_and_deletes_expired_jobs_with_all_children(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $id = $this->store()->table('jobs')->first()->id;
        $this->store()->table('jobs')->update(['expires_at' => microtime(true) - 1]);
        $this->get('/current/jobs/'.$id)->assertNotFound();
        $this->artisan('lens:prune')->assertSuccessful();
        foreach (['jobs', 'attempts', 'steps', 'tags'] as $table) {
            $this->assertSame(0, $this->store()->table($table)->count());
        }
    }

    public function test_unconfigured_message_capture_omits_secret_and_outside_root_paths(): void
    {
        config()->set('current.message_sanitizer', null);
        config()->set('current.source_roots', []);
        dispatch(new PaymentJob('failure'));
        $this->work();
        $attempt = $this->store()->table('attempts')->first();
        $this->assertNull($attempt->exception_message);
        $this->assertNull($attempt->exception_file);
        $this->assertStringNotContainsString('/workspace', $attempt->exception_trace);
    }

    public function test_time_bounds_and_all_selected_tags_apply_before_pagination(): void
    {
        $first = $this->store()->newJob('both-tags', 'redis', 'payments', ['data' => ['commandName' => 'ExampleJob'], 'tags' => ['order:one', 'scenario:success']]);
        $second = $this->store()->newJob('one-tag-only', 'redis', 'payments', ['data' => ['commandName' => 'ExampleJob'], 'tags' => ['order:one']]);
        $this->store()->table('jobs')->where('id', $first)->update(['observed_at' => strtotime('2026-10-25 12:00:00 UTC')]);
        $this->store()->table('jobs')->where('id', $second)->update(['observed_at' => strtotime('2026-10-25 13:00:00 UTC')]);
        $this->get('/current/monitoring?tags=order%3Aone%2Cscenario%3Asuccess')->assertOk()->assertSee('both-tags')->assertDontSee('one-tag-only');
        $this->get('/current/monitoring?to=2026-10-25T13%3A00')->assertOk()->assertSee('both-tags')->assertDontSee('one-tag-only');
        $this->get('/current/monitoring?from=2026-10-25T13%3A00')->assertOk()->assertDontSee('both-tags')->assertSee('one-tag-only');
    }

    public function test_cursor_pagination_has_no_duplicates_and_keeps_filter_state(): void
    {
        for ($i = 0; $i < 45; $i++) {
            $this->store()->newJob('page-'.$i, 'redis', 'pagination', ['data' => ['commandName' => 'ExampleJob']]);
        }
        $page = $this->get('/current/monitoring?queue=pagination')->assertOk();
        $jobs = $page->viewData('jobs');
        $firstIds = $jobs->pluck('id')->all();
        $next = $this->get($jobs->nextPageUrl())->assertOk();
        $this->assertCount(20, $next->viewData('jobs'));
        $this->assertSame([], array_intersect($firstIds, $next->viewData('jobs')->pluck('id')->all()));
        $this->assertStringContainsString('queue=pagination', $next->viewData('jobs')->nextPageUrl());
    }

    public function test_silenced_completion_capture_and_filters_search_beyond_first_page(): void
    {
        config()->set('horizon.silenced', [PaymentJob::class]);
        dispatch(new PaymentJob('success'));
        $this->work();
        $job = $this->store()->table('jobs')->first();
        $this->assertSame(1, $job->silenced);
        $this->get('/current/jobs/completed')->assertDontSee($job->external_id);
        for ($i = 0; $i < 55; $i++) {
            $id = $this->store()->newJob('quiet-'.$i, 'redis', 'quiet', ['silenced' => true, 'data' => ['commandName' => 'QuietJob'], 'tags' => ['order:quiet']]);
            $this->store()->table('jobs')->where('id', $id)->update(['status' => 'completed']);
        }
        $response = $this->get('/current/silenced?'.http_build_query([
            'job_id' => $job->external_id, 'queue' => $job->queue, 'job_class' => $job->job_class, 'tags' => 'scenario:success',
        ]))->assertOk()->assertSee($job->external_id)->assertDontSee('quiet-');
        $this->assertSame(1, $response->viewData('total'));
        $unknown = $this->store()->newJob('unknown-classification', 'redis', 'quiet', ['data' => ['commandName' => 'QuietJob']]);
        $this->store()->table('jobs')->where('id', $unknown)->update(['status' => 'completed']);
        $this->get('/current/silenced')->assertDontSee('unknown-classification')->assertDontSee('Earlier records have unknown classification');
    }

    public function test_scoped_time_filter_accepts_seconds_and_preserves_exclusive_upper_bound(): void
    {
        foreach (['inside' => '12:00:30', 'edge' => '12:05:30'] as $name => $time) {
            $id = $this->store()->newJob($name, 'redis', 'payments', ['data' => ['commandName' => 'ExampleJob']]);
            $this->store()->table('jobs')->where('id', $id)->update(['status' => 'failed', 'observed_at' => strtotime('2026-10-25 '.$time.' UTC')]);
        }
        $response = $this->get('/current/jobs/failed?'.http_build_query([
            'from' => '2026-10-25T12:00:30', 'to' => '2026-10-25T12:05:30', 'queue' => 'payments',
        ]))->assertOk()->assertSee('inside')->assertDontSee('>edge<', false);
        $this->assertSame(1, $response->viewData('total'));
        $this->getJson('/current/jobs/failed?from=2026-10-25T13:00:00&to=2026-10-25T12:00:00')->assertUnprocessable();
    }

    public function test_private_job_detail_requires_authorization_and_mutating_route_is_absent(): void
    {
        $id = $this->store()->newJob('protected', 'redis', 'private', ['data' => ['commandName' => 'PrivateJob']]);
        $this->app['auth']->forgetGuards();
        $this->get('/current/jobs/'.$id)->assertForbidden();
        $this->post('/current/jobs/'.$id)->assertStatus(405);
    }
}
