<?php

namespace Current\Console;

use Current\Telemetry\Transport;
use Illuminate\Console\Command;
use Throwable;

/** Internal CLI pipe endpoint. Never register an HTTP equivalent. */
final class CollectorConfigCommand extends Command
{
    protected $signature = 'current:collector-config {--once}';
    protected $description = 'Internal collector configuration handoff (contains credentials).';
    protected $hidden = true;

    public function handle(Transport $transport): int
    {
        try {
            if (config('current.telemetry.driver') !== 'redis') {
                throw new \RuntimeException('Async telemetry is not enabled.');
            }
            $settings = $transport->settings();
            $settings['once'] = (bool) $this->option('once');
            $json = json_encode($settings, JSON_THROW_ON_ERROR);
            if (strlen($json) > 65536) { throw new \RuntimeException('Configuration exceeds handoff limit.'); }
            $this->output->write($json, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Collector configuration unavailable.');
            return self::FAILURE;
        }
    }
}
