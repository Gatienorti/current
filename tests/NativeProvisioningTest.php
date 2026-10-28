<?php

namespace Current\Tests;

use Current\Native\Plan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

final class NativeProvisioningTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance('env', 'testing');
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', [
            'state_directory' => '/tmp/current-test-plan', 'instance' => 'test', 'grace_seconds' => 10,
            'defaults' => [
                'imports' => ['connection' => 'redis', 'queues' => ['imports', 'fallback'], 'backoff' => [1, 5, 30], 'timeout' => 2, 'workers' => 1, 'max_workers' => 5],
                'mail' => ['connection' => 'redis', 'queues' => ['mail'], 'timeout' => 2],
            ],
        ]);
        Gate::define('viewCurrent', fn ($user) => true);
    }

    public function test_queue_names_fit_history_and_filter_identity_bounds(): void
    {
        config()->set('current.native.defaults.imports.queues', [str_repeat('q', 100)]);
        $this->assertSame([str_repeat('q', 100)], app(Plan::class)->pools()[0]['queues']);
        config()->set('current.native.defaults.imports.queues', [str_repeat('q', 101)]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 100');
        app(Plan::class)->pools();
    }

    public function test_environment_overrides_replace_lists_and_can_disable_default_pools(): void
    {
        config()->set('current.native.environments', [
            'testing' => ['imports' => ['queues' => ['priority'], 'backoff' => [7], 'workers' => 3], 'mail' => false],
            '*' => ['imports' => ['workers' => 2]],
        ]);
        $pools = app(Plan::class)->pools();
        $this->assertCount(1, $pools);
        $this->assertSame('imports', $pools[0]['name']);
        $this->assertSame(['priority'], $pools[0]['queues']);
        $this->assertSame([7], $pools[0]['backoff']);
        $this->assertSame(3, $pools[0]['workers']);
        $this->assertSame(5, $pools[0]['max_workers']);
        $this->assertSame(0, Artisan::call('current:supervise', ['--check' => true]));
        $runtime = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('--queue=priority', $runtime['pools'][0]['command']);
        $this->get('/current/queues')->assertOk()->assertSee('priority')->assertDontSee('fallback');
    }

    public function test_first_matching_wildcard_and_plain_pool_defaults_are_supported(): void
    {
        config()->set('current.native.environments', ['test*' => ['imports' => ['workers' => 4]], '*' => ['imports' => ['workers' => 2]]]);
        $this->assertSame(4, app(Plan::class)->pools()[0]['workers']);
        $this->assertCount(2, app(Plan::class)->pools());
        config()->set('current.native.environments', []);
        config()->set('current.native.pools', ['imports' => ['workers' => 2], 'mail' => false]);
        $this->assertSame(2, app(Plan::class)->pools()[0]['workers']);
        $this->assertCount(1, app(Plan::class)->pools());
    }

    public function test_redis_blocking_must_be_finite_and_shorter_than_shutdown_grace(): void
    {
        foreach ([0, '0', -1, 10, 11, 1.5] as $blocking) {
            config()->set('queue.connections.redis.block_for', $blocking);
            $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        }
        foreach ([null, 1, 9] as $blocking) {
            config()->set('queue.connections.redis.block_for', $blocking);
            $this->assertSame(0, Artisan::call('current:supervise', ['--check' => true]));
        }
    }

    public function test_long_worker_deadlines_require_longer_grace_and_redis_reservation(): void
    {
        config()->set('current.native.defaults', []);
        config()->set('current.native.pools', ['exports' => ['connection' => 'redis', 'queues' => ['exports'], 'workers' => 1, 'timeout' => 2500]]);
        config()->set('current.native.grace_seconds', 2530);
        config()->set('queue.connections.redis.retry_after', 2700);
        $this->assertContains('--timeout=2500', app(Plan::class)->runtime()['pools'][0]['command']);
        $this->assertSame(2530, app(Plan::class)->runtime()['grace_seconds']);
        foreach ([['timeout' => 2500, 'grace' => 2500, 'retry' => 2700],
            ['timeout' => 2500, 'grace' => 2530, 'retry' => 2500],
            ['timeout' => Plan::MAX_TIMEOUT_SECONDS + 1, 'grace' => Plan::MAX_GRACE_SECONDS, 'retry' => 90000],
            ['timeout' => 2500, 'grace' => Plan::MAX_GRACE_SECONDS + 1, 'retry' => 2700]] as $invalid) {
            config()->set('current.native.pools.exports.timeout', $invalid['timeout']);
            config()->set('current.native.grace_seconds', $invalid['grace']);
            config()->set('queue.connections.redis.retry_after', $invalid['retry']);
            $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        }
        config()->set('current.native.pools.exports.timeout', 0);
        config()->set('current.native.grace_seconds', 10);
        $this->assertContains('--timeout=0', app(Plan::class)->runtime()['pools'][0]['command']);
        config()->set('current.native.pools.exports.timeout', Plan::MAX_TIMEOUT_SECONDS);
        config()->set('current.native.grace_seconds', Plan::MAX_GRACE_SECONDS);
        config()->set('queue.connections.redis.retry_after', 90000);
        $this->assertSame(Plan::MAX_TIMEOUT_SECONDS, app(Plan::class)->pools()[0]['timeout']);
    }

    public function test_explicit_launcher_environment_is_forwarded_to_every_worker(): void
    {
        $this->assertSame(0, Artisan::call('current:supervise', ['--check' => true, '--env' => 'staging']));
        $runtime = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($runtime['pools'] as $pool) { $this->assertContains('--env=staging', $pool['command']); }
        foreach (app(Plan::class)->runtime()['pools'] as $pool) {
            $this->assertSame([], array_values(array_filter($pool['command'], fn ($argument) => str_starts_with($argument, '--env='))));
        }
    }

    public function test_instance_budget_is_validated_and_passed_to_go(): void
    {
        config()->set('current.native.worker_limit', 2);
        $this->assertSame(2, app(Plan::class)->runtime()['worker_limit']);
        foreach ([0, 1, 4097, '4'] as $limit) {
            config()->set('current.native.worker_limit', $limit);
            $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        }
    }

    public function test_large_reviewed_budgets_and_pool_counts_compile_without_starting_processes(): void
    {
        config()->set('current.native.defaults', []);
        config()->set('current.native.worker_limit', 4096);
        $pools = [];
        for ($i = 0; $i < 40; $i++) {
            $pools['pool-'.$i] = ['connection' => 'redis', 'queues' => ['queue-'.$i], 'workers' => 70, 'timeout' => 2];
        }
        config()->set('current.native.pools', $pools);
        $runtime = app(Plan::class)->runtime();
        $this->assertCount(40, $runtime['pools']);
        $this->assertSame(2800, array_sum(array_column($runtime['pools'], 'workers')));
        $this->assertSame(4096, $runtime['worker_limit']);
        config()->set('current.native.worker_limit', 256);
        $this->expectException(InvalidArgumentException::class);
        app(Plan::class)->runtime();
    }

    public function test_worker_output_is_explicit_and_json_requires_framework_support(): void
    {
        config()->set('current.native.worker_output', 'cli');
        $this->assertNotContains('--quiet', app(Plan::class)->runtime()['pools'][0]['command']);
        config()->set('current.native.worker_output', 'json');
        if (app(\Illuminate\Queue\Console\WorkCommand::class)->getDefinition()->hasOption('json')) {
            $this->assertContains('--json', app(Plan::class)->runtime()['pools'][0]['command']);
        } else {
            $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
            $this->assertStringContainsString('does not support JSON', Artisan::output());
        }
        config()->set('current.native.worker_output', 'invalid');
        $this->expectException(InvalidArgumentException::class);
        app(Plan::class)->runtime();
    }

    public function test_zero_floor_and_paused_start_preserve_worker_identity_and_environment_selection(): void
    {
        config()->set('current.native.defaults', []);
        config()->set('current.native.environments', ['blue' => ['idle' => ['connection' => 'redis', 'queues' => ['one', 'two'],
            'workers' => 0, 'min_workers' => 0, 'max_workers' => 70, 'balance' => 'auto', 'auto_scale_strategy' => 'size',
            'timeout' => 2, 'paused' => true, 'worker_name' => 'tenant-workers']]]);
        $this->assertSame(0, Artisan::call('current:supervise', ['--check' => true, '--environment' => 'blue']));
        $runtime = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $runtime['pools']);
        $this->assertSame([0, 0], array_column($runtime['pools'], 'workers'));
        $this->assertSame([true, true], array_column($runtime['pools'], 'paused'));
        $this->assertContains('--name=tenant-workers', $runtime['pools'][0]['command']);
        $this->assertSame('testing', app()->environment());
        $this->assertNull(config('current.native.environment'));
        $pool = ['min_workers' => 0, 'max_workers' => 70, 'balance' => 'size', 'jobs_per_worker' => 1, 'balance_shift' => 5];
        $this->assertSame(5, \Current\Native\Autoscaler::target(10, 0, $pool));
        $this->assertSame(0, \Current\Native\Autoscaler::target(0, 2, $pool));
    }

    public function test_routing_and_aggregate_limits_fail_during_php_preflight(): void
    {
        config()->set('current.native.defaults', []);
        $pools = [];
        for ($p = 0; $p < 5; $p++) {
            $queues = [];
            for ($q = 0; $q < 256; $q++) { $queues[] = 'route-'.$p.'-'.$q; }
            $pools['pool-'.$p] = ['connection' => 'redis', 'queues' => $queues, 'timeout' => 2];
        }
        config()->set('current.native.pools', $pools);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        $this->assertStringContainsString('1024 distinct', Artisan::output());
        $queues = [];
        for ($q = 0; $q < 256; $q++) { $queues[] = str_pad('queue-'.$q, 100, 'x'); }
        $pools = [];
        for ($p = 0; $p < 100; $p++) { $pools['pool-'.$p] = ['connection' => 'redis', 'queues' => $queues, 'timeout' => 2]; }
        config()->set('current.native.pools', $pools);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        $this->assertStringContainsString('4 MiB aggregate', Artisan::output());
        config()->set('current.native.pools', ['priority' => ['connection' => 'redis', 'queues' => $queues, 'timeout' => 2]]);
        $this->assertSame(0, Artisan::call('current:supervise', ['--check' => true]));
        $runtime = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(256, $runtime['pools'][0]['queues']);
    }

    public function test_no_environment_match_does_not_silently_launch_default_workers(): void
    {
        config()->set('current.native.environments', ['production' => []]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No native pools match');
        app(Plan::class)->runtime();
    }

    public function test_ambiguous_plain_and_environment_pools_are_rejected(): void
    {
        config()->set('current.native.pools', ['imports' => []]);
        config()->set('current.native.environments', ['*' => []]);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not both');
        app(Plan::class)->runtime();
    }

    public function test_effective_environment_configuration_still_has_all_safety_validation(): void
    {
        config()->set('current.native.environments', ['*' => ['imports' => ['workers' => 6]]]);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        config()->set('current.native.environments', ['*' => ['imports' => ['bogus' => true]]]);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        config()->set('current.native.environments', ['*' => ['imports' => false, 'mail' => false]]);
        $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
    }
}
