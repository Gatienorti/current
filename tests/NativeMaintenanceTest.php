<?php

namespace Current\Tests;

use Current\Native\ClearQueue;
use Current\Native\Maintenance;
use Current\Native\Metrics;
use Current\Prototype\Jobs\PaymentJob;
use Current\Recorder;
use Current\RetrySubmission;
use Current\Support\DispatchIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class NativeMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        Gate::define('viewCurrent', fn () => true);
    }

    public function test_action_identity_uses_portable_byte_framing(): void
    {
        $this->assertSame('6eca7adf5be2d236ae132ba172cf6b9039f69c2fa7bd21045d45ac517dba0d6e', DispatchIdentity::fingerprint('u/é', 'redis', '01AAAAAAAAAAAAAAAAAAAAAAAA'));
    }

    public function test_clear_waiting_and_delayed_entries_preserves_reservations_and_unrelated_queues(): void
    {
        dispatch((new PaymentJob('success'))->onQueue('other'));
        dispatch(new PaymentJob('success'));
        $reserved = Queue::connection()->pop('lens-demo');
        dispatch(new PaymentJob('success'));
        dispatch((new PaymentJob('success'))->delay(3600));
        $operation = (string) Str::ulid();
        $record = app(ClearQueue::class)->run('redis', 'lens-demo', $operation, false);
        $this->assertSame(2, $record['removed']);
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        $this->assertSame(0, Redis::zcard('queues:lens-demo:delayed'));
        $this->assertSame(1, Redis::zcard('queues:lens-demo:reserved'));
        $this->assertSame(1, Redis::llen('queues:other'));
        $this->assertSame(2, $this->store()->jobsWithObservationState()->whereRaw($this->store()->outcomeSql()." = 'cleared'")->count());
        $this->assertSame(4, $this->store()->table('jobs')->count());
        $this->get('/current/monitoring?status=cleared')->assertOk()->assertSee('Cleared');
        $reserved->delete();
        // New work is not removed by reconciling this operation a second time.
        dispatch(new PaymentJob('success'));
        $again = app(ClearQueue::class)->run('redis', 'lens-demo', $operation, true);
        $this->assertSame($record, $again);
        $this->assertSame(1, Redis::llen('queues:lens-demo'));
        $this->work();
        $this->assertSame(1, $this->store()->table('jobs')->where('status', 'completed')->count());
    }

    public function test_clear_sql_failure_can_be_resumed_without_a_second_redis_clear(): void
    {
        dispatch(new PaymentJob('success'));
        $operation = (string) Str::ulid();
        $this->store()->connection()->statement("CREATE TRIGGER maintenance_failure BEFORE INSERT ON lens_job_actions BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        try {
            app(ClearQueue::class)->run('redis', 'lens-demo', $operation, false);
            $this->fail('SQL failure ignored');
        } catch (QueryException) {
            $this->assertSame(0, Redis::llen('queues:lens-demo'));
        }
        $this->store()->connection()->statement('DROP TRIGGER maintenance_failure');
        dispatch(new PaymentJob('success'));
        $record = app(ClearQueue::class)->run('redis', 'lens-demo', $operation, true);
        $this->assertSame(1, $record['removed']);
        $this->assertSame(1, Redis::llen('queues:lens-demo'));
        $this->assertSame(1, $this->store()->table('jobs')->whereNotNull('cleared_at')->count());
    }

    public function test_clear_removes_uninspectable_payloads_without_inventing_history_and_has_no_total_entry_ceiling(): void
    {
        Redis::rpush('queues:lens-demo', '{not-json');
        $this->assertSame(0, Artisan::call('current:clear', ['connection' => 'redis', '--queue' => 'lens-demo']));
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        Redis::del('queues:lens-demo');
        Redis::rpush('queues:lens-demo', ...array_fill(0, 1001, '{}'));
        $this->assertSame(0, Artisan::call('current:clear', ['connection' => 'redis', '--queue' => 'lens-demo']));
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        $this->assertSame(1, Artisan::call('current:clear', ['connection' => 'redis', '--resume' => (string) Str::ulid()]));
        config()->set('current.execution_backend', 'horizon');
        $this->assertSame(1, Artisan::call('current:clear', ['connection' => 'redis', '--queue' => 'lens-demo']));
    }

    public function test_old_or_unconfirmed_collector_ownership_blocks_maintenance_before_clear(): void
    {
        dispatch(new PaymentJob('success'));
        $original = config('database.connections.lens.database');
        $this->store()->connection()->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        app('db')->purge('lens');
        $local = tempnam(sys_get_temp_dir(), 'current-maintenance-');
        copy($original, $local);
        config()->set('database.connections.lens.database', $local);
        $path = $local.'.collector.lock';
        $file = fopen($path, 'c+');
        $this->assertIsResource($file);
        $this->assertTrue(flock($file, LOCK_EX | LOCK_NB));
        try {
            $this->assertSame(1, Artisan::call('current:clear', ['connection' => 'redis', '--queue' => 'lens-demo']));
            $this->assertSame(1, Redis::llen('queues:lens-demo'));
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
            unlink($path);
            app('db')->purge('lens');
            config()->set('database.connections.lens.database', $original);
            foreach ([$local, $local.'-wal', $local.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    public function test_forget_removes_only_the_exact_failed_dispatch_and_prevents_sync_recreation(): void
    {
        $failed = new PaymentJob('failure');
        $failed->tries = 1;
        dispatch($failed);
        $this->work();
        $job = $this->store()->table('jobs')->sole();
        $payload = json_decode(app('queue.failer')->find($job->external_id)->payload, true);
        $this->assertSame(0, Artisan::call('current:forget', ['id' => $job->id]));
        $this->assertNull(app('queue.failer')->find($job->external_id));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->assertSame(0, $this->store()->table('attempts')->count());
        $this->assertSame(0, $this->store()->table('steps')->count());
        $this->assertSame(0, $this->store()->table('tags')->count());
        app(Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $this->get('/current/jobs/'.$job->id)->assertNotFound();
        // A fresh native retry generation with the same UUID remains observable.
        $payload['_current_dispatch_id'] = (string) Str::ulid();
        app(Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->assertSame(1, $this->store()->table('jobs')->count());
    }

    public function test_forget_respects_retry_claims_replacement_payloads_and_history_only_scope(): void
    {
        $failed = new PaymentJob('failure');
        $failed->tries = 1;
        dispatch($failed);
        $this->work();
        $original = $this->store()->table('jobs')->sole();
        $this->store()->table('meta')->insert(['key' => 'retry:'.$original->id, 'value' => '{"state":"requested"}']);
        $this->assertSame(1, Artisan::call('current:forget', ['id' => $original->id]));
        $this->assertSame(0, $this->store()->table('job_actions')->count());
        $this->store()->table('meta')->where('key', 'retry:'.$original->id)->delete();
        app(RetrySubmission::class)->submit($original);
        $this->work();
        $new = $this->store()->table('jobs')->where('id', '!=', $original->id)->sole();
        $this->assertSame(1, Artisan::call('current:forget', ['id' => $original->id]));
        $this->assertNotNull(app('queue.failer')->find($new->external_id));
        $this->assertSame(0, Artisan::call('current:forget', ['id' => $original->id, '--history-only' => true]));
        $this->assertSame($new->id, $this->store()->table('jobs')->sole()->id);
        $this->assertNotNull(app('queue.failer')->find($new->external_id));
    }

    public function test_forget_failure_after_payload_deletion_reports_partial_state_and_can_reconcile(): void
    {
        $failed = new PaymentJob('failure');
        $failed->tries = 1;
        dispatch($failed);
        $this->work();
        $job = $this->store()->table('jobs')->sole();
        $this->store()->connection()->statement("CREATE TRIGGER forget_failure BEFORE DELETE ON lens_jobs BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        $this->assertSame(1, Artisan::call('current:forget', ['id' => $job->id]));
        $this->assertStringContainsString('may have succeeded', Artisan::output());
        $this->assertNull(app('queue.failer')->find($job->external_id));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(0, $this->store()->table('job_actions')->count());
        $this->store()->connection()->statement('DROP TRIGGER forget_failure');
        $this->assertSame(0, Artisan::call('current:forget', ['id' => $job->id, '--history-only' => true]));
        $this->assertSame(0, $this->store()->table('jobs')->count());
    }

    public function test_expired_removal_markers_and_retry_claims_are_pruned_together(): void
    {
        $failed = new PaymentJob('failure');
        $failed->tries = 1;
        dispatch($failed);
        $this->work();
        $job = $this->store()->table('jobs')->sole();
        app(Maintenance::class)->forget($job->id, false);
        $this->store()->table('job_actions')->update(['expires_at' => time() - 1]);
        $this->store()->table('job_actions')->insert(['identity_hash' => str_repeat('f', 64), 'kind' => 'cleared', 'observed_at' => time(), 'expires_at' => time() + 1000]);
        $this->assertSame(0, Artisan::call('current:prune'));
        $this->assertFalse($this->store()->table('meta')->where('key', 'retry:'.$job->id)->exists());
        $this->assertSame('cleared', $this->store()->table('job_actions')->sole()->kind);
    }

    public function test_clear_never_hides_a_later_release_or_completed_attempt(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $job = $this->store()->table('jobs')->sole();
        $cleared = microtime(true);
        $this->store()->table('jobs')->where('id', $job->id)->update(['cleared_at' => $cleared]);
        $this->store()->table('attempts')->where('job_id', $job->id)->update(['status' => 'released', 'finished_at' => $cleared - 1]);
        $this->assertSame('cleared', $this->store()->jobsWithObservationState()->sole()->display_status);
        $this->store()->table('attempts')->where('job_id', $job->id)->update(['finished_at' => $cleared + 1]);
        $this->assertSame('pending', $this->store()->jobsWithObservationState()->sole()->display_status);
        $this->store()->table('attempts')->where('job_id', $job->id)->update(['status' => 'completed']);
        $this->assertSame('completed', $this->store()->jobsWithObservationState()->sole()->display_status);
    }

    public function test_metric_reset_does_not_rebuild_pre_reset_history_and_keeps_job_observations(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $metrics = app(Metrics::class);
        $end = intdiv(time(), 300) * 300;
        $this->store()->table('attempts')->update(['started_at' => $end - 21, 'finished_at' => $end - 20]);
        $metrics->snapshot($end + 1);
        $metrics->reset($end - 15);
        $this->assertSame(0, $this->store()->table('native_metrics')->count());
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(0, $metrics->snapshot($end + 2));
        $series = $metrics->series('jobs', [PaymentJob::class], 1, $end + 2);
        $this->assertTrue($series[11]['partial']);
        $this->assertSame(0, $series[11]['completed']);
        $this->assertFalse($series[10]['available']);
        $this->store()->table('attempts')->update(['finished_at' => $end - 10]);
        $metrics->snapshot($end + 3);
        $series = $metrics->series('jobs', [PaymentJob::class], 1, $end + 3);
        $this->assertSame(1, $series[11]['completed']);
        $this->assertSame(0, Artisan::call('current:clear-metrics'));
        $this->get('/current/metrics/jobs')->assertOk()->assertSee('No captured completions since the metric reset')->assertDontSee('class="text-link" href="'.route('current.metrics.jobs.history', ['job_class' => PaymentJob::class]).'"', false);
    }
}
