<?php

namespace Current\Console;

use Current\Native\Readiness;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'current:doctor {--json : Print machine-readable readiness checks}';
    protected $description = 'Check native setup without editing configuration, dispatching jobs, migrating or controlling services.';

    public function handle(Readiness $readiness): int
    {
        $report = $readiness->inspect();
        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)); }
        else {
            $this->info('Current native setup checks');
            $this->table(['Check', 'Result', 'Action / scope'], array_map(fn ($check) => [$check['id'], strtoupper($check['status']), $check['message']], $report['checks']));
            $this->line($report['scope']);
            $this->line($report['ready'] ? 'Read checks passed. Review warnings and complete the manual cutover checks.' : 'Setup needs attention. Resolve errors and rerun in the worker runtime.');
        }
        return $report['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
