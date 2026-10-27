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
        foreach ([0, 1, 257, '4'] as $limit) {
            config()->set('current.native.worker_limit', $limit);
            $this->assertSame(1, Artisan::call('current:supervise', ['--check' => true]));
        }
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
