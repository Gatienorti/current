<?php

namespace Current\Tests;

use Current\Native\BulkForget;
use Current\Native\ClearQueue;
use Current\Prototype\Jobs\PaymentJob;
use Current\Recorder;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

final class NativeBulkMaintenanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
    }

    private function failures(int $count): void
    {
        $jobs = [];
        for ($i = 0; $i < $count; $i++) {
            $job = new PaymentJob('failure');
            $job->tries = 1;
            $jobs[] = $job;
        }
        Queue::bulk($jobs, '', 'lens-demo');
        $this->work($count);
    }

    public function test_large_clear_snapshots_chunks_resumes_sql_failure_and_preserves_later_work_and_reservation(): void
    {
        dispatch(new PaymentJob('success'));
        $reserved = Queue::connection()->pop('lens-demo');
        $jobs = [];
        for ($i = 0; $i < 1250; $i++) {
            $job = new PaymentJob('success');
            if ($i % 2) {
                $job->delay(3600);
            }$jobs[] = $job;
        }
        foreach (array_chunk($jobs, 400) as $chunk) {
            Queue::bulk($chunk, '', 'lens-demo');
        }$op = (string) Str::ulid();
        $this->assertSame(1251, $this->store()->table('jobs')->count());
        $first = app(ClearQueue::class)->run('redis', 'lens-demo', $op, false, false, 1);
        $this->assertFalse($first['complete']);
        $this->assertSame(1250, $first['removed']);
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
        $this->assertSame(1, Redis::zcard('queues:lens-demo:reserved'));
        dispatch(new PaymentJob('success'));
        dispatch((new PaymentJob('success'))->delay(3600));
        $this->store()->connection()->statement("CREATE TRIGGER clear_chunk_failure BEFORE INSERT ON lens_job_actions BEGIN SELECT RAISE(ABORT, 'fixture'); END");
        try {
            app(ClearQueue::class)->run('redis', 'lens-demo', $op, true);
            $this->fail('SQL failure ignored');
        } catch (QueryException) {
        }
        $this->store()->connection()->statement('DROP TRIGGER clear_chunk_failure');
        $done = app(ClearQueue::class)->run('redis', 'lens-demo', $op, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(1250, $done['processed']);
        $this->assertSame(1, Redis::llen('queues:lens-demo'));
        $this->assertSame(1, Redis::zcard('queues:lens-demo:delayed'));
        $this->assertSame(1, Redis::zcard('queues:lens-demo:reserved'));
        $this->assertSame(1250, $this->store()->table('jobs')->whereNotNull('cleared_at')->count());
        $this->assertSame($done, app(ClearQueue::class)->run('redis', 'lens-demo', $op, true));
        $reserved->delete();
    }

    public function test_horizon_clear_mode_removes_reserved_snapshot_without_relabelling_a_running_attempt(): void
    {
        dispatch(new PaymentJob('success'));
        $reserved = Queue::connection()->pop('lens-demo');
        event(new JobProcessing('redis', $reserved));
        dispatch(new PaymentJob('success'));
        $op = (string) Str::ulid();
        $record = app(ClearQueue::class)->run('redis', 'lens-demo', $op, false, true);
        $this->assertSame(1, $record['removed_reserved']);
        $this->assertSame(1, $record['removed_waiting']);
        $this->assertSame(0, Redis::zcard('queues:lens-demo:reserved'));
        $active = $this->store()->jobsWithObservationState()->where('external_id', $reserved->uuid())->first();
        $this->assertNull($active->cleared_at);
        $this->assertSame(1, (int) $active->unfinished_attempts);
        $this->assertSame(1, $this->store()->table('jobs')->whereNotNull('cleared_at')->count());
    }

    public function test_clear_reservation_scan_interruption_or_membership_change_keeps_history_outcome_unknown(): void
    {
        $reserved = [];
        for ($i = 0; $i < 125; $i++) {
            dispatch(new PaymentJob('success'));
            $reserved[] = Queue::connection()->pop('lens-demo');
        }
        dispatch(new PaymentJob('success'));
        $op = (string) Str::ulid();
        $first = app(ClearQueue::class)->run('redis', 'lens-demo', $op, false, false, 1);
        $this->assertFalse($first['complete']);
        $this->assertSame('reservations', $first['phase']);
        $reserved[0]->delete();
        dispatch(new PaymentJob('success'));
        $new = Queue::connection()->pop('lens-demo');
        $done = app(ClearQueue::class)->run('redis', 'lens-demo', $op, true);
        $this->assertTrue($done['complete']);
        $this->assertTrue($done['unknown_reserved']);
        $this->assertSame(125, Redis::zcard('queues:lens-demo:reserved'));
        $this->assertSame(0, $this->store()->table('jobs')->whereNotNull('cleared_at')->count());
        $new->delete();
        foreach ($reserved as $job) {
            $job->delete();
        }
    }

    public function test_clear_reconciles_old_version_receipt_and_uninspectable_unicode_never_blocks_progress(): void
    {
        dispatch(new PaymentJob('success'));
        $payload = json_decode(Redis::lpop('queues:lens-demo'), true);
        $op = (string) Str::ulid();
        $key = 'current:clear:'.hash('sha256', 'redis|lens-demo').':'.$op;
        $legacy = ['version' => 1, 'operation' => $op, 'connection' => 'redis', 'queue' => 'lens-demo', 'observed_at' => microtime(true), 'removed' => 1, 'identities' => [['uuid' => $payload['uuid'], 'dispatch' => $payload['_current_dispatch_id']]]];
        Redis::set($key, json_encode($legacy));
        dispatch(new PaymentJob('success'));
        $done = app(ClearQueue::class)->run('redis', 'lens-demo', $op, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(1, $done['processed']);
        $this->assertSame(1, Redis::llen('queues:lens-demo'));
        $this->assertSame(1, $this->store()->table('jobs')->whereNotNull('cleared_at')->count());
        Redis::rpush('queues:lens-demo', '{"uuid":"'.chr(255).'"}');
        $cleared = app(ClearQueue::class)->run('redis', 'lens-demo', (string) Str::ulid(), false);
        $this->assertTrue($cleared['complete']);
        $this->assertSame(2, $cleared['removed']);
        $this->assertSame(1, $cleared['untracked']);
        $this->assertSame(0, Redis::llen('queues:lens-demo'));
    }

    public function test_bulk_forget_resumes_more_than_one_page_and_excludes_later_failures(): void
    {
        $this->failures(205);
        $op = (string) Str::ulid();
        $first = app(BulkForget::class)->run($op, false, false, 1);
        $this->assertFalse($first['complete']);
        $this->assertSame(100, $first['histories']);
        $this->assertSame(205, app('db')->connection('app')->table('failed_jobs')->count());
        $this->failures(1);
        $new = $this->store()->table('jobs')->orderByDesc('id')->first();
        $done = app(BulkForget::class)->run($op, true);
        $this->assertTrue($done['complete']);
        $this->assertSame(205, $done['histories']);
        $this->assertSame(205, $done['payloads']);
        $this->assertSame(0, $done['blocked']);
        $this->assertSame($new->id, $this->store()->table('jobs')->sole()->id);
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
        $this->assertSame($done, app(BulkForget::class)->run($op, true));
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
    }

    public function test_bulk_forget_retains_unknown_retry_and_expired_resume_cannot_delete_new_failures(): void
    {
        $this->failures(1);
        $job = $this->store()->table('jobs')->sole();
        $this->store()->table('meta')->insert(['key' => 'retry:'.$job->id, 'value' => '{"state":"requested"}']);
        $op = (string) Str::ulid();
        $state = app(BulkForget::class)->run($op, false);
        $this->assertTrue($state['complete']);
        $this->assertGreaterThan(0, $state['blocked']);
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
        $this->store()->table('maintenance_operations')->where('id', $op)->update(['expires_at' => time() - 1]);
        $this->assertSame(1, Artisan::call('current:forget', ['--all' => true, '--resume' => $op]));
        $this->assertSame(1, $this->store()->table('jobs')->count());
        $this->assertSame(1, app('db')->connection('app')->table('failed_jobs')->count());
    }

    public function test_legacy_failed_payload_can_be_forgotten_by_laravel_id_without_history_and_cannot_reappear(): void
    {
        $this->failures(1);
        $job = $this->store()->table('jobs')->sole();
        $failed = app('queue.failer')->find($job->external_id);
        $payload = json_decode($failed->payload, true);
        unset($payload['_current_dispatch_id']);
        $this->store()->table('jobs')->update(['dispatch_id' => null]);
        app('db')->connection('app')->table('failed_jobs')->update(['payload' => json_encode($payload)]);
        $this->assertSame(0, Artisan::call('current:forget', ['id' => $job->external_id]));
        $this->assertSame(0, $this->store()->table('jobs')->count());
        app(Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->assertSame(0, $this->store()->table('jobs')->count());
        $payload['_current_dispatch_id'] = (string) Str::ulid();
        app(Recorder::class)->enqueued($payload, 'redis', 'lens-demo');
        $this->assertSame(1,$this->store()->table('jobs')->count());
    }
}
