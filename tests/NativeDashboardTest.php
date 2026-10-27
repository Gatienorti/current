<?php

namespace Current\Tests;

use Current\Native\Dashboard;
use Current\Native\Sections;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class NativeDashboardTest extends TestCase
{
    private function job(string $queue, float $at, ?string $status = null, ?float $duration = null, bool $silenced = false): string
    {
        $id = $this->store()->newJob((string) Str::uuid(), 'redis', $queue, ['data' => ['commandName' => 'DashboardFixture'], 'silenced' => $silenced]);
        $this->store()->table('jobs')->where('id', $id)->update(['first_observed_at' => $at, 'observed_at' => $at, 'status' => $status ?? 'pending']);
        if ($status !== null) {
            $this->store()->table('attempts')->insert(['id' => (string) Str::ulid(), 'job_id' => $id, 'reported_attempt' => 1,
                'worker_id' => 'dashboard-fixture', 'status' => $status, 'started_at' => $at - ($duration ?? 0.25), 'finished_at' => $at]);
        }
        return $id;
    }

    public function test_native_dashboard_exposes_the_eight_operational_summary_cards(): void
    {
        config()->set('current.execution_backend', 'native');
        config()->set('current.native.registry.enabled', false);
        Gate::define('viewCurrent', fn () => true);
        $this->job('orders', microtime(true) - 2, 'completed', .5);
        $response = $this->get('/current')->assertOk();
        foreach (['Jobs Per Minute', 'Jobs Past Hour', 'Failed Jobs Past 7 Days', 'Status', 'Total Processes', 'Max Wait Time', 'Max Runtime', 'Max Throughput'] as $label) {
            $response->assertSee($label);
        }
        $response->assertSee('data-live-region="native-summary"', false);
    }

    public function test_metric_lists_keep_completion_counts_when_only_runtime_is_invalid(): void
    {
        config()->set('current.execution_backend', 'native');
        $now = microtime(true);
        $this->job('timing', $now - 2, 'completed', .5);
        $this->job('timing', $now - 1, 'completed', -.5);
        $page = app(Sections::class)->page('metrics', 'queues');
        $this->assertTrue($page['available']);
        $this->assertSame(2, $page['rows'][0]['cells'][1]);
        $this->assertSame('500.0 ms', $page['rows'][0]['cells'][2]);
        $this->job('invalid', $now - 1, 'completed', -.5);
        $invalid = collect(app(Sections::class)->page('metrics', 'queues')['rows'])->first(fn ($row) => $row['cells'][0] === 'redis / invalid');
        $this->assertSame(1, $invalid['cells'][1]);
        $this->assertSame('—', $invalid['cells'][2]);
    }

    private function inventory(string $state = 'running', ?int $running = 2): array
    {
        return ['available' => true, 'instance' => 'dashboard', 'owner' => str_repeat('a', 32), 'state' => $state,
            'pools' => [['name' => 'orders', 'state' => $state, 'workers' => 2, 'running' => $running]]];
    }

    private function registry(array $instances = []): array
    {
        return ['available' => true, 'invalid' => 0, 'instances' => $instances];
    }

    private function workload(array $waits = [3.5]): array
    {
        return ['available' => true, 'partial' => false, 'rows' => array_map(fn ($wait) => ['connection' => 'redis', 'queues' => ['orders'], 'wait' => $wait], $waits)];
    }

    public function test_counts_rankings_and_rate_use_retained_observations_and_preserve_silenced_completions(): void
    {
        config()->set('current.execution_backend', 'native');
        $now = microtime(true);
        $this->job('slow', $now - 20, 'completed', 5);
        $this->job('fast', $now - 10, 'completed', .1);
        $this->job('fast', $now - 5, 'completed', .1, true);
        $this->job('invalid', $now - 2, 'completed', -.2);
        $this->job('pending', $now - 3);
        $this->job('failed', $now - 4, 'failed');
        $expired = $this->job('expired', $now - 1, 'completed', 50);
        $this->store()->table('jobs')->where('id', $expired)->update(['expires_at' => $now - .5]);
        $this->job('old', $now - 4000, 'completed', 100);
        $this->job('old-failure', $now - 604801, 'failed');
        $this->job('future', $now + 2, 'completed', 100);
        $summary = app(Dashboard::class)->snapshot($this->inventory(), $this->registry(), $this->workload(), $now);
        $this->assertTrue($summary['counts']['available']);
        $this->assertSame(['recent' => 6, 'failed' => 1], $summary['counts']['data']);
        $this->assertSame(4.0, $summary['metrics']['data']['rate']);
        $this->assertSame('slow', $summary['metrics']['data']['max_runtime']['queue']);
        $this->assertEqualsWithDelta(5000, $summary['metrics']['data']['max_runtime']['runtime_ms'], .01);
        $this->assertSame(['connection' => 'redis', 'queue' => 'fast', 'completed' => 2], $summary['metrics']['data']['max_throughput']);
        $this->assertSame(3.5, $summary['wait']['value']);
    }

    public function test_metric_reset_uses_the_exact_cutoff_and_history_counts_stay_independent(): void
    {
        $now = (float) time();
        $this->job('before', $now - 31, 'completed', 100);
        $this->job('boundary', $now - 30, 'completed', .1);
        $this->job('after', $now - 29, 'completed', .2);
        $this->store()->table('meta')->insert(['key' => 'native_metrics_reset_at', 'value' => (string) ($now - 30)]);
        $dashboard = app(Dashboard::class);
        $first = $dashboard->snapshot($this->inventory(), $this->registry(), $this->workload(), $now);
        $second = $dashboard->snapshot($this->inventory(), $this->registry(), $this->workload(), $now + 10);
        $this->assertSame(4.0, $first['metrics']['data']['rate']);
        $this->assertSame(3.0, $second['metrics']['data']['rate']);
        $this->assertSame($now - 30, $first['metrics']['data']['since']);
        $this->assertSame($now - 30, $second['metrics']['data']['since']);
        $this->assertSame('after', $first['metrics']['data']['max_runtime']['queue']);
        $this->assertSame(3, $first['counts']['data']['recent']);
        $this->assertSame(3, $second['counts']['data']['recent']);
    }

    public function test_inventory_deduplicates_current_generation_and_excludes_terminal_allocations(): void
    {
        $local = $this->inventory();
        $copy = $local + ['id' => hash('sha256', (gethostname() ?: 'unknown-host')."\0dashboard\0".str_repeat('a', 32))];
        $remote = $this->inventory() + ['id' => str_repeat('b', 64)];
        $stopped = $this->inventory('stopped', 0) + ['id' => str_repeat('c', 64)];
        $summary = app(Dashboard::class)->snapshot($local, $this->registry([$copy, $remote, $stopped]), $this->workload());
        $this->assertTrue($summary['inventory']['available']);
        $this->assertSame('running', $summary['inventory']['state']);
        $this->assertSame(4, $summary['inventory']['processes']);
        $this->assertSame(4, $summary['inventory']['allocated']);
        $this->assertSame(3, $summary['inventory']['instances']);
        foreach ([
            $this->registry([$remote, $this->inventory('stale', null) + ['id' => str_repeat('d', 64)]]),
            ['available' => false, 'invalid' => 0, 'instances' => []],
            ['available' => true, 'invalid' => 1, 'instances' => []],
        ] as $registry) {
            $result = app(Dashboard::class)->snapshot($local, $registry, $this->workload());
            $this->assertFalse($result['inventory']['available']);
            $this->assertSame('unknown', $result['inventory']['state']);
            $this->assertNull($result['inventory']['processes']);
            $this->assertNull($result['inventory']['allocated']);
        }
        config()->set('current.native.registry.enabled', false);
        $onlyLocal = app(Dashboard::class)->snapshot($local, ['available' => false], $this->workload());
        $this->assertSame(2, $onlyLocal['inventory']['processes']);
        $this->assertSame('local instance', $onlyLocal['inventory']['scope']);
    }

    public function test_unknown_wait_and_failed_metric_source_do_not_erase_independent_summaries(): void
    {
        $this->job('pending', microtime(true) - 1);
        $this->store()->connection()->beforeExecuting(function ($query) {
            if (str_contains($query, 'lens_meta')) { throw new \RuntimeException('private metric failure'); }
        });
        $summary = app(Dashboard::class)->snapshot($this->inventory(), $this->registry(), $this->workload([5, null]));
        $this->assertFalse($summary['metrics']['available']);
        $this->assertTrue($summary['counts']['available']);
        $this->assertSame(1, $summary['counts']['data']['recent']);
        $this->assertTrue($summary['inventory']['available']);
        $this->assertFalse($summary['wait']['available']);
        $this->assertNull($summary['wait']['value']);
        $partial = $this->workload([5]); $partial['partial'] = true;
        $this->assertFalse(app(Dashboard::class)->snapshot($this->inventory(), $this->registry(), $partial)['wait']['available']);
    }
}
