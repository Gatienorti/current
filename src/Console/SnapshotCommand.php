<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\Metrics;
use Illuminate\Console\Command;
use Throwable;

final class SnapshotCommand extends Command
{
    protected $signature = 'current:snapshot';
    protected $description = 'Snapshot captured native job and queue metrics in five-minute windows.';

    public function handle(Metrics $metrics): int
    {
        if (! ExecutionBackend::native() || ! config('current.enabled', true) || ! config('current.native.metrics.enabled', true)) {
            $this->info('Native metric snapshots are disabled.'); return self::SUCCESS;
        }
        try {
            $count = $metrics->snapshot();
            $this->info("Updated {$count} job/queue metric groups across three closed windows.");
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Native metrics could not be snapshotted. Check Current migrations, history storage, retention settings and the 1,000-group limit.');
            return self::FAILURE;
        }
    }
}
