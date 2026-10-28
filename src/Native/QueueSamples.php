<?php

namespace Current\Native;

use Current\Store;
use RuntimeException;
use Throwable;

/** Point-in-time estimates. Never reconstructs historical waits from today's load. */
final class QueueSamples
{
    public function observe(?array $local = null, ?array $registry = null): array
    {
        $local ??= app(Supervisor::class)->snapshot();
        $registry ??= app(Registry::class)->all();
        $instances = $registry['instances'];
        $uncertain = ($registry['invalid'] ?? 0) > 0 || (config('current.native.registry.enabled', true) && ! $registry['available']);
        if ($local['available']) {
            $instances = Registry::withoutLocal($instances, $local);
            $instances[] = $local;
        }
        $queues = [];
        foreach ($instances as $instance) {
            foreach ($instance['pools'] as $pool) {
                if ($pool['running'] === 0 && in_array($pool['state'], ['stopped', 'failed'], true)) { continue; }
                $connection = $pool['connection'] ?? ''; $routing = $pool['queues'] ?? [];
                if ($connection === '' || ! $routing) { $uncertain = true; continue; }
                foreach ($routing as $queue) {
                    $key = Metrics::key('queues', [$connection, $queue]);
                    if (! isset($queues[$key]) && count($queues) >= RuntimeSnapshot::MAX_PAIRS) { throw new RuntimeException('Queue sample group limit exceeded.'); }
                    $queues[$key] ??= ['connection' => $connection, 'queue' => $queue, 'workers' => 0, 'unknown' => false];
                    if ($pool['running'] === null || in_array($instance['state'], ['stale', 'unknown'], true) || $pool['state'] === 'unknown') { $queues[$key]['unknown'] = true; }
                    elseif (in_array($instance['state'], ['running', 'degraded', 'partial'], true) && in_array($pool['state'], ['running', 'degraded'], true)) {
                        // Each real worker pool contributes once per queue it consumes.
                        // Shared priority capacity is an estimate, never a dedicated allocation.
                        $consuming = array_key_exists('consuming', $pool) ? $pool['consuming'] : ($pool['state'] === 'running' ? $pool['running'] : null);
                        if ($consuming === null) { $queues[$key]['unknown'] = true; }
                        else { $queues[$key]['workers'] += $consuming; }
                    }
                }
            }
        }
        $load = new QueueLoad; $runtime = new RuntimeEstimate; $rows = [];
        foreach ($queues as $key => $queue) {
            $workers = $uncertain || $queue['unknown'] ? null : $queue['workers'];
            $ready = null; $wait = null;
            try {
                $counts = $load->counts($queue['connection'], [$queue['queue']]);
                $ready = array_sum($counts);
                if ($workers !== null) {
                    if ($ready === 0) { $wait = 0.0; }
                    elseif ($workers > 0) { $wait = $runtime->workSeconds($queue['connection'], $counts) / $workers; }
                }
            } catch (Throwable) { /* Missing queue/runtime evidence remains null. */ }
            $rows[] = ['metric_key' => $key, 'ready' => $ready, 'workers' => $workers, 'wait_seconds' => $wait];
        }
        return $rows;
    }

    public function series(array $dimensions, int $hours, int $now, float $reset): array
    {
        $end = intdiv($now, 300) * 300; $start = $end - $hours * 3600;
        // Include the latest open observation bucket; its timestamp is explicit.
        return app(Store::class)->table('native_queue_samples')->where('metric_key', Metrics::key('queues', $dimensions))
            ->where('bucket', '>=', $start)->where('bucket', '<=', $end)->where('observed_at', '>=', $reset)
            ->where('expires_at', '>', $now)->orderBy('bucket')->get()->map(fn ($row) => [
                'at' => (int) $row->bucket, 'observed_at' => (float) $row->observed_at,
                'ready' => $row->ready === null ? null : (int) $row->ready,
                'workers' => $row->workers === null ? null : (int) $row->workers,
                'wait' => $row->wait_seconds === null ? null : (float) $row->wait_seconds,
            ])->all();
    }
}
