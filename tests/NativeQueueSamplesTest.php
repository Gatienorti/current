<?php

namespace Current\Tests;

use Current\Native\Metrics;
use Current\Native\QueueSamples;
use Current\Native\Registry;
use Current\Native\RuntimeSnapshot;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;

final class NativeQueueSamplesTest extends TestCase
{
    private function pool(string $name, array $queues, int $workers): array
    {
        return ['name' => $name, 'connection' => 'redis', 'queues' => $queues, 'workers' => $workers, 'min' => 1, 'max' => 10, 'running' => $workers, 'state' => 'running'];
    }

    public function test_priority_and_overlapping_pools_count_real_capacity_without_inventing_runtime(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        Redis::rpush('queues:one', ...array_fill(0, 12, 'fixture'));
        Redis::rpush('queues:two', 'fixture');
        $snapshot = app(RuntimeSnapshot::class);
        $snapshot->publish(['observed_at' => microtime(true), 'rows' => [
            ['connection' => 'redis', 'queue' => 'one', 'runtime' => 2.0],
            ['connection' => 'redis', 'queue' => 'two', 'runtime' => null],
        ]], ['redis' => ['one', 'two']]);
        $registry = ['available' => true, 'invalid' => 0, 'instances' => [['state' => 'running', 'pools' => [
            $this->pool('priority', ['one', 'two'], 2), $this->pool('dedicated', ['one'], 2),
        ]]]];
        $this->assertSame(['one' => 12], app(\Current\Native\QueueLoad::class)->counts('redis', ['one']));
        $rows = app(QueueSamples::class)->observe(['available' => false], $registry);
        $this->assertSame(4, $rows[0]['workers']);
        $this->assertSame(12, $rows[0]['ready']);
        $this->assertSame(6.0, $rows[0]['wait_seconds']);
        $this->assertSame(2, $rows[1]['workers']);
        $this->assertNull($rows[1]['wait_seconds']);
        $registry['instances'][0]['state'] = 'stale';
        $unknown = app(QueueSamples::class)->observe(['available' => false], $registry);
        $this->assertNull($unknown[0]['workers']);
        $this->assertNull($unknown[0]['wait_seconds']);
        Redis::del('queues:one');
        $registry['instances'][0]['state'] = 'paused';
        $paused = app(QueueSamples::class)->observe(['available' => false], $registry);
        $this->assertSame(0, $paused[0]['workers']);
        $this->assertSame(0.0, $paused[0]['wait_seconds']);
        $this->assertNull($paused[1]['wait_seconds']);
    }

    public function test_snapshot_persists_only_current_wait_point_and_reset_pruning_and_routes_keep_gaps(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        app(Registry::class)->publish(['instance' => 'queue-sample', 'owner' => str_repeat('b', 32), 'state' => 'running',
            'updated_at' => gmdate('c'), 'pools' => [$this->pool('orders', ['sample'], 2)]], 'sample-host');
        $now = time(); $metrics = app(Metrics::class);
        $metrics->snapshot($now);
        $waits = app(QueueSamples::class)->series(['redis', 'sample'], 1, $now, 0);
        $this->assertCount(1, $waits);
        $this->assertSame(intdiv($now, 300) * 300, $waits[0]['at']);
        $this->assertSame(0.0, $waits[0]['wait']);
        $this->assertSame(2, $waits[0]['workers']);
        $metrics->snapshot($now);
        $this->assertSame(1, $this->store()->table('native_queue_samples')->count());
        $this->store()->table('native_queue_samples')->update(['observed_at' => $now + 1, 'wait_seconds' => 99]);
        $metrics->snapshot($now);
        $this->assertSame(99.0, app(QueueSamples::class)->series(['redis', 'sample'], 1, $now, 0)[0]['wait']);
        Gate::define('viewCurrent', fn () => true);
        $this->get('/current/metrics/queues/history?connection=redis&queue=sample')->assertOk()->assertSee('Queue wait data');
        $metrics->reset($now + 1);
        $this->assertSame([], app(QueueSamples::class)->series(['redis', 'sample'], 1, $now, $metrics->resetAt()));
        $metrics->snapshot($now); // An older observation cannot cross reset.
        $this->assertSame(0, $this->store()->table('native_queue_samples')->count());
        $metrics->snapshot($now + 2);
        $this->store()->table('native_queue_samples')->update(['expires_at' => $now - 1]);
        $this->assertSame([], app(QueueSamples::class)->series(['redis', 'sample'], 1, $now, 0));
        $this->assertSame(0, Artisan::call('current:prune'));
        $this->assertSame(0, $this->store()->table('native_queue_samples')->count());
        Gate::define('viewCurrent', fn () => false);
        $this->get('/current/metrics/queues/history?connection=redis&queue=sample')->assertForbidden();
    }

