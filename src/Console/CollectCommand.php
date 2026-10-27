<?php

namespace Current\Console;

use Current\Telemetry\Transport;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class CollectCommand extends Command
{
    protected $signature = 'current:collect {--once : Drain currently available events and exit}';

    protected $description = 'Run the opt-in Go telemetry collector (SQLite/MySQL/MariaDB, standalone Redis).';

    public function handle(Transport $transport): int
    {
        $process = null;
        try {
            if (config('current.telemetry.driver') !== 'redis') {
                throw new \RuntimeException('Set CURRENT_TELEMETRY_DRIVER=redis to run this collector.');
            }
            $settings = $transport->settings();
            $settings['once'] = (bool) $this->option('once');
            $binary = config('current.telemetry.binary');
            if (! is_executable($binary)) {
                throw new \RuntimeException('Build/install the collector binary at current.telemetry.binary first.');
            }
            $probe = new Process([$binary, '--version']);
            $probe->setTimeout(3);
            $probe->run();
            $capabilities = json_decode($probe->getOutput(), true);
            if (! $probe->isSuccessful() || ($capabilities['identity_protocol'] ?? 0) !== 2 || ($capabilities['maintenance_protocol'] ?? 0) !== 1) {
                throw new \RuntimeException('Upgrade the collector binary for telemetry protocol 2 and maintenance support before starting it.');
            }
            // Replace this CLI process, retaining its PID for the service manager.
            // The Go process obtains settings from a short-lived PHP child over a
            // bounded private pipe: no credentials in argv, environment or files.
            // Programmatic calls, explicit environment overrides and non-bootstrap binaries
            // retain the stdin path so their already-booted configuration is preserved.
            if (PHP_OS_FAMILY === 'Linux' && function_exists('pcntl_exec')
                && $this->option('env') === null
                && $this->input instanceof \Symfony\Component\Console\Input\ArgvInput) {
                if ($probe->isSuccessful() && ($capabilities['laravel_bootstrap'] ?? 0) === 1) {
                    $arguments = ['--laravel', PHP_BINARY, base_path('artisan')];
                    if ($this->option('once')) { $arguments[] = '--once'; }
                    pcntl_exec($binary, $arguments);
                    throw new \RuntimeException('Could not replace PHP with the collector process.');
                }
            }
            $process = new Process([$binary, '-']);
            $process->setInput(json_encode($settings, JSON_THROW_ON_ERROR));
            $process->setTimeout(null);
            if (function_exists('pcntl_signal')) {
                $this->trap([SIGTERM, SIGINT], function () use (&$process) {
                    if ($process?->isRunning()) {
                        $process->signal(SIGTERM);
                    }
                });
            }
            $process->start();

            return $process->wait(function ($type, $buffer) {
                $this->output->write($buffer);
            });
        } catch (Throwable $e) {
            // Configuration errors contain no credentials; Redis/SQL errors can.
            $this->error($e instanceof \RuntimeException && ! ($e instanceof \RedisException)
                ? $e->getMessage() : 'Collector could not start; check configuration and service availability.');

            return self::FAILURE;
        } finally {
            if ($process?->isRunning()) {
                $process->stop(5);
            }
        }
    }
}
