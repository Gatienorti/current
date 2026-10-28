<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\Plan;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class SuperviseCommand extends Command
{
    protected $signature = 'current:supervise {--environment= : Select the provisioning environment without changing application environment} {--instance= : Override the configured unique instance name} {--check : Validate and print worker configuration without launching} {--status : Read process health} {--control= : pause, resume, restart or stop} {--pool= : Named pool for control}';
    protected $description = 'Run the opt-in native Current worker supervisor.';

    public function handle(Plan $plan): int
    {
        $process = null; $file = null; $sampler = null; $memoryExceeded = false;
        $originalInstance = config('current.native.instance'); $originalEnvironment = config('current.native.environment');
        try {
            if (! ExecutionBackend::native()) { throw new \RuntimeException('Select CURRENT_EXECUTION_BACKEND=native explicitly first.'); }
            if ($this->option('status') && $this->option('control') !== null) { throw new \RuntimeException('Choose status or control.'); }
            if ($this->option('status')) {
                return $this->call('current:status', array_filter(['--instance' => $this->option('instance'), '--json' => true], fn ($value) => $value !== null));
            }
            if ($this->option('control') !== null) {
                return $this->call('current:control', array_filter(['action' => $this->option('control'), '--instance' => $this->option('instance'), '--pool' => $this->option('pool')], fn ($value) => $value !== null));
            }
            if ($instance = $this->option('instance')) { config()->set('current.native.instance', $instance); }
            if ($this->option('environment') !== null) { config()->set('current.native.environment', $this->option('environment')); }
            $config = $plan->runtime($this->option('env'));
            if ($this->option('check')) { $this->line(json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return self::SUCCESS; }
            $used = (int) ceil(memory_get_usage(true) / 1048576);
            if ($used >= $config['memory_limit_mb']) {
                throw new \RuntimeException('Native launcher already allocated '.$used.' MiB at boot, above its '.$config['memory_limit_mb'].' MiB budget. Increase current.native.memory_limit (CURRENT_NATIVE_MEMORY_LIMIT in the default config) with headroom; worker memory is configured separately.');
            }
            $binary = config('current.native.binary');
            if (! is_string($binary) || ! str_starts_with($binary, '/') || ! is_executable($binary)) {
                throw new \RuntimeException('Build/install the native Linux supervisor at current.native.binary first.');
            }
            $directory = $config['state_directory'];
            if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true)) || (fileperms($directory) & 0077)) {
                throw new \RuntimeException('Native state directory must be private (0700) and not a symlink.');
            }
            $file = tempnam($directory, 'config-');
            chmod($file, 0600);
            file_put_contents($file, json_encode($config, JSON_THROW_ON_ERROR));
            $lightweight = new \Current\Native\LightweightLauncher;
            if ($request = $lightweight->prepare($this->input, $config, $file)) {
                $lightweight->replace($request);
            }
            $command = [$binary, '-config', $file, '-parent-pid', (string) getmypid()];
            if ($this->option('status')) { $command[] = '-status'; }
            if ($action = $this->option('control')) {
                if (! in_array($action, ['pause', 'resume', 'restart', 'stop'], true)) { throw new \RuntimeException('Unknown control action.'); }
                array_push($command, '-control', $action);
                if ($pool = $this->option('pool')) { array_push($command, '-pool', $pool); }
            }
            $process = new Process($command, base_path()); $process->setTimeout(null);
            $this->trap([SIGTERM, SIGINT], function () use (&$process) { if ($process?->isRunning()) { $process->signal(SIGTERM); } });
            $process->start();
            if ($this->option('status') || $this->option('control')) {
                return $process->wait(fn ($type, $buffer) => $this->output->write($buffer));
            }
            $sampler = new \Current\Native\RuntimeSampler;
            $pools = $plan->workerPools();
            $autoscaler = app(\Current\Native\Autoscaler::class);
            $registry = app(\Current\Native\Registry::class);
            while ($process->isRunning()) {
                if (! $memoryExceeded && memory_get_usage(true) > $config['memory_limit_mb'] * 1048576) {
                    $this->warn('Native launcher exceeded current.native.memory_limit ('.$config['memory_limit_mb'].' MiB); draining workers before process-manager restart. Increase the launcher budget if the application boot needs more headroom; worker memory is separate.');
                    $memoryExceeded = true;
                    $process->signal(SIGTERM);
                    $sampler->stop();
                }
                $this->forwardOutput($process);
                if (! $memoryExceeded) { $sampler->tick($pools); $autoscaler->tick(); }
                $registry->tick();
                usleep(200000);
            }
            $registry->tick(final: true);
            $this->forwardOutput($process);
            return $memoryExceeded ? self::FAILURE : ($process->getExitCode() ?? self::FAILURE);
        } catch (Throwable $e) {
            $this->error($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? $e->getMessage() : 'Native supervisor could not start.');
            return self::FAILURE;
        } finally {
            $sampler?->stop();
            config()->set('current.native.instance', $originalInstance);
            config()->set('current.native.environment', $originalEnvironment);
            if ($process?->isRunning()) { $process->stop((float) config('current.native.grace_seconds', 90) + 2); }
            if ($file && is_file($file)) { unlink($file); }
        }
    }

    private function forwardOutput(Process $process): void
    {
        $this->output->write($process->getIncrementalOutput());
        $process->clearOutput();
        $this->output->write($process->getIncrementalErrorOutput());
        $process->clearErrorOutput();
    }
}
