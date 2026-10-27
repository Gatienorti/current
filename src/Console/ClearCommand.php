<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\ClearQueue;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Str;
use Throwable;

final class ClearCommand extends Command
{
    use ConfirmableTrait;
    protected $signature = 'current:clear {connection : Native queue connection} {--queue= : One explicit queue} {--resume= : Reconcile a previous operation ID without clearing new work} {--force : Allow production queue clearing}';
    protected $description = 'Clear bounded waiting/delayed native entries, preserve reservations and captured attempts.';
    public function handle(ClearQueue $clear): int
    {
        if (! ExecutionBackend::native()) { $this->error('This command requires the native execution backend.'); return self::FAILURE; }
        if (! $this->confirmToProceed()) { return self::FAILURE; }
        $operation = $this->option('resume') ?? (string) Str::ulid();
        $this->line('Operation '.$operation);
        try {
            $record = $clear->run((string) $this->argument('connection'), (string) $this->option('queue'), $operation, $this->option('resume') !== null);
            $this->info('Cleared '.$record['removed'].' waiting/delayed entries. Reservations and captured attempts were retained.');
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Clear/history outcome unconfirmed or outside supported bounds. Inspect the queue; retain this operation ID and use --resume to reconcile an existing receipt.');
            return self::FAILURE;
        }
    }
}
