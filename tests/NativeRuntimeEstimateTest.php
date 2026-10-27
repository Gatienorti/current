<?php

namespace Current\Tests;

use Current\Native\Autoscaler;
use Current\Native\Plan;
use Current\Native\RuntimeEstimate;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

final class NativeRuntimeEstimateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
    }

    public function test_work_estimate_is_weighted_by_queue_and_excludes_unobserved_work(): void
    {
        dispatch(new PaymentJob('success'));
        dispatch((new PaymentJob('success'))->onQueue('other'));
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'lens-demo,other', '--stop-when-empty' => true, '--max-jobs' => 2]));
        $end = microtime(true) - 1;
        foreach (['lens-demo' => 2, 'other' => 4] as $queue => $seconds) {
            $id = $this->store()->table('jobs')->where('queue', $queue)->value('id');
            $this->store()->table('attempts')->where('job_id', $id)->update(['started_at' => $end - $seconds, 'finished_at' => $end]);
        }
        $routes = ['redis' => ['lens-demo', 'other']];
        $snapshot = app(\Current\Native\RuntimeSnapshot::class);
        $snapshot->publish($snapshot->sample($routes), $routes);
        $this->store()->connection()->beforeExecuting(fn () => throw new RuntimeException('History SQL must stay off runtime reads.'));
        $estimate = app(RuntimeEstimate::class);
        $this->assertEqualsWithDelta(22.0, $estimate->workSeconds('redis', ['lens-demo' => 5, 'other' => 3, 'empty-unobserved' => 0]), 0.001);
        $this->assertSame(0.0, $estimate->workSeconds('redis', ['unknown' => 0]));
        $this->expectException(RuntimeException::class);
        $estimate->workSeconds('another-connection', ['lens-demo' => 1]);
    }

    public function test_missing_stale_incomplete_and_expired_samples_do_not_become_zero_runtime(): void
    {
        dispatch(new PaymentJob('success'));
        $this->work();
        $job = $this->store()->table('jobs')->sole();
        $attempt = $this->store()->table('attempts')->sole();
        $end = microtime(true) - 1;
        foreach (['missing-finish', 'stale', 'reversed-clock', 'expired', 'processing'] as $case) {
            $this->store()->table('jobs')->where('id', $job->id)->update(['expires_at' => $case === 'expired' ? $end - 1 : $end + 3600]);
            $this->store()->table('attempts')->where('id', $attempt->id)->update([
                'started_at' => $case === 'stale' ? $end - 400 : $end - 2,
                'finished_at' => match ($case) { 'missing-finish' => null, 'stale' => $end - 399, 'reversed-clock' => $end - 3, default => $end },
                'status' => $case === 'processing' ? 'processing' : 'completed',
            ]);
            $snapshot = app(\Current\Native\RuntimeSnapshot::class);
            $routes = ['redis' => ['lens-demo']];
            $snapshot->publish($snapshot->sample($routes), $routes);
            try {
                app(RuntimeEstimate::class)->workSeconds('redis', ['lens-demo' => 5]);
                $this->fail('Accepted '.$case.' runtime.');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('unavailable', $e->getMessage());
            }
        }
    }

    public function test_time_policy_uses_clearance_target_with_worker_ranges_and_shift_limits(): void
    {
        config()->set('current.native', ['state_directory' => '/tmp/current-test-plan', 'instance' => 'test', 'grace_seconds' => 10,
            'pools' => ['imports' => ['connection' => 'redis', 'queues' => ['lens-demo'], 'timeout' => 2, 'workers' => 2,
                'min_workers' => 1, 'max_workers' => 8, 'balance' => 'time', 'target_clear_seconds' => 10, 'balance_shift' => 2]]]);
        $pool = app(Plan::class)->pools()[0];
        $this->assertSame(3, Autoscaler::target(25, 2, $pool));
        $this->assertSame(4, Autoscaler::target(1000, 2, $pool));
        $this->assertSame(1, Autoscaler::target(0, 2, $pool));
        $this->assertSame(8, Autoscaler::target(1000, 8, $pool));
        config()->set('current.native.pools.imports.target_clear_seconds', 0);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
    }
}
