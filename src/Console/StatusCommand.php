<?php

namespace Current\Console;

use Current\ExecutionBackend;
use Current\Native\LocalInstances;
use Current\Native\Registry;
use Current\Native\Supervisor;
use Illuminate\Console\Command;
use Throwable;

final class StatusCommand extends Command
{
    protected $signature = 'current:status {--instance= : One local instance} {--all : All application-local instances} {--registry : Shared host and supervisor inventory} {--json : Machine-readable status}';
    protected $description = 'Inspect existing native supervisors without validating new worker settings.';

    public function handle(LocalInstances $instances, Supervisor $supervisor, Registry $registry): int
    {
        $original = config('current.native.instance');
        try {
            if (! ExecutionBackend::native()) { throw new \RuntimeException('Select the native execution backend first.'); }
            $rows = []; $ok = true;
            if ($this->option('registry')) {
                if ($this->option('all') || $this->option('instance') !== null) { throw new \RuntimeException('Choose shared registry or local instances.'); }
                $snapshot = $registry->all(); $rows = $snapshot['instances'];
                $ok = $snapshot['available'] && $snapshot['invalid'] === 0;
                foreach ($rows as $row) { $ok = $ok && ! in_array($row['state'], ['stale', 'unknown', 'stopped', 'failed', 'unavailable'], true); }
            } else {
                foreach ($instances->names($this->option('all'), $this->option('instance')) as $name) {
                    config()->set('current.native.instance', $name);
                    $health = $supervisor->snapshot(); unset($health['owner']);
                    $rows[] = ['instance' => $name] + $health;
                    $ok = $ok && $health['available'] && ! in_array($health['state'], ['stale', 'unknown', 'stopped', 'failed', 'unavailable'], true);
                }
            }
            if ($this->option('json')) { $this->line(json_encode(['available' => $ok && (bool) $rows, 'instances' => $rows], JSON_THROW_ON_ERROR)); }
            elseif (! $rows) { $this->line('No native supervisor records found.'); }
            else {
                $table = [];
                foreach ($rows as $row) {
                    foreach ($row['pools'] ?: [[]] as $pool) {
                        $table[] = [$row['host'] ?? 'local', $row['instance'], $row['state'], $pool['name'] ?? '—',
                            $pool['state'] ?? 'Unknown', $pool['running'] ?? 'Unknown', isset($pool['min'], $pool['max']) ? $pool['min'].'–'.$pool['max'] : '—', implode(', ', $pool['queues'] ?? [])];
                    }
                }
                $this->table(['Host', 'Instance', 'Status', 'Pool', 'Pool status', 'Processes', 'Range', 'Queues'], $table);
            }
            return $ok && $rows ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) { $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Native status unavailable.'); return self::FAILURE; }
        finally { config()->set('current.native.instance', $original); }
    }
}
