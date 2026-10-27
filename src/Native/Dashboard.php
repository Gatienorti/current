<?php

namespace Current\Native;

use Current\Store;
use Throwable;

/** Operational summaries of retained observations; never treats missing coverage as idle. */
final class Dashboard
{
    public function snapshot(array $local, array $registry, array $workload, ?float $now = null): array
    {
        $now ??= microtime(true);
        $counts = $this->read(function () use ($now) {
            $store = app(Store::class);
            $retained = $store->table('jobs')->where('expires_at', '>', $now);
            return ['recent' => (clone $retained)->where('first_observed_at', '>=', $now - 3600)->where('first_observed_at', '<=', $now)->count(),
                'failed' => (clone $retained)->where('observed_at', '>=', $now - 604800)->where('observed_at', '<=', $now)
                    ->whereRaw($store->outcomeSql()." = 'failed'")->count()];
        });
        $metrics = $this->read(function () use ($now) {
            $reset = app(Metrics::class)->resetAt();
            $since = max($now - 3600, $reset);
            $minute = max($now - 60, $reset);
            $groups = app(Store::class)->table('attempts as a')->join('lens_jobs as j', 'j.id', '=', 'a.job_id')
                ->where('j.expires_at', '>', $now)->where('a.status', 'completed')
                ->where('a.finished_at', '>=', $since)->where('a.finished_at', '<=', $now)
                ->select(['j.connection', 'j.queue'])
                ->selectRaw('COUNT(*) AS completions, SUM(CASE WHEN a.finished_at >= ? THEN 1 ELSE 0 END) AS minute_completions', [$minute])
                ->selectRaw('AVG(CASE WHEN a.started_at IS NOT NULL AND a.finished_at >= a.started_at THEN (a.finished_at - a.started_at) * 1000 END) AS runtime_ms')
                ->groupBy('j.connection', 'j.queue')->orderBy('j.connection')->orderBy('j.queue')->limit(1001)->get();
            if ($groups->count() > 1000) { throw new \RuntimeException('Dashboard metric group limit exceeded.'); }
            $runtime = $groups->filter(fn ($row) => $row->runtime_ms !== null)->sortByDesc('runtime_ms')->first();
            $throughput = $groups->sortByDesc('completions')->first();
            $dimension = fn ($row) => $row === null ? null : ['connection' => $row->connection, 'queue' => $row->queue];
            return ['rate' => $groups->sum('minute_completions') / max(1 / 60, ($now - $minute) / 60),
                'rate_seconds' => max(1, $now - $minute), 'since' => $since,
                'max_runtime' => $runtime ? $dimension($runtime) + ['runtime_ms' => (float) $runtime->runtime_ms] : null,
                'max_throughput' => $throughput ? $dimension($throughput) + ['completed' => (int) $throughput->completions] : null,
                'reset' => $reset > $now - 3600];
        });

        return ['counts' => $counts, 'metrics' => $metrics,
            'inventory' => $this->inventory($local, $registry), 'wait' => $this->wait($workload)];
    }

    private function inventory(array $local, array $registry): array
    {
        $enabled = config('current.native.registry.enabled', true) === true;
        $instances = $enabled ? ($registry['instances'] ?? []) : [];
        $partial = $enabled && (! ($registry['available'] ?? false) || ($registry['invalid'] ?? 0) > 0);
        if ($local['available'] ?? false) {
            $id = hash('sha256', (gethostname() ?: 'unknown-host')."\0".$local['instance']."\0".$local['owner']);
            $instances = array_values(array_filter($instances, fn ($instance) => $instance['id'] !== $id));
            $instances[] = $local;
        } elseif (! $enabled) { $partial = true; }
        $processes = 0; $allocated = 0; $paused = 0; $states = [];
        foreach ($instances as $instance) {
            $states[] = $instance['state'];
            $paused += $instance['state'] === 'paused' ? 1 : 0;
            if (in_array($instance['state'], ['stale', 'unknown', 'unavailable'], true)) { $partial = true; }
            foreach ($instance['pools'] as $pool) {
                if (! in_array($instance['state'], ['stopped', 'failed'], true) && ! in_array($pool['state'], ['stopped', 'failed'], true)) {
                    $allocated += $pool['workers'];
                }
                if ($pool['running'] === null) { $partial = true; }
                else { $processes += $pool['running']; }
            }
        }
        $state = match (true) {
            $partial => 'unknown',
            ! $states || count(array_diff($states, ['stopped'])) === 0 => 'inactive',
            count(array_diff($states, ['failed', 'stopped'])) === 0 => 'failed',
            count(array_diff($states, ['paused', 'stopped'])) === 0 => 'paused',
            (bool) array_intersect($states, ['failed', 'degraded', 'partial']) => 'degraded',
            in_array('stopping', $states, true) => 'stopping',
            in_array('starting', $states, true) => 'starting',
            in_array('pausing', $states, true) => 'pausing',
            default => 'running',
        };
        return ['available' => ! $partial, 'state' => $state, 'processes' => $partial ? null : $processes,
            'allocated' => $partial ? null : $allocated, 'instances' => count($instances), 'paused' => $paused,
            'scope' => $enabled ? 'reported instances' : 'local instance'];
    }

    private function wait(array $workload): array
    {
        if (! ($workload['available'] ?? false)) { return ['available' => false, 'value' => null, 'queue' => null, 'reason' => 'Queue workload unavailable.']; }
        if (($workload['partial'] ?? false) || collect($workload['rows'])->contains(fn ($row) => $row['wait'] === null)) {
            return ['available' => false, 'value' => null, 'queue' => null, 'reason' => 'Some queue waits are unknown; a complete maximum cannot be established.'];
        }
        $row = collect($workload['rows'])->sortByDesc('wait')->first();
        return ['available' => true, 'value' => $row['wait'] ?? null,
            'queue' => $row ? $row['connection'].' / '.implode(', ', $row['queues']) : null,
            'reason' => 'Estimated clearing time for reported queue routing.'];
    }

    private function read(callable $callback): array
    {
        try { return ['available' => true, 'data' => $callback()]; }
        catch (Throwable) { return ['available' => false, 'data' => null]; }
    }
}