    public function test_attempt_storage_failure_does_not_break_available_wait_history(): void
    {
        config()->set('current.execution_backend', 'native');
        Gate::define('viewCurrent', fn () => true);
        $now = time();
        $this->store()->table('native_queue_samples')->insert(['metric_key' => Metrics::key('queues', ['redis', 'sample']),
            'bucket' => intdiv($now, 300) * 300, 'ready' => 2, 'workers' => 1, 'wait_seconds' => 3,
            'observed_at' => $now, 'expires_at' => $now + 3600]);
        $this->store()->connection()->getSchemaBuilder()->drop('lens_native_metrics');
        $this->get('/current/metrics/queues/history?connection=redis&queue=sample')->assertOk()
            ->assertSee('Metric history unavailable')->assertSee('Queue wait data')->assertDontSee('Queue wait history unavailable');
    }

    public function test_wait_storage_failure_is_unknown_and_keeps_attempt_chart_available(): void
    {
        config()->set('current.execution_backend', 'native');
        Gate::define('viewCurrent', fn () => true);
        app(Metrics::class)->snapshot();
        $this->store()->connection()->getSchemaBuilder()->drop('lens_native_queue_samples');
        $this->get('/current/metrics/queues/history?connection=redis&queue=sample')->assertOk()
            ->assertSee('Queue wait history unavailable')->assertSee('Trend data')
            ->assertDontSee('No queue wait snapshots yet')->assertDontSee('Metric history unavailable');
    }

    public function test_draining_zero_target_has_processes_without_consuming_capacity(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $directory = sys_get_temp_dir().'/current-draining-'.bin2hex(random_bytes(5)); mkdir($directory, 0700);
        config()->set('current.native.state_directory', $directory); config()->set('current.native.instance', 'draining');
        try {
            file_put_contents($directory.'/draining.json', json_encode(['version' => 1, 'instance' => 'draining', 'owner' => str_repeat('a', 32),
                'state' => 'running', 'updated_at' => gmdate('c'), 'pools' => [['name' => 'orders', 'state' => 'degraded', 'connection' => 'redis',
                    'queues' => ['draining'], 'workers' => 0, 'min_workers' => 0, 'max_workers' => 70,
                    'slots' => [['pid' => 123, 'state' => 'stopping', 'starts' => 1]]]]]));
            Redis::rpush('queues:draining', 'fixture');
            $local = app(\Current\Native\Supervisor::class)->snapshot();
            $this->assertTrue($local['available']);
            $this->assertSame(1, $local['pools'][0]['running']);
            $this->assertSame(0, $local['pools'][0]['consuming']);
            $registry = ['available' => true, 'invalid' => 0, 'instances' => []];
            $point = app(QueueSamples::class)->observe($local, $registry)[0];
            $this->assertSame(0, $point['workers']);
            $this->assertSame(1, $point['ready']);
            $this->assertNull($point['wait_seconds']);
            $this->assertNull(app(\Current\Native\Workload::class)->snapshot($local, $registry)['rows'][0]['wait']);
        } finally { unlink($directory.'/draining.json'); rmdir($directory); }
    }
}
