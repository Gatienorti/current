<?php

namespace Current\Native;

use Throwable;

/** Ready work and clearance estimates, scoped to explicitly reported queue routing. */
final class Workload
{
    public function snapshot(array $local, array $registry): array
    {
        $instances = $registry['instances'];
        $partial = ($registry['invalid'] ?? 0) > 0 || (config('current.native.registry.enabled', true) && ! $registry['available']);
        if ($local['available']) {
            $id = hash('sha256', (gethostname() ?: 'unknown-host')."\0".$local['instance']."\0".$local['owner']);
            $instances = array_values(array_filter($instances, fn ($instance) => $instance['id'] !== $id));
            $instances[] = $local;
        }
        $groups = [];
        foreach ($instances as $instance) {
            foreach ($instance['pools'] as $pool) {
                // Final zero-worker records remain useful inventory, but no
                // longer describe current routing or compete for queue work.
                if ($pool['running'] === 0 && (in_array($instance['state'], ['stopped', 'failed'], true) || in_array($pool['state'], ['stopped', 'failed'], true))) { continue; }
                $connection = $pool['connection'] ?? ''; $queues = $pool['queues'] ?? [];
                if ($connection === '' || ! $queues) { $partial = true; continue; }
                $key = json_encode([$connection, $queues], JSON_THROW_ON_ERROR);
                if (! isset($groups[$key]) && count($groups) >= 128) { $partial = true; continue; }
                $groups[$key] ??= ['connection' => $connection, 'queues' => $queues, 'running' => 0, 'consuming' => 0, 'uncertain' => false];
                if ($pool['running'] === null || in_array($instance['state'], ['stale', 'unknown'], true) || $pool['state'] === 'unknown') { $groups[$key]['uncertain'] = true; }
                else {
                    $groups[$key]['running'] += $pool['running'];
                    if (in_array($pool['state'], ['running', 'degraded'], true)
                        && ! in_array($instance['state'], ['starting', 'pausing', 'paused', 'stopping', 'stopped', 'failed'], true)) {
                        $groups[$key]['consuming'] += $pool['running'];
                    }
                }
            }
        }
        $queueGroups = [];
        foreach ($groups as $key => $group) {
            if (! $group['uncertain'] && $group['consuming'] === 0) { continue; }
            foreach ($group['queues'] as $queue) { $queueGroups[$group['connection']."\0".$queue][$key] = true; }
        }
        $load = new QueueLoad; $runtime = new RuntimeEstimate; $rows = [];
        foreach ($groups as $key => $group) {
            $ready = null; $wait = null; $reason = null;
            $overlap = false;
            foreach ($group['queues'] as $queue) { $overlap = $overlap || count($queueGroups[$group['connection']."\0".$queue] ?? []) > 1; }
            try {
                $counts = $load->counts($group['connection'], $group['queues']); $ready = array_sum($counts);
                if ($partial || $group['uncertain'] || $overlap) { $reason = 'Worker routing or inventory is incomplete or shared with another queue group.'; }
                elseif ($ready === 0) { $wait = 0.0; }
                elseif ($group['consuming'] === 0) { $reason = 'No confirmed consuming worker allocation.'; }
                else { $wait = $runtime->workSeconds($group['connection'], $counts) / $group['consuming']; }
            } catch (Throwable) { $reason = $ready === null ? 'Queue load unavailable.' : 'Recent captured runtime unavailable.'; }
            $rows[] = $group + ['ready' => $ready, 'wait' => $wait, 'reason' => $reason];
        }
        return ['rows' => $rows, 'partial' => $partial, 'available' => $local['available'] || $registry['available']];
    }
}
