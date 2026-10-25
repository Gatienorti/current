<?php

namespace HorizonLens\Tests;

use HorizonLens\HorizonRetry;
use HorizonLens\Prototype\Jobs\PaymentJob;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Mockery;

final class RetryActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withSession(['_token' => 'current-test-csrf']);
        $this->withHeader('X-CSRF-TOKEN', 'current-test-csrf');
    }

    private function failedJob(): object
    {
        $job = new PaymentJob('failure');
        $job->tries = 1;
        dispatch($job);
        $this->work();

        return $this->store()->table('jobs')->first();
    }

    public function test_retry_uses_horizon_lineage_and_duplicate_posts_open_the_same_new_job(): void
    {
        $original = $this->failedJob();
        $this->get('/horizon-lens/jobs/'.$original->id)->assertOk()->assertSee('Retry job');
        $url = '/horizon-lens/jobs/'.$original->id.'/retry';
        $response = $this->post($url)->assertRedirect();
        $retry = $this->store()->table('jobs')->where('id', '!=', $original->id)->first();
        $this->assertNotNull($retry);
        $this->assertSame($original->external_id, $retry->retry_of);
        $this->assertNotSame($original->external_id, $retry->external_id);
        $response->assertRedirect(route('horizon-lens.show', ['id' => $retry->id, 'watch' => 1]).'#execution-map');
        $this->post($url)->assertRedirect($response->headers->get('Location'));
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->get('/horizon-lens/jobs/'.$retry->id.'?watch=1')->assertOk()->assertSee('data-refresh-ms="1000"', false);
        $this->work();
        $this->get('/horizon-lens/jobs/'.$retry->id.'?watch=1')->assertOk()->assertSee('data-refresh-ms="5000"', false)->assertSee('Follow live');
        $this->get('/horizon-lens/jobs/'.$original->id)->assertSee('View retry')->assertDontSee('>Retry job<', false);
        $this->store()->table('jobs')->where('id', $original->id)->update(['expires_at' => time() - 1]);
        $this->store()->prune();
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$original->id)->exists());
    }

    public function test_retry_context_links_both_ways_and_preserves_snapshot_provenance(): void
    {
        $original = $this->failedJob();
        $this->store()->table('jobs')->where('id', $original->id)->update([
            'arguments_json' => '{"order":"safe-order","token":"[REDACTED]"}', 'arguments_state' => 'captured',
            'metadata_json' => '{"origin":"enqueue"}', 'metadata_state' => 'captured',
        ]);
        // Native Horizon retry, outside Current's POST/claim mechanism.
        $uuid = app(HorizonRetry::class)->retry($original->external_id);
        $retry = $this->store()->findCycle($uuid, $original->connection);
        $this->get('/horizon-lens/jobs/'.$retry->id)->assertOk()->assertSee('Earlier enqueue')
            ->assertSee('safe-order')->assertSee('[REDACTED]')->assertSee('not a fresh capture')
            ->assertSee(route('horizon-lens.show', ['id' => $original->id]), false);
        $this->get('/horizon-lens/jobs/'.$original->id)->assertOk()->assertSee('View retry '.$uuid);
        $this->assertNull($this->store()->findCycle($uuid, $original->connection)->arguments_json);
        $this->work();
        $nextUuid = app(HorizonRetry::class)->retry($uuid);
        $next = $this->store()->findCycle($nextUuid, $original->connection);
        $this->get('/horizon-lens/jobs/'.$next->id)->assertOk()->assertSee('safe-order')->assertSee('Earlier enqueue');
        $this->store()->table('jobs')->where('id', $next->id)->update(['arguments_json' => '{"own":"fresh"}', 'arguments_state' => 'captured']);
        $this->get('/horizon-lens/jobs/'.$next->id)->assertSee('fresh')->assertDontSee('safe-order');
        $this->store()->table('jobs')->where('id', $original->id)->update(['expires_at' => time() - 1]);
        $this->get('/horizon-lens/jobs/'.$retry->id)->assertOk()->assertDontSee('safe-order')->assertSee('Original observation unavailable or ambiguous');
    }

    public function test_ambiguous_or_mismatched_parent_never_supplies_context(): void
    {
        $original = $this->failedJob();
        $this->store()->table('jobs')->where('id', $original->id)->update(['arguments_json' => '{"private":"parent-context"}', 'arguments_state' => 'captured']);
        $uuid = app(HorizonRetry::class)->retry($original->external_id);
        $retry = $this->store()->findCycle($uuid, $original->connection);
        $copy = (array) $this->store()->table('jobs')->where('id', $original->id)->first();
        $copy['id'] = (string) Str::ulid();
        $this->store()->table('jobs')->insert($copy);
        $this->get('/horizon-lens/jobs/'.$retry->id)->assertOk()->assertDontSee('parent-context')->assertSee('Original observation unavailable or ambiguous');
        $this->store()->table('jobs')->where('id', $copy['id'])->delete();
        $this->store()->table('jobs')->where('id', $original->id)->update(['connection' => 'other']);
        $this->get('/horizon-lens/jobs/'.$retry->id)->assertOk()->assertDontSee('parent-context');
        $this->store()->table('jobs')->where('id', $original->id)->update(['connection' => $original->connection]);
        $this->store()->table('jobs')->where('id', $retry->id)->update(['arguments_state' => 'unavailable']);
        $this->get('/horizon-lens/jobs/'.$retry->id)->assertOk()->assertDontSee('parent-context');
    }

    public function test_authorization_csrf_and_disabled_actions_are_enforced(): void
    {
        $job = $this->failedJob();
        $url = '/horizon-lens/jobs/'.$job->id.'/retry';
        $this->actingAs(new GenericUser(['id' => 2, 'is_admin' => false]));
        $this->post($url)->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 1, 'is_admin' => true]));
        config()->set('horizon-lens.allow_retries', false);
        $this->post($url)->assertForbidden();
        config()->set('horizon-lens.allow_retries', true);
        $this->flushHeaders(); // Exercise the real missing-token path.
        $this->post($url)->assertStatus(419);
        $this->assertSame(1, $this->store()->table('jobs')->count());
    }

    public function test_nonfailed_and_expired_horizon_payloads_cannot_be_retried(): void
    {
        dispatch(new PaymentJob('success'));
        $pending = $this->store()->table('jobs')->first();
        $this->post('/horizon-lens/jobs/'.$pending->id.'/retry')->assertStatus(409);
        $this->work();
        $this->post('/horizon-lens/jobs/'.$pending->id.'/retry')->assertStatus(409);
        $this->store()->table('jobs')->delete();
        $failed = $this->failedJob();
        app(JobRepository::class)->deleteFailed($failed->external_id);
        $this->post('/horizon-lens/jobs/'.$failed->id.'/retry')->assertStatus(409);
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$failed->id)->exists());
    }

    public function test_ambiguous_enqueue_failure_never_automatically_resends(): void
    {
        $job = $this->failedJob();
        $real = app(Dispatcher::class);
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatchSync')->once()->andReturnUsing(function ($command) use ($real) {
            $real->dispatchSync($command);
            throw new \RuntimeException('private transport error');
        });
        $this->app->instance(Dispatcher::class, $dispatcher);
        $url = '/horizon-lens/jobs/'.$job->id.'/retry';
        $this->post($url)->assertRedirect(route('horizon-lens.show', ['id' => $job->id]));
        $this->post($url)->assertRedirect();
        $this->assertSame(2, $this->store()->table('jobs')->count());
        $this->get('/horizon-lens/jobs/'.$job->id)->assertSee('Retry requested')->assertDontSee('private transport error');
    }
}
