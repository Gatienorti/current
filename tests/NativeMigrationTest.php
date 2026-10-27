<?php

namespace Current\Tests;

use Current\Native\HorizonMigration;
use Current\Native\Plan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\ProvisioningPlan;

final class NativeMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('env', 'testing');
        config()->set('horizon.env', null);
        config()->set('horizon.defaults', ['orders' => ['connection' => 'redis', 'queue' => ['critical', 'normal', 'bulk'],
            'balance' => 'auto', 'minProcesses' => 1, 'maxProcesses' => 7, 'timeout' => 2, 'backoff' => [1, 3, 9]]]);
        config()->set('horizon.environments', ['test*' => ['orders' => ['queue' => ['priority'], 'backoff' => [5]]], '*' => ['orders' => ['maxProcesses' => 2]]]);
    }

    public function test_preview_preserves_installed_horizon_merging_and_restores_all_configuration(): void
    {
        $before = config()->all();
        Redis::set('migration-sentinel', 'unchanged');
        $original = (new ProvisioningPlan('oracle', config('horizon.environments'), config('horizon.defaults')))->parsed['test*']['orders'];
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame('test*', $report['matched_environment']);
        $pool = $report['config']['native']['pools']['orders'];
        $this->assertSame(explode(',', $original->queue), $pool['queues']);
        $this->assertSame(['priority', 'normal', 'bulk'], $pool['queues']);
        $this->assertSame([5, 3, 9], $pool['backoff']);
        $this->assertSame($original->minProcesses, $pool['min_workers']);
        $this->assertSame($original->maxProcesses, $pool['max_workers']);
        $this->assertSame(3, $pool['workers']);
        $this->assertSame('auto', $pool['balance']);
        $this->assertSame('time', $pool['auto_scale_strategy']);
        $this->assertSame('horizon', $pool['balance_policy']);
        $this->assertSame(7, $report['config']['native']['worker_limit']);
        $this->assertSame(['redis' => ['from' => 'redis', 'to' => 'current-redis']], $report['queue_changes']);
        $this->assertSame($before, config()->all());
        $this->assertSame('unchanged', Redis::get('migration-sentinel'));
        $this->assertSame(0, Redis::llen('queues:priority'));
        $this->assertSame(0, Artisan::call('current:migrate-horizon', ['--json' => true]));
        $this->assertTrue(json_decode(Artisan::output(), true)['valid']);
        $this->assertSame($before, config()->all());
        $this->assertSame(0, Artisan::call('current:migrate-horizon'));
        $output = Artisan::output();
        $this->assertStringContainsString('no files, queues or services changed', $output);
        $this->assertStringContainsString('current:doctor', $output);
    }

    public function test_false_balance_legacy_aliases_and_disabled_supervisors_are_explicit(): void
    {
        config()->set('horizon.defaults', []);
        config()->set('horizon.environments', ['production' => [
            'priority' => ['connection' => 'redis', 'queue' => 'high,low', 'balance' => false, 'processes' => 5, 'timeout' => 2, 'tries' => 0],
            'disabled' => ['connection' => 'redis', 'queue' => ['unused'], 'maxProcesses' => 0],
        ]]);
        $report = app(HorizonMigration::class)->preview('production');
        $this->assertTrue($report['valid']);
        $this->assertCount(1, $report['config']['native']['pools']);
        $pool = $report['config']['native']['pools']['priority'];
        $this->assertSame('fixed', $pool['balance']);
        $this->assertSame(['high', 'low'], $pool['queues']);
        $this->assertSame(3, $pool['workers']);
        $this->assertSame(0, $pool['tries']);
        $this->assertContains('disabled', array_column($report['issues'], 'code'));
        $this->assertFalse(app(HorizonMigration::class)->preview('missing')['valid']);
        $this->assertSame(1, Artisan::call('current:migrate-horizon', ['--environment' => 'missing', '--json' => true]));
    }

    public function test_unsupported_values_are_not_echoed_or_silently_dropped(): void
    {
        config()->set('horizon.defaults.orders.customSecret', 'never-echo-private-credential');
        $before = config()->all();
        $report = app(HorizonMigration::class)->preview();
        $this->assertFalse($report['valid']);
        $this->assertNull($report['config']);
        $this->assertStringNotContainsString('customSecret', json_encode($report));
        $this->assertStringNotContainsString('never-echo-private-credential', json_encode($report));
        $this->assertSame($before, config()->all());
        $this->assertSame(1, Artisan::call('current:migrate-horizon', ['--json' => true]));
        $this->assertStringNotContainsString('never-echo-private-credential', Artisan::output());
    }

    public function test_native_constraints_block_an_unsafe_or_ambiguous_preview_without_config_changes(): void
    {
        $base = config('horizon.defaults');
        config()->set('horizon.environments', ['testing' => []]);
        foreach ([
            ['timeout' => 10], // Above the actual queue retry_after.
            ['maxProcesses' => 257], ['minProcesses' => 3, 'maxProcesses' => 4],
            ['minProcesses' => 'one'], ['balance' => 'unrecognized'],
            ['queue' => ['duplicate', 'duplicate']], ['backoff' => ['not-a-number']],
            ['workersName' => 'custom-worker'], ['directory' => '/private/other-app'],
            ['max_processes' => 7], // Ambiguous alias duplicates maxProcesses.
        ] as $override) {
            config()->set('horizon.defaults', ['orders' => array_replace($base['orders'], $override)]);
            $before = config()->all();
            $report = app(HorizonMigration::class)->preview();
            $this->assertFalse($report['valid'], json_encode(array_keys($override)));
            $this->assertNull($report['config']);
            $this->assertSame($before, config()->all());
        }
    }

    public function test_previewed_plan_can_be_applied_in_memory_and_compiled_by_the_native_contract(): void
    {
        $report = app(HorizonMigration::class)->preview();
        config()->set('current.native', $report['config']['native']);
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        $pools = app(Plan::class)->workerPools();
        $this->assertCount(3, $pools);
        $this->assertSame(3, array_sum(array_column($pools, 'workers')));
        $this->assertSame([['priority'], ['normal'], ['bulk']], array_column($pools, 'queues'));
        $this->assertContains('--queue=priority', app(Plan::class)->runtime()['pools'][0]['command']);
    }

    public function test_integer_environment_values_are_normalized_without_overflow_or_boolean_coercion(): void
    {
        config()->set('horizon.defaults.orders.maxProcesses', '7');
        config()->set('horizon.defaults.orders.timeout', '2');
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame(7, $report['config']['native']['pools']['orders']['max_workers']);
        config()->set('horizon.defaults.orders.maxProcesses', str_repeat('9', 30));
        $this->assertFalse(app(HorizonMigration::class)->preview()['valid']);
        config()->set('horizon.defaults.orders.maxProcesses', true);
        $this->assertFalse(app(HorizonMigration::class)->preview()['valid']);
    }

    public function test_simple_startup_rounds_each_queue_share_down_with_native_minima_explicit(): void
    {
        config()->set('horizon.defaults', []);
        config()->set('horizon.environments', ['testing' => ['pair' => [
            'connection' => 'redis', 'queue' => ['one', 'two'], 'balance' => 'simple',
            'minProcesses' => 1, 'maxProcesses' => 7, 'timeout' => 2,
        ]]]);
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame(4, $report['config']['native']['pools']['pair']['workers']);
        config()->set('horizon.environments.testing.pair.maxProcesses', 5);
        $report = app(HorizonMigration::class)->preview();
        // Midpoint 3 divided among two Horizon pools gives one each.
        $this->assertTrue($report['valid']);
        $this->assertSame(2, $report['config']['native']['pools']['pair']['workers']);
        config()->set('horizon.environments.testing.pair.maxProcesses', 2);
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame(2, $report['config']['native']['pools']['pair']['workers']);
        $this->assertContains('startup_minimum', array_column($report['issues'], 'code'));
    }

    public function test_queue_floor_matches_actual_horizon_scale_without_launching_processes(): void
    {
        $reference = (new \ReflectionClass(\Laravel\Horizon\Supervisor::class))->newInstanceWithoutConstructor();
        $reference->options = new \Laravel\Horizon\SupervisorOptions('oracle', 'redis', 'one,two', balance: 'auto', maxProcesses: 1, minProcesses: 1);
        $reference->processPools = collect(array_map(fn ($queue) => new class($queue) {
            public function __construct(private string $name) {}
            public function queue(): string { return $this->name; }
            public function scale($count): void {} // Observe the controller without spawning workers.
        }, ['one', 'two']));
        $reference->scale(1);
        $this->assertSame(2, $reference->options->maxProcesses);
        config()->set('horizon.defaults', []);
        config()->set('horizon.environments', ['testing' => ['routes' => ['connection' => 'redis', 'queue' => ['one', 'two'], 'balance' => 'auto', 'maxProcesses' => 1, 'timeout' => 2]]]);
        $before = config()->all();
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame($reference->options->maxProcesses, $report['config']['native']['pools']['routes']['max_workers']);
        $this->assertSame(2, $report['config']['native']['worker_limit']);
        $this->assertContains('horizon_queue_floor', array_column($report['issues'], 'code'));
        $this->assertSame($before, config()->all());
    }

    public function test_long_timeouts_and_disabled_alarm_are_preserved_with_finite_grace(): void
    {
        config()->set('queue.connections.redis.retry_after', 2700);
        config()->set('horizon.defaults.orders.timeout', 2500);
        $before = config()->all();
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame(2500, $report['config']['native']['pools']['orders']['timeout']);
        $this->assertSame(2730, $report['config']['native']['grace_seconds']);
        $this->assertSame($before, config()->all());
        config()->set('horizon.defaults.orders.timeout', 0);
        $report = app(HorizonMigration::class)->preview();
        $this->assertTrue($report['valid']);
        $this->assertSame(0, $report['config']['native']['pools']['orders']['timeout']);
        $this->assertContains('unlimited_timeout', array_column($report['issues'], 'code'));
        config()->set('horizon.defaults.orders.timeout', Plan::MAX_TIMEOUT_SECONDS + 1);
        $this->assertFalse(app(HorizonMigration::class)->preview()['valid']);
    }
}
