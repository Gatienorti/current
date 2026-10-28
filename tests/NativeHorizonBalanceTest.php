<?php

namespace Current\Tests;

use Current\Native\HorizonAllocation;
use Current\Native\Plan;
use Illuminate\Contracts\Queue\Factory;
use Laravel\Horizon\AutoScaler;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Supervisor;
use Laravel\Horizon\SupervisorOptions;
use ReflectionClass;

final class NativeHorizonBalanceTest extends TestCase
{
    public function test_recommendations_match_installed_horizon_for_known_size_and_runtime_evidence(): void
    {
        $reference = new class($this->createStub(Factory::class), $this->createStub(MetricsRepository::class)) extends AutoScaler {
            public function recommendations(Supervisor $supervisor, array $queues): array
            {
                return $this->numberOfWorkersPerQueue($supervisor, collect($queues))->all();
            }
        };
        foreach ([['a'=>100,'b'=>1], ['a'=>1,'b'=>1,'c'=>1], ['a'=>0,'b'=>20], ['a'=>8,'b'=>2,'c'=>1]] as $weights) {
            foreach (['size','time'] as $strategy) {
                $supervisor = (new ReflectionClass(Supervisor::class))->newInstanceWithoutConstructor();
                $supervisor->options = new SupervisorOptions('parity', 'redis', balance: 'auto', maxProcesses: 10, minProcesses: 1, autoScalingStrategy: $strategy);
                $queues = []; $pools = [];
                foreach ($weights as $name => $weight) {
                    $queues[$name] = ['size'=>$weight, 'time'=>$weight];
                    $pools[] = ['name'=>$name,'min_workers'=>1,'max_workers'=>10 - count($weights) + 1];
                }
                $expected = [];
                foreach ($reference->recommendations($supervisor, $queues) as $name => $target) {
                    $expected[$name] = max(1, min(10 - count($weights) + 1, (int) ceil($target)));
                }
                ksort($expected);
                $actual = HorizonAllocation::targets($pools, $weights, 10); ksort($actual);
                $this->assertSame($expected, $actual, $strategy.' '.json_encode($weights));
            }
        }
    }

    public function test_rounding_recommendations_do_not_claim_a_global_allocation(): void
    {
        $pools = array_map(fn ($name) => ['name'=>$name,'min_workers'=>1,'max_workers'=>8], ['a','b','c']);
        $this->assertSame(['a'=>4,'b'=>4,'c'=>4], HorizonAllocation::targets($pools, ['a'=>1,'b'=>1,'c'=>1], 10));
        $this->assertSame(['a'=>1,'b'=>1,'c'=>1], HorizonAllocation::targets($pools, ['a'=>0,'b'=>0,'c'=>0], 10));
        $this->expectException(\InvalidArgumentException::class);
        HorizonAllocation::targets($pools, ['a'=>1,'b'=>NAN,'c'=>1], 10);
    }

    public function test_zero_measured_time_with_confirmed_backlog_matches_horizon_fallback(): void
    {
        $reference = new class($this->createStub(Factory::class), $this->createStub(MetricsRepository::class)) extends AutoScaler {
            public function recommendations(Supervisor $supervisor, array $queues): array { return $this->numberOfWorkersPerQueue($supervisor, collect($queues))->all(); }
        };
        $supervisor = (new ReflectionClass(Supervisor::class))->newInstanceWithoutConstructor();
        $supervisor->options = new SupervisorOptions('zero-time', 'redis', balance: 'auto', maxProcesses: 4, minProcesses: 0, autoScalingStrategy: 'time');
        $queues = ['busy' => ['size' => 3, 'time' => 0], 'idle' => ['size' => 0, 'time' => 0]];
        $pools = [['name' => 'busy', 'min_workers' => 0, 'max_workers' => 4], ['name' => 'idle', 'min_workers' => 0, 'max_workers' => 4]];
        $expected = $reference->recommendations($supervisor, $queues); ksort($expected);
        $actual = HorizonAllocation::targets($pools, ['busy' => 0, 'idle' => 0], 4, ['busy' => 3, 'idle' => 0]); ksort($actual);
        $this->assertSame(['busy' => 4, 'idle' => 0], $actual);
        $this->assertEquals($expected, $actual);
        // Missing runtime remains unavailable; this fallback requires known
        // measured zero and a separately confirmed ready count.
        $this->assertSame(['busy' => 0, 'idle' => 0], HorizonAllocation::targets($pools, ['busy' => 0, 'idle' => 0], 4));
    }

    public function test_pool_policy_defaults_to_horizon_with_explicit_previous_policy_available(): void
    {
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', ['grace_seconds'=>10, 'pools'=>['default'=>['queues'=>['a','b'],'workers'=>2,'max_workers'=>10,'timeout'=>2,'balance'=>'auto']]]);
        $this->assertSame('horizon', app(Plan::class)->workerPools()[0]['balance_policy']);
        config()->set('current.native.pools.default.balance_policy', 'bounded');
        $this->assertSame('bounded', app(Plan::class)->workerPools()[0]['balance_policy']);
        config()->set('current.native.pools.default.balance_policy', 'unknown');
        $this->expectException(\InvalidArgumentException::class);
        app(Plan::class)->pools();
    }
}
