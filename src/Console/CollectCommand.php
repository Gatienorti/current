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
