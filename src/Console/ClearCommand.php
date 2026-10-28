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

    protected $signature = 'current:clear {connection? : Native queue connection} {--queue= : Queue (defaults to the connection queue)} {--including-reserved : Also remove the snapshot of Redis reservations, matching Horizon clear} {--resume= : Resume an operation without clearing later dispatches} {--max-chunks= : Pause after this many bounded chunks} {--force : Allow production queue clearing}';

    protected $description = 'Clear a point-in-time queue snapshot in resumable chunks; preserve running-attempt evidence.';

    public function handle(ClearQueue $clear): int
    {
        if (! ExecutionBackend::native()) {
            $this->error('This command requires the native execution backend.');

            return self::FAILURE;
        }
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        $operation = $this->option('resume') ?? (string) Str::ulid();
        $this->line('Operation '.$operation);
        try {
            $connection = $this->argument('connection') ?? config('queue.default');
            $queue = $this->option('queue') ?? config('queue.connections.'.$connection.'.queue', 'default');
            $limit = $this->option('max-chunks');
            if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
                throw new \RuntimeException('Choose a positive chunk count.');
            }
            $record = $clear->run((string) $connection, (string) $queue, $operation, $this->option('resume') !== null, (bool) $this->option('including-reserved'), (int) ($limit ?? 0));
            $this->info('Removed snapshot: '.$record['removed_waiting'].' waiting/delayed entries and '.$record['removed_reserved'].' reservations; '.$record['processed'].' entries reconciled.');
            if ($record['untracked'] > 0 || ($record['unknown_reserved'] ?? false)) {
                $this->warn('Some payload identities were unavailable; their history outcome remains unknown.');
            }
            if (! $record['complete']) {
                $this->warn('Operation paused. Resume with --resume='.$operation.' and the same connection, queue and reserved mode.');

                return 2;
            }
            $this->line('Captured attempts remain intact. Removing a reservation does not stop an already running handler.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Clear/history outcome unconfirmed. Retain this operation ID and resume with the same scope; expired or missing receipts never clear new work.');

            return self::FAILURE;
        }
    }
}
