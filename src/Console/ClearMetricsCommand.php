<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\Metrics;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;

final class ClearMetricsCommand extends Command
{
    use ConfirmableTrait;
    protected $signature = 'current:clear-metrics {--force : Allow production metric reset}';
    protected $description = 'Reset native metric summaries/charts while retaining captured jobs and workload evidence.';
    public function handle(Metrics $metrics): int
    {
        if (! ExecutionBackend::native()) { $this->error('This command requires the native execution backend.'); return self::FAILURE; }
        if (! $this->confirmToProceed()) { return self::FAILURE; }
        try { $metrics->reset(); $this->info('Native metrics reset. Job history and workload observations retained.'); return self::SUCCESS; }
        catch (Throwable) { $this->error('Metric reset unconfirmed; inspect history storage.'); return self::FAILURE; }
    }
}
