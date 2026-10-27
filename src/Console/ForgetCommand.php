<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\Maintenance;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;

final class ForgetCommand extends Command
{
    use ConfirmableTrait;
    protected $signature = 'current:forget {id : Current history ID of one native terminal failure} {--history-only : Preserve Laravel failed payload storage} {--force : Allow production removal}';
    protected $description = 'Remove one terminal failed dispatch and its captures, with a bounded replay marker.';
    public function handle(Maintenance $maintenance): int
    {
        if (! ExecutionBackend::native()) { $this->error('This command requires the native execution backend.'); return self::FAILURE; }
        if (! $this->confirmToProceed()) { return self::FAILURE; }
        try {
            $id = $this->argument('id');
            if (! is_string($id) || ! preg_match('/^[A-Z0-9]{26}$/D', $id)) { throw new \Current\Native\MaintenanceException('Choose one Current history ID.'); }
            $maintenance->forget($id, (bool) $this->option('history-only'));
            $this->info($this->option('history-only') ? 'Failed history removed; Laravel failed storage retained.' : 'Failed history and its matching Laravel failed payload removed.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e instanceof \Current\Native\MaintenanceException ? $e->getMessage() : 'Removal outcome unconfirmed; inspect Current and Laravel failed storage.');
            return self::FAILURE;
        }
    }
}
