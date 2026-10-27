<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\Registry;
use Current\Native\Supervisor;
use Current\Native\WaitMonitor;
use Current\Native\Workload;
use Illuminate\Console\Command;
use Throwable;

final class MonitorCommand extends Command
{
    protected $signature = 'current:monitor';
    protected $description = 'Emit configured native queue-wait notification events.';

    public function handle(WaitMonitor $monitor): int
    {
        if (! ExecutionBackend::native() || ! config('current.enabled', true)) { return self::SUCCESS; }
        try {
            if (! $monitor->thresholds()) { return self::SUCCESS; }
            $workload = app(Workload::class)->snapshot(app(Supervisor::class)->snapshot(), app(Registry::class)->all());
            $sent = $monitor->inspect($workload);
            $this->info("Emitted {$sent} queue-wait events.");
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Native queue waits could not be inspected. Check thresholds and observation storage.');
            return self::FAILURE;
        }
    }
}
