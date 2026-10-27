<?php

namespace Current\Tests;

use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

final class NativeLifecycleParityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $this->assertInstanceOf(\Current\Native\RedisQueue::class, app('queue')->connection('redis'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unique_dispatch_is_suppressed_until_the_original_completes(): void
    {
        dispatch(new NativeUniqueJob);
        dispatch(new NativeUniqueJob);
        $this->assertSame(1, (int) Redis::llen('queues:lens-demo'));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->work();
        dispatch(new NativeUniqueJob);
        $this->work();
        $this->assertSame('2', Redis::get('native-unique-handled'));
        $this->assertSame(2, $this->store()->table('jobs')->where('status', 'completed')->count());
    }

    public function test_encrypted_payload_still_executes_without_exporting_its_serialized_command(): void
    {
        dispatch(new NativeEncryptedJob);
        $payload = json_decode(Redis::lindex('queues:lens-demo', 0), true);
        $this->assertStringNotContainsString('native-encryption-marker', $payload['data']['command']);
        $this->work();
        $this->assertSame('native-encryption-marker', Redis::get('native-encrypted-handled'));
        $job = $this->store()->table('jobs')->sole();
        $this->assertSame('completed', $job->status);
        $this->assertStringNotContainsString($payload['data']['command'], json_encode($job));
    }

    public function test_overlap_release_does_not_run_the_handler_or_invent_completion(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        $job = new NativeOverlapJob;
        $middleware = $job->middleware()[0];
        $lock = Cache::lock($middleware->getLockKey($job), 60);
        $this->assertTrue($lock->get());
        try {
            dispatch($job);
            $this->work();
            $this->assertNull(Redis::get('native-overlap-handled'));
            $this->assertSame('released', $this->store()->table('attempts')->sole()->status);
            $this->assertSame('pending', $this->store()->table('jobs')->sole()->status);
        } finally {
            $lock->release();
        }
        Carbon::setTestNow(Carbon::now()->addSeconds(4));
        $this->work();
        $this->assertSame('1', Redis::get('native-overlap-handled'));
        $this->assertSame('completed', $this->store()->table('jobs')->sole()->status);
        $this->assertSame(2, $this->store()->table('attempts')->count());
    }

    public function test_expired_retry_deadline_fails_without_running_the_handler(): void
    {
        Carbon::setTestNow(Carbon::now()->startOfSecond());
        dispatch(new NativeDeadlineJob(Carbon::now()->addSeconds(2)->timestamp));
        Carbon::setTestNow(Carbon::now()->addSeconds(3));
        $this->work();
        $this->assertNull(Redis::get('native-deadline-handled'));
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
        $this->assertSame('failed', $this->store()->table('jobs')->sole()->status);
    }

    public function test_max_exceptions_still_limits_a_worker_with_unlimited_tries(): void
    {
        dispatch(new NativeExceptionLimitJob);
        foreach ([1, 2] as $attempt) {
            $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'lens-demo', '--once' => true, '--tries' => 0]));
            $this->assertSame((string) $attempt, Redis::get('native-exceptions-handled'));
        }
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
        $this->assertSame('failed', $this->store()->table('jobs')->sole()->status);
        $this->assertSame(0, app('queue')->connection('redis')->size('lens-demo'));
    }
}

class NativeUniqueJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;
    public function uniqueId(): string { return 'native-unique'; }
    public function handle(): void { Redis::incr('native-unique-handled'); }
}

class NativeEncryptedJob implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable;
    public string $marker = 'native-encryption-marker';
    public function handle(): void { Redis::set('native-encrypted-handled', $this->marker); }
}

class NativeOverlapJob implements ShouldQueue
{
    use Queueable;
    public function middleware(): array { return [(new WithoutOverlapping('native-overlap'))->releaseAfter(3)]; }
    public function handle(): void { Redis::incr('native-overlap-handled'); }
}

class NativeDeadlineJob implements ShouldQueue
{
    use Queueable;
    public function __construct(public int $deadline) {}
    public function retryUntil(): int { return $this->deadline; }
    public function handle(): void { Redis::incr('native-deadline-handled'); }
}

class NativeExceptionLimitJob implements ShouldQueue
{
    use Queueable;
    public int $maxExceptions = 2;
    public function handle(): void
    {
        Redis::incr('native-exceptions-handled');
        throw new \RuntimeException('Controlled exception limit');
    }
}
