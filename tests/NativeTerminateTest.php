<?php

namespace Current\Tests;

use Illuminate\Support\Facades\Artisan;

final class NativeTerminateTest extends TestCase
{
    public function test_shutdown_uses_existing_owner_even_when_new_configuration_and_binary_are_invalid(): void
    {
        if (! function_exists('pcntl_fork')) { $this->markTestSkipped('Requires local Unix sockets and pcntl.'); }
        $directory = sys_get_temp_dir().'/current-stop-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        config()->set('current.execution_backend', 'native');
        config()->set('current.native', ['instance' => 'broken-new-plan', 'state_directory' => $directory, 'pools' => [], 'binary' => '/missing/new/binary']);
        $pids = [];
        try {
            foreach (['first', 'second'] as $instance) {
                $health = ['version' => 1, 'instance' => $instance, 'owner' => str_repeat($instance === 'first' ? 'a' : 'b', 32),
                    'state' => 'running', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => [
                        ['name' => 'worker', 'state' => 'running', 'workers' => 1, 'min_workers' => 1, 'max_workers' => 1,
                            'slots' => [['state' => 'running', 'pid' => 123, 'starts' => 1]]]]];
                file_put_contents($directory.'/'.$instance.'.json', json_encode($health));
                $server = stream_socket_server('unix://'.$directory.'/'.$instance.'.sock');
                $this->assertIsResource($server);
                $pid = pcntl_fork(); $this->assertNotSame(-1, $pid);
                if ($pid === 0) {
                    $socket = stream_socket_accept($server, 5); if (! $socket) { exit(2); }
                    $request = json_decode(fgets($socket, 4097), true);
                    if ($request !== ['owner' => $health['owner'], 'action' => 'stop', 'pool' => '']) { exit(3); }
                    fwrite($socket, json_encode(['accepted' => true])."\n"); fclose($socket); fclose($server);
                    usleep(100000); $health['state'] = 'stopped'; $health['pools'][0]['state'] = 'stopped';
                    $health['pools'][0]['slots'][0]['state'] = 'stopped'; $health['pools'][0]['slots'][0]['pid'] = 0;
                    file_put_contents($directory.'/'.$instance.'.json', json_encode($health)); exit(0);
                }
                $pids[] = $pid; fclose($server);
            }
            $exit = Artisan::call('current:terminate', ['--all' => true, '--wait' => '2']);
            $output = Artisan::output();
            $this->assertSame(0, $exit, $output);
            $this->assertStringContainsString('shutdown confirmed', $output);
            $this->assertSame('broken-new-plan', config('current.native.instance'));
            foreach ($pids as $pid) { pcntl_waitpid($pid, $status); $this->assertSame(0, pcntl_wexitstatus($status)); }
            $pids = [];
        } finally {
            foreach ($pids as $pid) { posix_kill($pid, SIGKILL); pcntl_waitpid($pid, $status); }
            foreach (glob($directory.'/*') as $file) { unlink($file); } rmdir($directory);
        }
    }

    public function test_scope_validation_and_unconfirmed_records_do_not_claim_shutdown(): void
    {
        $directory = sys_get_temp_dir().'/current-stop-'.bin2hex(random_bytes(6)); mkdir($directory, 0700);
        config()->set('current.execution_backend', 'native');
        config()->set('current.native.state_directory', $directory);
        try {
            $this->assertSame(1, Artisan::call('current:terminate', ['--all' => true, '--instance' => 'first']));
            $this->assertSame(1, Artisan::call('current:terminate', ['--wait' => '86431']));
            $this->assertSame(0, Artisan::call('current:terminate', ['--all' => true, '--wait' => '3000']));
            file_put_contents($directory.'/first.json', '{broken');
            $this->assertSame(1, Artisan::call('current:terminate', ['--all' => true]));
            $this->assertStringContainsString('could not be confirmed', Artisan::output());
            unlink($directory.'/first.json');
            $this->assertSame(0, Artisan::call('current:terminate', ['--all' => true]));
            chmod($directory, 0755);
            // PHP 8.2 retains the prior stat result after an in-process chmod.
            // A real deployment command starts with a fresh filesystem cache.
            clearstatcache(true, $directory);
            $this->assertSame(1, Artisan::call('current:terminate', ['--all' => true]));
        } finally { chmod($directory, 0700); rmdir($directory); }
    }
}
