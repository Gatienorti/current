<?php

namespace Current\Tests;

use Illuminate\Support\Facades\Artisan;

final class NativeStatusControlTest extends TestCase
{
    private function health(string $instance): array
    {
        return ['version' => 1, 'instance' => $instance, 'owner' => str_repeat('a', 32), 'state' => 'running', 'updated_at' => gmdate('c'),
            'pools' => [['name' => 'imports', 'state' => 'running', 'workers' => 1, 'min_workers' => 1, 'max_workers' => 70,
                'connection' => 'redis', 'queues' => ['imports'], 'slots' => [['state' => 'running', 'pid' => 123, 'starts' => 1]]]]];
    }

    public function test_status_and_owner_checked_control_do_not_require_a_new_plan_or_binary(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires Unix sockets and pcntl.'); }
        $directory = sys_get_temp_dir().'/current-status-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        config()->set('current.execution_backend', 'native');
        config()->set('current.native', ['state_directory' => $directory, 'instance' => 'configured', 'pools' => [], 'binary' => '/missing']);
        $children = [];
        try {
            foreach (['first', 'second'] as $name) {
                $health = $this->health($name);
                file_put_contents($directory.'/'.$name.'.json', json_encode($health));
                $server = stream_socket_server('unix://'.$directory.'/'.$name.'.sock');
                $this->assertIsResource($server);
                $pid = pcntl_fork(); $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    $socket = stream_socket_accept($server, 5); if (! $socket) { exit(2); }
                    $request = json_decode(fgets($socket, 4097), true);
                    if ($request !== ['owner' => $health['owner'], 'action' => 'pause', 'pool' => 'imports']) { exit(3); }
                    fwrite($socket, "{\"accepted\":true}\n"); fclose($socket); fclose($server); exit(0);
                }
                $children[] = $pid; fclose($server);
            }
            $this->assertSame(0, Artisan::call('current:status', ['--all' => true, '--json' => true]));
            $status = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertTrue($status['available']);
            $this->assertCount(2, $status['instances']);
            $this->assertArrayNotHasKey('owner', $status['instances'][0]);
            $this->assertSame(0, Artisan::call('current:supervise', ['--status' => true, '--instance' => 'first']));
            $this->assertSame('configured', config('current.native.instance'));
            $this->assertSame(0, Artisan::call('current:control', ['action' => 'pause', '--all' => true, '--pool' => 'imports']));
            $this->assertSame('configured', config('current.native.instance'));
            foreach ($children as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            $children = [];
            file_put_contents($directory.'/first.json', '{broken');
            $this->assertSame(1, Artisan::call('current:status', ['--all' => true]));
            $this->assertSame(1, Artisan::call('current:control', ['action' => 'resume', '--all' => true]));
        } finally {
            foreach ($children as $pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') as $path) { unlink($path); } rmdir($directory);
        }
    }

    public function test_status_and_controls_reject_unsafe_scope_without_creating_state(): void
    {
        config()->set('current.execution_backend', 'native');
        $directory = sys_get_temp_dir().'/current-missing-'.bin2hex(random_bytes(6));
        config()->set('current.native.state_directory', $directory);
        $this->assertSame(1, Artisan::call('current:status', ['--all' => true, '--instance' => 'first']));
        $this->assertSame(1, Artisan::call('current:status', ['--registry' => true, '--all' => true]));
        $this->assertSame(1, Artisan::call('current:status', ['--instance' => '../unsafe']));
        $this->assertSame(1, Artisan::call('current:control', ['action' => 'execute', '--all' => true]));
        $this->assertSame(1, Artisan::call('current:supervise', ['--status' => true, '--control' => 'pause']));
        $this->assertDirectoryDoesNotExist($directory);
    }
}
