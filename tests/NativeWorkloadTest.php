<?php

namespace Current\Tests;

use Current\Native\Workload;
use Current\Prototype\Jobs\PaymentJob;
use Illuminate\Support\Facades\Redis;

final class NativeWorkloadTest extends TestCase
{
    public function test_shared_inventory_deduplicates_local_instance_and_estimates_ready_work(): void
    {
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        dispatch(new PaymentJob('success')); $this->work();
        $end = microtime(true) - 1;
        $this->store()->table('attempts')->update(['started_at' => $end - 2, 'finished_at' => $end]);
        $snapshot = app(\Current\Native\RuntimeSnapshot::class);
        $routes = ['redis' => ['lens-demo']];
        $snapshot->publish($snapshot->sample($routes), $routes);
        $this->store()->connection()->beforeExecuting(fn () => throw new \RuntimeException('Workload must not query history SQL.'));
        Redis::rpush('queues:lens-demo', ...array_fill(0, 12, 'fixture'));
        Redis::zadd('queues:lens-demo:delayed', time() + 3600, 'future');
        Redis::zadd('queues:lens-demo:reserved', time() + 3, 'reserved');
        $pool = ['name' => 'orders', 'connection' => 'redis', 'queues' => ['lens-demo'], 'running' => 2, 'state' => 'running'];
        $local = ['available' => true, 'instance' => 'test', 'owner' => str_repeat('a', 32), 'state' => 'running', 'pools' => [$pool]];
        $id = hash('sha256', (gethostname() ?: 'unknown-host')."\0test\0".str_repeat('a', 32));
        $registry = ['available' => true, 'invalid' => 0, 'instances' => [
            ['id' => $id, 'state' => 'running', 'pools' => [$pool]],
            ['id' => str_repeat('b', 64), 'state' => 'running', 'pools' => [$pool]],
        ]];
        $rows = app(Workload::class)->snapshot($local, $registry)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['running']);
        $this->assertSame(12, $rows[0]['ready']);
        $this->assertEqualsWithDelta(6, $rows[0]['wait'], 0.01);
        $registry['instances'][1]['state'] = 'stale';
        $registry['instances'][1]['pools'][0]['running'] = null;
        $rows = app(Workload::class)->snapshot($local, $registry)['rows'];
        $this->assertTrue($rows[0]['uncertain']);
        $this->assertNull($rows[0]['wait']);
        $registry['instances'] = []; $registry['available'] = false;
        $this->assertNull(app(Workload::class)->snapshot($local, $registry)['rows'][0]['wait']);
        config()->set('current.native.registry.enabled', false);
        $this->assertEqualsWithDelta(12, app(Workload::class)->snapshot($local, $registry)['rows'][0]['wait'], 0.01);
    }

    public function test_overlapping_queue_groups_and_absent_runtimes_are_not_false_wait_estimates(): void
    {
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        $pool = ['name' => 'orders', 'connection' => 'redis', 'queues' => ['one'], 'running' => 2, 'state' => 'running'];
        $local = ['available' => false, 'pools' => []];
        $registry = ['available' => true, 'invalid' => 0, 'instances' => [['id' => str_repeat('a', 64), 'state' => 'running', 'pools' => [$pool]]]];
        $this->assertSame(0.0, app(Workload::class)->snapshot($local, $registry)['rows'][0]['wait']);
        Redis::rpush('queues:one', 'fixture');
        $this->assertNull(app(Workload::class)->snapshot($local, $registry)['rows'][0]['wait']);
        $pool['queues'] = ['one', 'two'];
        $registry['instances'][0]['pools'][] = $pool;
        $rows = app(Workload::class)->snapshot($local, $registry)['rows'];
        $this->assertCount(2, $rows);
        foreach ($rows as $row) { $this->assertNull($row['wait']); $this->assertStringContainsString('shared', $row['reason']); }
        Redis::del('queues:one'); Redis::set('queues:one', 'wrong-type');
        $this->assertNull(app(Workload::class)->snapshot($local, $registry)['rows'][0]['ready']);
    }

    public function test_stopped_and_draining_instances_do_not_hide_or_inflate_active_consuming_capacity(): void
    {
        config()->set('current.execution_backend', 'native'); config()->set('queue.connections.redis.driver', 'current-redis');
        dispatch(new PaymentJob('success')); $this->work();
        $end = microtime(true) - 1;
        $this->store()->table('attempts')->update(['started_at' => $end - 2, 'finished_at' => $end]);
        $snapshot = app(\Current\Native\RuntimeSnapshot::class);
        $routes = ['redis' => ['lens-demo']];
        $snapshot->publish($snapshot->sample($routes), $routes);
        Redis::rpush('queues:lens-demo', ...array_fill(0, 12, 'fixture'));
        $pool = ['name' => 'orders', 'connection' => 'redis', 'queues' => ['lens-demo'], 'running' => 2, 'state' => 'running'];
        $local = ['available' => true, 'instance' => 'test', 'owner' => str_repeat('a', 32), 'state' => 'running', 'pools' => [$pool]];
        $draining = array_replace($pool, ['state' => 'pausing']);
        $stopped = array_replace($pool, ['state' => 'stopped', 'running' => 0]);
        $oldRouting = array_replace($stopped, ['queues' => ['lens-demo', 'retired']]);
        $registry = ['available' => true, 'invalid' => 0, 'instances' => [
            ['id' => str_repeat('b', 64), 'state' => 'pausing', 'pools' => [$draining]],
            ['id' => str_repeat('c', 64), 'state' => 'stopped', 'pools' => [$stopped, $oldRouting]],
        ]];
        $rows = app(Workload::class)->snapshot($local, $registry)['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(4, $rows[0]['running']);
        $this->assertSame(2, $rows[0]['consuming']);
        $this->assertEqualsWithDelta(12, $rows[0]['wait'], .01);
        $onlyDraining = app(Workload::class)->snapshot(['available' => false], $registry)['rows'];
        $this->assertSame(2, $onlyDraining[0]['running']);
        $this->assertSame(0, $onlyDraining[0]['consuming']);
        $this->assertNull($onlyDraining[0]['wait']);
        $registry['instances'][0]['state'] = 'stale';
        $registry['instances'][0]['pools'][0]['running'] = null;
        $unknown = app(Workload::class)->snapshot($local, $registry)['rows'][0];
        $this->assertTrue($unknown['uncertain']);
        $this->assertNull($unknown['wait']);
    }
}
