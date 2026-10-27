<?php

namespace Current\Tests;

use Current\Native\Readiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

final class NativeDoctorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        (require dirname(__DIR__).'/database/migrations/2026_10_27_000012_add_async_telemetry_receipts.php')->up();
        $this->directory = sys_get_temp_dir().'/current-doctor-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        // A header fixture exercises static inspection. It must never be executed.
        $machine = match (php_uname('m')) { 'x86_64', 'amd64' => 62, default => 183 };
        file_put_contents($this->directory.'/supervisor', "\x7fELF\x02\x01".str_repeat("\0", 12).pack('v', $machine).str_repeat("\0", 44));
        chmod($this->directory.'/supervisor', 0700);
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', ['state_directory' => $this->directory, 'instance' => 'doctor', 'binary' => $this->directory.'/supervisor',
            'grace_seconds' => 10, 'worker_limit' => 2, 'pools' => ['orders' => ['connection' => 'redis', 'queues' => ['check'], 'workers' => 1, 'timeout' => 2]]]);
        config()->set('current.telemetry.driver', 'sync');
        Gate::define('viewCurrent', fn () => false); // Presence is not proof that this user is authorized.
        Gate::define('operateCurrent', fn () => false);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) { if (is_link($path) || is_file($path)) { unlink($path); } }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_doctor_checks_actual_redis_and_schema_without_configuration_or_queue_mutation(): void
    {
        $before = config()->all();
        Redis::rpush('queues:check', 'must-not-consume');
        $report = app(Readiness::class)->inspect();
        $this->assertTrue($report['ready'], json_encode($report));
        $checks = array_column($report['checks'], 'status', 'id');
        $this->assertSame('pass', $checks['history_schema']);
        $this->assertSame('pass', $checks['redis_1']);
        $this->assertSame('pass', $checks['supervisor_binary']);
        $this->assertSame('warning', $checks['deployment']);
        $this->assertSame(1, Redis::llen('queues:check'));
        $this->assertSame(['supervisor'], array_map('basename', glob($this->directory.'/*')));
        $this->assertSame($before, config()->all());
        $this->assertSame(0, Artisan::call('current:doctor', ['--json' => true]));
        $this->assertTrue(json_decode(Artisan::output(), true)['ready']);
        $this->assertSame(0, Artisan::call('current:doctor'));
        $this->assertStringContainsString('Read checks passed', Artisan::output());
    }

    public function test_doctor_reports_real_setup_errors_without_running_or_repairing_anything(): void
    {
        chmod($this->directory, 0755);
        config()->set('current.queue_connections', []);
        config()->set('current.native.binary', $this->directory.'/missing');
        Schema::connection('lens')->drop('lens_native_retry_claims');
        $report = app(Readiness::class)->inspect();
        $this->assertFalse($report['ready']);
        $checks = array_column($report['checks'], 'status', 'id');
        foreach (['state_directory', 'capture_connections', 'supervisor_binary', 'history_schema'] as $key) { $this->assertSame('error', $checks[$key]); }
        $this->assertFalse(Schema::connection('lens')->hasTable('lens_native_retry_claims'));
        $this->assertFalse(is_file($this->directory.'/missing'));
        $this->assertSame(1, Artisan::call('current:doctor', ['--json' => true]));
    }

    public function test_absent_storage_is_not_created_and_transport_errors_do_not_expose_credentials(): void
    {
        $missing = $this->directory.'/missing.sqlite';
        config()->set('database.connections.lens.database', $missing);
        config()->set('current.native.state_directory', $this->directory.'/absent');
        config()->set('current.telemetry.driver', 'redis');
        config()->set('current.telemetry.binary', $this->directory.'/supervisor');
        config()->set('current.telemetry.namespace', 'bad/private-secret');
        config()->set('database.redis.default.password', 'never-echo-password');
        $report = app(Readiness::class)->inspect();
        $this->assertFalse($report['ready']);
        $checks = array_column($report['checks'], 'status', 'id');
        $this->assertSame('warning', $checks['state_directory']);
        $this->assertSame('error', $checks['history_schema']);
        $this->assertSame('error', $checks['collector']);
        $this->assertFileDoesNotExist($missing);
        $this->assertDirectoryDoesNotExist($this->directory.'/absent');
        $this->assertStringNotContainsString('never-echo-password', json_encode($report));
        $this->assertStringNotContainsString('bad/private-secret', json_encode($report));
    }

    public function test_unknown_backend_unsupported_client_and_executable_script_remain_blocked(): void
    {
        file_put_contents($this->directory.'/script', '#!/bin/sh'."\n".'touch '.$this->directory.'/executed');
        chmod($this->directory.'/script', 0700);
        config()->set('current.execution_backend', 'horizon');
        config()->set('database.redis.client', 'predis');
        config()->set('current.native.binary', $this->directory.'/script');
        $report = app(Readiness::class)->inspect();
        $this->assertFalse($report['ready']);
        $checks = array_column($report['checks'], 'status', 'id');
        foreach (['backend', 'redis_client', 'supervisor_binary'] as $key) { $this->assertSame('error', $checks[$key]); }
        $this->assertFileDoesNotExist($this->directory.'/executed');
    }

    public function test_a_file_or_non_traversable_private_directory_is_not_reported_ready(): void
    {
        config()->set('current.native.state_directory', $this->directory.'/supervisor');
        $report = app(Readiness::class)->inspect();
        $this->assertSame('error', array_column($report['checks'], 'status', 'id')['state_directory']);
        config()->set('current.native.state_directory', $this->directory);
        chmod($this->directory, 0600); clearstatcache();
        try {
            $report = app(Readiness::class)->inspect();
            $this->assertSame('error', array_column($report['checks'], 'status', 'id')['state_directory']);
        } finally { chmod($this->directory, 0700); }
    }

    public function test_disabled_laravel_alarm_is_an_explicit_warning_with_finite_shutdown_grace(): void
    {
        config()->set('current.native.pools.orders.timeout', 0);
        $report = app(Readiness::class)->inspect();
        $this->assertTrue($report['ready']);
        $this->assertSame('warning', array_column($report['checks'], 'status', 'id')['worker_timeout']);
    }
}
