<?php

namespace Current\Console;

use Current\Native\HorizonMigration;
use Illuminate\Console\Command;

final class MigrateHorizonCommand extends Command
{
    protected $signature = 'current:migrate-horizon {--environment= : Select a Horizon environment from the loaded configuration} {--json : Print a machine-readable preview}';
    protected $description = 'Preview a native Current configuration from Horizon without changing files or services.';

    public function handle(HorizonMigration $migration): int
    {
        $report = $migration->preview($this->option('environment'));
        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
        else {
            $this->info('Horizon → Current preview · no files, queues or services changed');
            $this->line('Environment: '.($report['environment'] ?? 'invalid').' · matched: '.($report['matched_environment'] ?? 'none'));
            foreach ($report['issues'] as $issue) { $this->line(strtoupper($issue['level']).': '.$issue['message']); }
            if ($report['valid']) {
                $this->line('Merge these settings into config/current.php; retain your other Current settings:');
                $this->line(var_export($report['config'], true));
                $this->table(['Queue connection', 'Current driver', 'Proposed driver'], array_map(fn ($name, $change) => [$name, $change['from'], $change['to']], array_keys($report['queue_changes']), array_values($report['queue_changes'])));
            }
            foreach ($report['steps'] as $i => $step) { $this->line(($i + 1).'. '.$step); }
        }
        return $report['valid'] ? self::SUCCESS : self::FAILURE;
    }
}
