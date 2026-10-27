<?php

namespace Current\Tests;

use Current\Native\Plan;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use InvalidArgumentException;

final class NativeWorkerOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', ['state_directory' => '/tmp/current-test-plan', 'instance' => 'test', 'grace_seconds' => 10,
            'pools' => ['payments' => ['connection' => 'redis', 'queues' => ['lens-demo'], 'timeout' => 2, 'tries' => 0, 'backoff' => [2, 4]]]]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Exercise the exact options passed to Laravel by the native launcher. */
    private function workerArguments(): array
    {
        $command = app(Plan::class)->runtime()['pools'][0]['command'];
        $arguments = ['connection' => $command[3]];
        foreach (array_slice($command, 4) as $option) {
            $parts = explode('=', $option, 2);
            $arguments[$parts[0]] = $parts[1] ?? true;
        }
        return $arguments;
    }

    public function test_retry_schedule_repeats_last_delay_and_zero_tries_keeps_retrying(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        dispatch(new NativeRetryPolicyJob);
        $arguments = $this->workerArguments();
        $this->assertSame('payments', $arguments['--name']);
        foreach ([2, 4, 4] as $delay) {
            $this->assertSame(0, Artisan::call('queue:work', $arguments + ['--once' => true]));
            $delayed = Redis::zrange('queues:lens-demo:delayed', 0, -1, ['withscores' => true]);
            $this->assertCount(1, $delayed);
            $this->assertSame(Carbon::now()->timestamp + $delay, (int) array_values($delayed)[0]);
            $this->assertSame(0, app('db')->connection('app')->table('failed_jobs')->count());
            Carbon::setTestNow(Carbon::now()->addSeconds($delay + 1));
        }
        $this->assertSame(0, Artisan::call('queue:work', $arguments + ['--once' => true]));
        $this->assertSame('4', Redis::get('native-policy-attempts'));
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $this->assertSame(4, $this->store()->table('attempts')->count());
    }

    public function test_job_retry_policy_takes_precedence_over_pool_policy(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $job = new NativeRetryPolicyJob;
        $job->tries = 2;
        $job->backoff = [7];
        dispatch($job);
        $arguments = $this->workerArguments() + ['--once' => true];
        Artisan::call('queue:work', $arguments);
        $delayed = Redis::zrange('queues:lens-demo:delayed', 0, -1, ['withscores' => true]);
        $this->assertCount(1, $delayed);
        $this->assertSame(Carbon::now()->timestamp + 7, (int) array_values($delayed)[0]);
        Carbon::setTestNow(Carbon::now()->addSeconds(8));
        Artisan::call('queue:work', $arguments);
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
        $this->assertSame(0, (int) Redis::zcard('queues:lens-demo:delayed'));
        $this->assertSame('failed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_maintenance_is_respected_unless_force_is_explicitly_enabled(): void
    {
        $maintenance = \Mockery::mock(MaintenanceMode::class);
        $maintenance->shouldReceive('active')->andReturn(true);
        app()->instance(MaintenanceMode::class, $maintenance);
        dispatch(new NativeRetryPolicyJob(failures: 0));
        config()->set('current.native.pools.payments.max_time', 1);
        config()->set('current.native.pools.payments.max_jobs', 1);
        $this->assertArrayNotHasKey('--force', $this->workerArguments());
        $this->assertSame(0, Artisan::call('queue:work', $this->workerArguments()));
        $this->assertNull(Redis::get('native-policy-attempts'));
        config()->set('current.native.pools.payments.force', true);
        $this->assertSame(0, Artisan::call('queue:work', $this->workerArguments()));
        $this->assertSame('1', Redis::get('native-policy-attempts'));
    }

    public function test_worker_niceness_changes_only_the_child_priority(): void
    {
        if (! function_exists('pcntl_getpriority')) { $this->markTestSkipped('Requires Linux PCNTL priority support.'); }
        config()->set('current.native.pools.payments.nice', 5);
        $command = app(Plan::class)->runtime()['pools'][0]['command'];
        $this->assertSame(['-n', '5'], array_slice($command, 1, 2));
        $before = pcntl_getpriority();
        $process = new \Symfony\Component\Process\Process(array_merge(array_slice($command, 0, 3),
            [PHP_BINARY, '-r', 'echo pcntl_getpriority();']));
        $process->mustRun();
        $this->assertSame(min(19, $before + 5), (int) $process->getOutput());
        $this->assertSame($before, pcntl_getpriority());
        config()->set('current.native.pools.payments.nice', 20);
        $this->expectException(InvalidArgumentException::class);
        app(Plan::class)->runtime();
    }

    public function test_invalid_retry_and_force_configuration_is_rejected_before_launch(): void
    {
        foreach (['backoff' => [[], [-1], [86401], ['2'], [1 => 2], array_fill(0, 101, 1), '2,4'],
            'force' => ['false', 1], 'tries' => [-1]] as $key => $invalidValues) {
            foreach ($invalidValues as $invalid) {
                config()->set('current.native.pools.payments.'.$key, $invalid);
                try {
                    app(Plan::class)->runtime();
                    $this->fail('Invalid '.$key.' was accepted.');
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString($key, strtolower($e->getMessage()));
                }
            }
            config()->set('current.native.pools.payments.'.$key, ['backoff' => 0, 'force' => false, 'tries' => 3][$key]);
        }
    }
}

class NativeRetryPolicyJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Queue\Queueable;

    public ?int $tries = null;
    public ?array $backoff = null;

    public function __construct(public int $failures = 3) {}

    public function handle(): void
    {
        if ((int) Redis::incr('native-policy-attempts') <= $this->failures) {
            throw new \RuntimeException('Controlled retry policy failure');
        }
    }
}
