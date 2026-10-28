<?php

namespace Current\Tests;

use Current\Native\LightweightLauncher;
use Current\Native\Plan;
use Current\Native\RuntimeSnapshot;
use Illuminate\Console\Events\CommandFinished;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Process\Process;

final class NativeLightweightLauncherTest extends TestCase
{
    private string $directory;
    private string $runtimeFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/current-controller-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        $this->runtimeFile = tempnam($this->directory, 'config-');
        config()->set('current.execution_backend', 'native');
        config()->set('queue.connections.redis.driver', 'current-redis');
        config()->set('current.native', ['state_directory' => $this->directory, 'instance' => 'test', 'grace_seconds' => 10,
            'pools' => ['test' => ['connection' => 'redis', 'queues' => ['lens-demo'], 'workers' => 0, 'min_workers' => 0,
                'max_workers' => 2, 'timeout' => 2, 'balance' => 'size']]]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) { unlink($file); }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function request(): ?array
    {
        return (new LightweightLauncher)->configuration(['state_directory' => $this->directory, 'memory_limit_mb' => 256, 'grace_seconds' => 10], $this->runtimeFile);
    }

    private function controller(): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__).'/src/Native/controller.php'], base_path());
    }

    public function test_transfer_preserves_resolved_reader_and_raw_history_identity(): void
    {
        $request = $this->request();
        $this->assertIsArray($request);
        $this->assertSame(app(Plan::class)->workerPools(), $request['pools']);
        $this->assertSame(app(RuntimeSnapshot::class)->readerSource(), $request['reader']);
        $this->assertSame(config('database.connections.'.config('current.connection')), $request['configuration']['database']['connections'][config('current.connection')]);
        $this->assertSame(app()->environment(), $request['configuration']['current']['native']['environment']);
        $this->assertSame($request, json_decode(LightweightLauncher::encode($request), true));
    }

    public function test_preflight_does_not_load_application_or_recorder_or_start_workers(): void
    {
        $request = $this->request(); $this->assertIsArray($request);
        $marker = $this->directory.'/audit'; $autoload = $this->directory.'/autoload.php';
        file_put_contents($autoload, '<?php $loader = require '.var_export($request['autoload'], true).'; register_shutdown_function(function () { file_put_contents('.var_export($marker, true).', json_encode([class_exists(\\Illuminate\\Foundation\\Application::class, false), class_exists(\\Current\\Recorder::class, false)])); }); return $loader;');
        $request['autoload'] = $autoload;
        $process = $this->controller(); $process->setInput(LightweightLauncher::encode($request))->setTimeout(5)->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame("ready\n", $process->getOutput());
        $this->assertSame([false, false], json_decode(file_get_contents($marker), true));
        $this->assertFileDoesNotExist($this->directory.'/test.json');
        $this->assertFileExists($this->runtimeFile);
    }

    public function test_programmatic_command_retains_laravel(): void
    {
        $this->assertNull((new LightweightLauncher)->prepare(new ArrayInput([]), [], ''));
    }

    public function test_custom_redis_creator_retains_laravel(): void
    {
        $this->assertIsArray($this->request());
        app('redis')->extend('custom', fn () => null);
        $this->assertNull($this->request());
    }

    public function test_custom_history_grammar_retains_laravel(): void
    {
        $connection = $this->store()->connection();
        $connection->setQueryGrammar(new class($connection) extends \Illuminate\Database\Query\Grammars\SQLiteGrammar {});
        $this->assertNull($this->request());
    }

    public function test_termination_hooks_retain_laravel(): void
    {
        $this->assertIsArray($this->request());
        app()->terminating(fn () => null);
        $this->assertNull($this->request());
    }

    public function test_kernel_lifecycle_hooks_retain_laravel(): void
    {
        app(\Illuminate\Contracts\Console\Kernel::class)->whenCommandLifecycleIsLongerThan(1, fn () => null);
        $this->assertNull($this->request());
    }

    public function test_terminating_event_listeners_retain_laravel(): void
    {
        app('events')->listen(\Illuminate\Foundation\Events\Terminating::class, fn () => null);
        $this->assertNull($this->request());
    }

    public function test_pending_deferred_work_retains_laravel(): void
    {
        $class = \Illuminate\Support\Defer\DeferredCallbackCollection::class;
        if (! class_exists($class)) { $this->markTestSkipped('Laravel version has no deferred callbacks.'); }
        \Illuminate\Support\defer(fn () => null);
        $this->assertNull($this->request());
    }

    public function test_wildcard_listeners_retain_laravel(): void
    {
        app('events')->listen('Illuminate\\Console\\Events\\*', fn () => null);
        $this->assertNull($this->request());
    }

    public function test_command_finished_hooks_retain_laravel(): void
    {
        app('events')->listen(CommandFinished::class, fn () => null);
        $this->assertNull($this->request());
    }

    public function test_nontransferable_configuration_retains_laravel(): void
    {
        config()->set('current.native.custom', fn () => null);
        $this->assertNull($this->request());
        foreach ([['value' => new \stdClass], ['value' => NAN], ['value' => str_repeat('x', LightweightLauncher::MAX_BYTES)]] as $request) {
            try { LightweightLauncher::encode($request); $this->fail('Unsafe transfer accepted.'); }
            catch (\RuntimeException | \JsonException) { $this->assertTrue(true); }
        }
    }

    public function test_probe_rejects_invalid_or_mismatched_input_without_leaking_it(): void
    {
        $request = $this->request(); $this->assertIsArray($request);
        $request['pools'][0]['workers'] = 1;
        foreach (['{private-secret', str_repeat('x', 262145), LightweightLauncher::encode($request)] as $input) {
            $process = $this->controller(); $process->setInput($input)->setTimeout(5)->run();
            $this->assertSame(1, $process->getExitCode());
            $this->assertSame('', $process->getOutput().$process->getErrorOutput());
        }
    }

    public function test_exec_keeps_pid_and_dotenv_environment_without_exposing_values_in_arguments(): void
    {
        if (! function_exists('pcntl_exec')) { $this->markTestSkipped('Requires Linux pcntl.'); }
        $request = $this->request(); $this->assertIsArray($request);
        $request['pools'][0]['workers'] = 1; // Stop after transfer, before any real worker starts.
        $marker = $this->directory.'/exec-audit'; $autoload = $this->directory.'/exec-autoload.php';
        file_put_contents($autoload, '<?php file_put_contents('.var_export($marker, true).', json_encode([getmypid(), getenv("CURRENT_HANDOFF_TEST"), file_get_contents("/proc/self/cmdline")])); return require '.var_export($request['autoload'], true).';');
        $script = $this->directory.'/replace.php';
        file_put_contents($script, '<?php $loader = require '.var_export($request['autoload'], true).'; $loader->addPsr4('.var_export('Current\\', true).', '.var_export(dirname(__DIR__).'/src/', true).'); $_ENV["CURRENT_HANDOFF_TEST"]="private-env-value"; (new \Current\Native\LightweightLauncher)->replace(json_decode(stream_get_contents(STDIN), true)); exit(99);');
        $request['autoload'] = $autoload;
        $process = new Process([PHP_BINARY, $script]);
        $process->setInput(LightweightLauncher::encode($request))->setTimeout(5)->start();
        $pid = $process->getPid(); $process->wait();
        $this->assertSame(1, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileExists($marker);
        [$after, $environment, $arguments] = json_decode(file_get_contents($marker), true);
        $this->assertSame($pid, $after);
        $this->assertSame('private-env-value', $environment);
        $this->assertStringNotContainsString('private-env-value', $arguments);
        $this->assertSame([], glob($this->directory.'/controller-*'));
    }

    public function test_exec_input_requires_owner_only_regular_file_and_consumes_valid_file(): void
    {
        $path = $this->directory.'/controller-test';
        $request = $this->request(); $this->assertIsArray($request);
        $request['pools'][0]['workers'] = 1; // Fail closed before starting Go after secure consumption.
        file_put_contents($path, LightweightLauncher::encode($request)); chmod($path, 0644);
        $process = new Process([PHP_BINARY, dirname(__DIR__).'/src/Native/controller.php', $path]);
        $process->run();
        $this->assertSame(1, $process->getExitCode()); $this->assertFileExists($path);
        $this->assertSame('', $process->getOutput().$process->getErrorOutput());
        $link = $this->directory.'/controller-link'; symlink($path, $link);
        $linked = new Process([PHP_BINARY, dirname(__DIR__).'/src/Native/controller.php', $link]); $linked->run();
        $this->assertSame(1, $linked->getExitCode()); $this->assertFileExists($path); unlink($link);
        chmod($path, 0600); $process->run();
        $this->assertSame(1, $process->getExitCode()); $this->assertFileDoesNotExist($path);
        $this->assertStringNotContainsString('private', $process->getErrorOutput());
    }
}
