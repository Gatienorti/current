<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\BulkForget;
use Current\Native\FailedPayloads;
use Current\Native\Maintenance;
use Current\Native\MaintenanceException;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Str;
use Throwable;

final class ForgetCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'current:forget {id? : Current history ID or Laravel failed-job ID} {--all : Forget all failures in a point-in-time native scope} {--failed-id : Interpret id as Laravel failed storage ID} {--history-only : Preserve Laravel failed payload storage} {--allow-nonatomic-provider : Allow a custom find/forget provider; a concurrent replacement race remains} {--resume= : Resume a bulk operation ID} {--max-chunks= : Pause bulk work after this many100-item pages} {--force : Allow production removal}';

    protected $description = 'Forget failed jobs or all retained failures with durable replay protection and resumable paging.';

    public function handle(Maintenance $maintenance): int
    {
        if (! ExecutionBackend::native()) {
            $this->error('This command requires the native execution backend.');

            return self::FAILURE;
        }
        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }
        if ($this->option('allow-nonatomic-provider')) {
            $this->warn('NON-ATOMIC PROVIDER OPT-IN: find then forget can race with a concurrent replacement. No compare-and-delete guarantee. Bulk scope includes captured failures only; ambiguous actions are never automatically resent.');
        }
        try {
            if ($this->option('all') || $this->option('resume') !== null) {
                if ($this->argument('id') !== null || $this->option('failed-id')) {
                    throw new MaintenanceException('Choose either one failure or --all.');
                }
                $limit = $this->option('max-chunks');
                if ($limit !== null && (! ctype_digit((string) $limit) || (int) $limit < 1)) {
                    throw new MaintenanceException('Choose a positive chunk count.');
                }
                $id = $this->option('resume') ?? (string) Str::ulid();
                $this->line('Operation '.$id);
                $result = app(BulkForget::class)->run($id, $this->option('resume') !== null, (bool) $this->option('history-only'), (int) ($limit ?? 0), (bool) $this->option('allow-nonatomic-provider'));
                $this->info('Confirmed removals: '.$result['histories'].' captured histories, '.$result['payloads'].' Laravel failed payloads. '.$result['blocked'].' unavailable or unconfirmed actions retained for review.');
                if ($result['untracked']) {
                    $this->warn($result['untracked'].' payloads had no usable job identity; no historical relationship was invented.');
                }
                if (! $result['complete']) {
                    $this->warn('Paused. Continue with --all --resume='.$id.' and the original history-only scope.');

                    return 2;
                }

                return $result['blocked'] ? self::FAILURE : self::SUCCESS;
            }
            $id = $this->argument('id');
            if (! is_string($id) || $id === '' || strlen($id) > 100) {
                throw new MaintenanceException('Choose a Current history ID, Laravel failed ID, or --all.');
            }
            if (! $this->option('failed-id') && preg_match('/^[A-Z0-9]{26}$/D', $id)) {
                $maintenance->forget($id, (bool) $this->option('history-only'), (bool) $this->option('allow-nonatomic-provider'));
            } else {
                if ($this->option('history-only')) {
                    throw new MaintenanceException('History-only removal needs a Current history ID.');
                }
                $failed = FailedPayloads::findById(app('queue.failer'), $id);
                if (! is_object($failed)) {
                    throw new MaintenanceException('Laravel no longer retains that failed ID.');
                }
                $maintenance->forgetFailed($failed, (bool) $this->option('allow-nonatomic-provider'));
            }
            $this->info($this->option('history-only') ? 'Failed history removed; Laravel failed storage retained.' : 'Failed history and its matching Laravel failed payload removed.');

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof MaintenanceException ? $error->getMessage() : 'Removal outcome unconfirmed; retain the operation ID and inspect Current and Laravel failed storage.');

            return self::FAILURE;
        }
    }
}
