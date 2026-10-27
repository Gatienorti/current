<?php

namespace Current\Tests;

use Current\Console\ListenCommand;
use Current\Console\PurgeCommand;
use Current\Native\Registry;
use Current\Native\Supervisor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Redis;

final class NativeDeveloperCommandsTest extends TestCase
{
    public function test_commands_require_explicit_native_selection_and_validate_scope(): void
    {
        Artisan::registerCommand(app(ListenCommand::class));
        Artisan::registerCommand(app(PurgeCommand::class));
        config()->set('current.execution_backend', 'horizon');
        $this->assertSame(1, Artisan::call('current:listen'));
        $this->assertSame(1, Artisan::call('current:purge'));
        config()->set('current.execution_backend', 'native');
        foreach (['0', '99', '60001', 'invalid'] as $interval) {
            $this->assertSame(1, Artisan::call('current:listen', ['--interval' => $interval]));
            $this->assertStringContainsString('watch interval', Artisan::output());
        }
        foreach ([['--all' => true, '--instance' => 'other'], ['--signal' => 'USR1'], ['--wait' => '301'], ['--wait' => '-1']] as $options) {
            $this->assertSame(1, Artisan::call('current:purge', $options));
            $this->assertStringContainsString('Choose one instance', Artisan::output());
        }
        config()->set('current.native.watch', ['../outside']);
        $this->assertSame(1, Artisan::call('current:listen'));
        $this->assertStringContainsString('relative files or directories', Artisan::output());
    }

    public function test_purge_uses_existing_state_with_invalid_new_plan_and_only_bounded_local_arguments(): void
    {
        Artisan::registerCommand(app(PurgeCommand::class));
        $directory = sys_get_temp_dir().'/current-purge-command-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        // A harmless helper fixture verifies the public command invokes the minimal
        // ownership helper rather than a new plan or a shell/discovery pipeline.
        $binary = $directory.'/helper';
        file_put_contents($binary, <<<'PHP'
#!/usr/bin/env php
<?php
if (array_slice($argv, 1) !== ['-purge', __DIR__, 'existing', 'KILL', '0']) { exit(2); }
echo json_encode(['active' => true, 'signaled' => 0, 'exited' => 0, 'unconfirmed' => 0]);
PHP);
        chmod($binary, 0700);
        file_put_contents($directory.'/existing.json', '{}');
        config()->set('current.execution_backend', 'native');
        config()->set('current.native', ['instance' => 'existing', 'state_directory' => $directory, 'binary' => $binary, 'pools' => []]);
        try {
            $exit = Artisan::call('current:purge', ['--signal' => 'KILL', '--wait' => '0']);
            $output = Artisan::output();
            $this->assertSame(0, $exit, $output);
            $this->assertStringContainsString('active owner retained', $output);
            file_put_contents($binary, "#!/usr/bin/env php\n<?php echo 'not a cleanup result'; exit(1);");
            $this->assertSame(1, Artisan::call('current:purge', ['--signal' => 'KILL', '--wait' => '0']));
            $this->assertStringContainsString('cleanup unconfirmed', Artisan::output());
            $this->assertSame('existing', config('current.native.instance'));
        } finally {
            foreach (glob($directory.'/*') as $file) { unlink($file); }
            rmdir($directory);
        }
    }

    public function test_private_worker_proof_is_excluded_from_snapshots_registry_and_dashboard(): void
    {
        $directory = sys_get_temp_dir().'/current-private-proof-'.bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        $token = 'private-worker-proof-sentinel';
        $health = ['version' => 1, 'instance' => 'proof', 'owner' => str_repeat('a', 32), 'pid' => 123,
            'state' => 'running', 'updated_at' => gmdate('Y-m-d\TH:i:s\Z'), 'pools' => [
                ['name' => 'imports', 'workers' => 1, 'min_workers' => 1, 'max_workers' => 1, 'slots' => [
                    ['state' => 'running', 'pid' => 124, 'starts' => 1,
                        'identity' => ['boot_id' => 'private-boot-sentinel', 'start_ticks' => '12345', 'uid' => 1000, 'token' => $token]],
                ]],
            ]];
        file_put_contents($directory.'/proof.json', json_encode($health));
        config()->set('current.execution_backend', 'native');
        config()->set('current.native.state_directory', $directory);
        config()->set('current.native.instance', 'proof');
        config()->set('current.native.registry.enabled', true);
        Gate::define('viewCurrent', fn () => true);
        try {
            $snapshot = app(Supervisor::class)->snapshot();
            $this->assertTrue($snapshot['available']);
            $this->assertArrayNotHasKey('slots', $snapshot['pools'][0]);
            $this->assertStringNotContainsString($token, json_encode($snapshot));
            $registry = new Registry;
            $registry->tick();
            $records = $registry->all();
            $this->assertTrue($records['available']);
            $this->assertCount(1, $records['instances']);
            $raw = Redis::get('current:native-registry:record:'.$records['instances'][0]['id']);
            $this->assertIsString($raw);
            foreach ([$token, 'private-boot-sentinel', '"identity"', '"start_ticks"', '"uid"', '"pid"'] as $secret) {
                $this->assertStringNotContainsString($secret, $raw);
            }
            $this->get('/current')->assertOk()->assertDontSee($token)->assertDontSee('private-boot-sentinel');
        } finally { unlink($directory.'/proof.json'); rmdir($directory); }
    }
}
