<?php

namespace Current\Tests;

use Current\Native\Supervisor;
use Illuminate\Support\Facades\Gate;

final class NativeSupervisorTest extends TestCase
{
    private string $directory;
    private array $health;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/current-health-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config()->set('current.execution_backend', 'native');
        config()->set('current.native', ['state_directory' => $this->directory, 'instance' => 'example', 'controls' => false]);
        Gate::define('viewCurrent', fn ($user) => true);
        $this->health = ['version' => 1, 'instance' => 'example', 'owner' => str_repeat('a', 32), 'pid' => 123, 'state' => 'running',
            'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => [['name' => 'imports', 'state' => 'running', 'workers' => 1, 'min_workers' => 1, 'max_workers' => 70,
                'slots' => [['state' => 'running', 'pid' => 124, 'starts' => 1]]]]];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $path) { unlink($path); }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function writeHealth(): void
    {
        file_put_contents($this->directory.'/example.json', json_encode($this->health));
    }

    public function test_health_is_bounded_validated_and_never_invents_live_workers_when_stale(): void
    {
        $this->get('/current')->assertOk()->assertSee('Worker status unavailable');
        $this->writeHealth();
        $this->get('/current')->assertOk()->assertSee('imports')->assertSee('1–70')->assertDontSee('action="'.route('current.native.control').'"', false);
        $this->health['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 10); $this->writeHealth();
        $snapshot = app(Supervisor::class)->snapshot();
        $this->assertSame('stale', $snapshot['state']);
        $this->assertNull($snapshot['pools'][0]['running']);
        $this->get('/current')->assertSee('Stale')->assertSee('Unknown');
        $this->health['instance'] = 'different'; $this->writeHealth();
        $this->assertFalse(app(Supervisor::class)->snapshot()['available']);
        file_put_contents($this->directory.'/example.json', str_repeat('x', 1048577));
        $this->assertFalse(app(Supervisor::class)->snapshot()['available']);
        unlink($this->directory.'/example.json');
        file_put_contents($this->directory.'/target', json_encode($this->health));
        symlink($this->directory.'/target', $this->directory.'/example.json');
        $this->assertFalse(app(Supervisor::class)->snapshot()['available']);
    }

    public function test_controls_require_separate_permission_csrf_current_owner_and_fresh_health(): void
    {
        $this->writeHealth();
        $request = ['owner' => $this->health['owner'], 'action' => 'pause', 'pool' => 'imports'];
        $this->post('/current/native/control', $request)->assertStatus(419);
        $this->withSession(['_token' => 'native-csrf'])->withHeader('X-CSRF-TOKEN', 'native-csrf');
        $this->post('/current/native/control', $request)->assertForbidden();
        config()->set('current.native.controls', true);
        $this->post('/current/native/control', $request)->assertForbidden();
        Gate::define('operateCurrent', fn ($user) => true);
        $this->get('/current')->assertSee('action="'.route('current.native.control').'"', false);
        $this->post('/current/native/control', array_replace($request, ['owner' => str_repeat('b', 32)]))->assertStatus(409);
        $this->post('/current/native/control', array_replace($request, ['pool' => 'missing']))->assertStatus(422);
        $this->health['updated_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 10); $this->writeHealth();
        $this->post('/current/native/control', $request)->assertStatus(409);
        $this->health['updated_at'] = gmdate('Y-m-d\TH:i:s\Z'); $this->writeHealth();
        // Missing socket gives an unconfirmed outcome, never a false success.
        $this->post('/current/native/control', $request)->assertRedirect(route('current.dashboard'))
            ->assertSessionHas('current_control_notice', 'Control outcome unconfirmed. Refresh supervisor state before taking another action.');
    }
    public function test_authorized_restart_reaches_the_socket_and_reports_acceptance(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Local socket integration requires pcntl.'); }
        $this->writeHealth();
        config()->set('current.native.controls', true);
        Gate::define('operateCurrent', fn ($user) => true);
        $server = stream_socket_server('unix://'.$this->directory.'/example.sock');
        $this->assertNotFalse($server);
        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid);
        if ($pid === 0) {
            $connection = stream_socket_accept($server, 5);
            if (! $connection) { exit(2); }
            stream_set_timeout($connection, 2);
            $request = json_decode(fgets($connection, 4097), true);
            $accepted = $request === ['owner' => str_repeat('a', 32), 'action' => 'restart', 'pool' => 'imports'];
            fwrite($connection, json_encode(['accepted' => $accepted])."\n");
            fclose($connection); fclose($server);
            exit($accepted ? 0 : 3);
        }
        try {
            $this->withSession(['_token' => 'native-csrf'])->withHeader('X-CSRF-TOKEN', 'native-csrf');
            $this->post('/current/native/control', ['owner' => $this->health['owner'], 'action' => 'restart', 'pool' => 'imports'])
                ->assertRedirect(route('current.dashboard'))
                ->assertSessionHas('current_control_notice', 'Control accepted. Worker transitions may take time to complete.');
        } finally {
            pcntl_waitpid($pid, $status);
            fclose($server);
        }
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
    }

}
