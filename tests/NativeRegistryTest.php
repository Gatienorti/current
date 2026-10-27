<?php

namespace Current\Tests;

use Current\Native\Registry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;

final class NativeRegistryTest extends TestCase
{
    private function observation(string $owner = 'a'): array
    {
        return ['available' => true, 'owner' => str_repeat($owner, 32), 'instance' => 'imports-worker', 'state' => 'running',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => [
                ['name' => 'imports', 'state' => 'running', 'workers' => 2, 'min' => 1, 'max' => 10, 'running' => 2],
            ]];
    }

    public function test_hosts_and_process_generations_are_distinct_without_exposing_control_owners(): void
    {
        $registry = app(Registry::class);
        $registry->publish($this->observation(), 'host-one');
        $registry->publish($this->observation(), 'host-two');
        $registry->publish($this->observation('b'), 'host-one');
        $result = $registry->all();
        $this->assertTrue($result['available']);
        $this->assertCount(3, $result['instances']);
        foreach ($result['instances'] as $instance) {
            $this->assertArrayNotHasKey('owner', $instance);
            $this->assertSame(2, $instance['pools'][0]['running']);
        }
        $registry->publish($this->observation(), 'host-one');
        $this->assertCount(3, $registry->all()['instances']);
    }

    public function test_missing_heartbeats_become_unknown_then_expire_without_inventing_worker_death(): void
    {
        $registry = app(Registry::class);
        $registry->publish($this->observation(), 'host-one');
        $record = $registry->all()['instances'][0];
        $now = (int) Redis::time()[0];
        Redis::zadd('current:native-registry:index', $now - 8, $record['id']);
        $stale = $registry->all()['instances'][0];
        $this->assertSame('stale', $stale['state']);
        $this->assertNull($stale['pools'][0]['running']);
        $this->assertSame('unknown', $stale['pools'][0]['state']);
        $this->assertSame(2, $stale['pools'][0]['workers']);
        Redis::zadd('current:native-registry:index', $now - 61, $record['id']);
        $this->assertSame([], $registry->all()['instances']);
        $this->assertGreaterThan(0, Redis::ttl('current:native-registry:record:'.$record['id']));
        $this->assertLessThanOrEqual(60, Redis::ttl('current:native-registry:record:'.$record['id']));
    }

    public function test_registry_is_bounded_namespaced_and_rejects_corrupt_records(): void
    {
        $registry = app(Registry::class);
        for ($i = 0; $i < 260; $i++) {
            $health = $this->observation();
            $health['owner'] = str_pad(dechex($i), 32, '0', STR_PAD_LEFT);
            $registry->publish($health, 'host');
        }
        $this->assertSame(256, (int) Redis::zcard('current:native-registry:index'));
        $result = $registry->all();
        $this->assertCount(256, $result['instances']);
        $id = $result['instances'][0]['id'];
        Redis::set('current:native-registry:record:'.$id, '{invalid');
        $this->assertSame(1, $registry->all()['invalid']);
        config()->set('current.native.registry.namespace', 'another');
        $this->assertSame([], $registry->all()['instances']);
        config()->set('current.native.registry.redis_connection', 'missing');
        $this->assertFalse($registry->all()['available']);
        // No configured local snapshot and unavailable registry never affect execution.
        $registry->tick();
    }

    public function test_registry_timeout_does_not_inherit_an_unbounded_application_connection(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires PCNTL and local TCP.'); }
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($server);
        $address = stream_socket_get_name($server, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        config()->set('database.redis.registry_stall', ['host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'timeout' => 30, 'read_timeout' => 30]);
        config()->set('current.native.registry.redis_connection', 'registry_stall');
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $socket = @stream_socket_accept($server, 2);
            if (! $socket) { exit(2); }
            sleep(3);
            fclose($socket); fclose($server); exit(0);
        }
        try {
            $start = microtime(true);
            $this->assertFalse(app(Registry::class)->all()['available']);
            $this->assertLessThan(2, microtime(true) - $start);
            $this->assertSame(30, config('database.redis.registry_stall.read_timeout'));
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

    public function test_remote_inventory_is_authorized_escaped_and_has_no_remote_controls(): void
    {
        config()->set('current.execution_backend', 'native');
        Gate::define('viewCurrent', fn ($user) => true);
        app(Registry::class)->publish($this->observation(), '<script>bad()</script>');
        $this->get('/current')->assertOk()->assertSee('Worker instances')->assertSee('imports-worker')
            ->assertDontSee('Worker status unavailable')->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)
            ->assertDontSee('action="'.route('current.native.control').'"', false);
        Gate::define('viewCurrent', fn ($user) => false);
        $this->get('/current')->assertForbidden();
        config()->set('current.native.registry.enabled', false);
        $this->assertFalse(app(Registry::class)->all()['available']);
    }
}
