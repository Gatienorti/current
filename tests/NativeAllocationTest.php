<?php

namespace Current\Tests;

use Current\Native\Allocation;
use Current\Native\Plan;
use Illuminate\Support\Facades\Artisan;

final class NativeAllocationTest extends TestCase
{
    public function test_proportions_respect_minima_maxima_rounding_and_empty_queues(): void
    {
        $pools = [
            ['name' => 'a', 'min_workers' => 1, 'max_workers' => 9],
            ['name' => 'b', 'min_workers' => 1, 'max_workers' => 9],
        ];
        $this->assertEquals(['a' => 9, 'b' => 1], Allocation::targets($pools, ['a' => 90, 'b' => 10], 10));
        $this->assertEquals(['a' => 5, 'b' => 5], Allocation::targets($pools, ['a' => 1, 'b' => 1], 10));
        $this->assertEquals(['a' => 1, 'b' => 9], Allocation::targets($pools, ['a' => 0, 'b' => 100], 10));
        $this->assertEquals(['a' => 1, 'b' => 1], Allocation::targets($pools, ['a' => 0, 'b' => 0], 10));
        $this->assertEquals(['a' => 3, 'b' => 2], Allocation::targets($pools, ['a' => 1, 'b' => 1], 5));
        $pools[0]['max_workers'] = 3;
        $this->assertEquals(['a' => 3, 'b' => 7], Allocation::targets($pools, ['a' => 90, 'b' => 10], 10));
        // Minimum reservations must be applied before redistributing a capped share.
        $pools[] = ['name' => 'c', 'min_workers' => 4, 'max_workers' => 5];
        $this->assertEquals(['a' => 3, 'b' => 3, 'c' => 4], Allocation::targets($pools, ['a' => 90, 'b' => 10, 'c' => 0], 10));
        $linear = Allocation::targets(array_slice($pools, 0, 2), ['a' => 1, 'b' => 1000], 10);
        $log = Allocation::targets(array_slice($pools, 0, 2), ['a' => log1p(1), 'b' => log1p(1000)], 10);
        $this->assertLessThanOrEqual(10, array_sum($linear));
        $this->assertLessThanOrEqual(10, array_sum($log));
    }

    public function test_balanced_plan_creates_dedicated_queues_under_one_budget(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', ['state_directory' => '/tmp/current-allocation', 'instance' => 'test', 'grace_seconds' => 10,
            'pools' => ['orders' => ['queues' => ['first', 'second', 'third'], 'workers' => 5, 'min_workers' => 1, 'max_workers' => 8, 'timeout' => 2, 'balance' => 'auto', 'auto_scale_strategy' => 'size']]]);
        $plan = app(Plan::class);
        $this->assertCount(1, $plan->pools());
        $workers = $plan->workerPools();
        $this->assertSame([2, 2, 1], array_column($workers, 'workers'));
        $this->assertSame([6, 6, 6], array_column($workers, 'max_workers'));
        $runtime = $plan->runtime();
        foreach ($runtime['pools'] as $index => $pool) {
            $this->assertSame('orders', $pool['group']);
            $this->assertSame(8, $pool['group_limit']);
            $this->assertContains('--queue='.['first', 'second', 'third'][$index], $pool['command']);
            $this->assertContains('--name=orders', $pool['command']);
        }
        config()->set('current.native.pools.orders.balance', 'simple');
        $this->assertSame([2, 2, 1], array_column($plan->runtime()['pools'], 'workers'));
        config()->set('current.native.pools.orders.workers', 2);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        config()->set('current.native.pools.orders.balance', 'fixed');
        $this->assertCount(1, $plan->runtime()['pools']);
        $this->assertContains('--queue=first,second,third', $plan->runtime()['pools'][0]['command']);
    }

    public function test_many_queue_shapes_never_exceed_budget_or_strand_usable_capacity(): void
    {
        mt_srand(8017);
        for ($case = 0; $case < 200; $case++) {
            $pools = []; $weights = []; $minimum = 0; $maximum = 0;
            for ($i = 0; $i < mt_rand(1, 12); $i++) {
                $min = mt_rand(1, 4); $max = mt_rand($min, 20); $weight = mt_rand(0, 1000);
                $pools[] = ['name' => 'q'.$i, 'min_workers' => $min, 'max_workers' => $max];
                $weights['q'.$i] = $weight; $minimum += $min; $maximum += $weight > 0 ? $max : $min;
            }
            $budget = mt_rand($minimum, max($minimum, $maximum + 10));
            $targets = Allocation::targets($pools, $weights, $budget);
            $this->assertSame(min($budget, $maximum), array_sum($targets));
            foreach ($pools as $pool) {
                $this->assertGreaterThanOrEqual($pool['min_workers'], $targets[$pool['name']]);
                $this->assertLessThanOrEqual($pool['max_workers'], $targets[$pool['name']]);
            }
        }
    }

    public function test_invalid_runtime_evidence_cannot_trigger_allocation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Allocation::targets([['name' => 'missing', 'min_workers' => 1, 'max_workers' => 5]], [], 5);
    }
}
