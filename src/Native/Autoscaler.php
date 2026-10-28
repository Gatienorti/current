<?php

namespace Current\Native;

use RuntimeException;
use Throwable;

/** Conservative per-pool size/time strategies. Unknown Redis data never scales workers down. */
final class Autoscaler
{
    private array $next = [];
    private float $nextTick = 0;
    private array $dueConnections = [];
    private array $dueFailed = [];
    private array $dueObserved = [];
    private float $dueDeadline = 0;

    public static function target(float $work, int $current, array $pool): int
    {
        $desired = max($pool['min_workers'], min($pool['max_workers'], (int) ceil($work / ($pool['balance'] === 'time' ? $pool['target_clear_seconds'] : $pool['jobs_per_worker']))));
        return max($current - $pool['balance_shift'], min($current + $pool['balance_shift'], $desired));
    }

    public function tick(): void
    {
        $now = microtime(true);
        if ($now < $this->nextTick) { return; }
        $this->nextTick = $now + 1;
        $this->dueConnections = $this->dueFailed = $this->dueObserved = [];
        $this->dueDeadline = $now + 1;
        try {
            $pools = app(Plan::class)->workerPools();
            if (! array_filter($pools, fn ($p) => ! in_array($p['balance'], ['fixed', 'simple'], true))) { return; }
            $supervisor = app(Supervisor::class);
            $health = $supervisor->snapshot();
            if (! $health['available'] || in_array($health['state'], ['stale', 'stopping', 'stopped', 'failed'], true)) { return; }
            $live = collect($health['pools'])->keyBy('name');
            $allocated = $live->sum('workers');
            $limit = config('current.native.worker_limit', 256);
            $load = new QueueLoad; $runtime = new RuntimeEstimate;
            $changes = []; $groups = []; $groupAllocation = []; $groupByPool = []; $groupLimits = [];
            foreach ($pools as $pool) {
                if (isset($pool['group'])) {
                    $groupByPool[$pool['name']] = $pool['group'];
                    $groupLimits[$pool['group']] = $pool['group_limit'];
                    $groupAllocation[$pool['group']] = ($groupAllocation[$pool['group']] ?? 0) + ($live->get($pool['name'])['workers'] ?? $pool['workers']);
                }
            }
            foreach ($pools as $pool) { if ($pool['balance'] === 'auto') { $groups[$pool['group']][] = $pool; } }
            foreach ($groups as $group => $members) {
                array_push($changes, ...$this->groupChanges($group, $members, $live, $now, $load, $runtime));
            }
            foreach ($pools as $pool) {
                $name = $pool['name']; $state = $live->get($name);
                if (in_array($pool['balance'], ['fixed', 'simple', 'auto'], true) || ! $state || in_array($state['state'], ['paused', 'pausing', 'unknown'], true) || ($this->next[$name] ?? 0) > $now) { continue; }
                $this->next[$name] = $now + $pool['balance_cooldown'];
                try {
                    $cold = $state['workers'] === 0;
                    $counts = $load->counts($pool['connection'], $pool['queues'], reserved: ! $cold);
                    $work = array_sum($counts);
                    if ($pool['min_workers'] === 0 && $state['workers'] <= 1 && $work === 0) {
                        $target = $this->dueCount($pool['connection'], $pool['queues']) > 0 ? 1 : self::target(0, $state['workers'], $pool);
                    } else {
                        try {
                            if ($pool['balance'] === 'time') { $work = $runtime->workSeconds($pool['connection'], $counts); }
                            $target = self::target($work, $state['workers'], $pool);
                            // A valid zero duration still needs a consumer for known work.
                            if (array_sum($counts) > 0) { $target = max(1, $target); }
                        } catch (Throwable $e) {
                            if (! $cold) { throw $e; }
                            $target = 1;
                        }
                    }
                    if ($target !== $state['workers']) { $changes[] = ['name' => $name, 'current' => $state['workers'], 'target' => $target]; }
                } catch (Throwable) {
                    // Keep the current allocation on an outage or uncertain control outcome.
                }
            }
            // Release desired allocation before requesting growth in another pool.
            // Go separately keeps draining processes within the live-worker budget.
            usort($changes, fn ($a, $b) => ($a['target'] - $a['current']) <=> ($b['target'] - $b['current']));
            foreach ($changes as $change) {
                $target = min($change['target'], $change['current'] + max(0, $limit - $allocated));
                $group = $groupByPool[$change['name']] ?? null;
                if ($group !== null) { $target = min($target, $change['current'] + max(0, $groupLimits[$group] - $groupAllocation[$group])); }
                if ($target === $change['current']) { continue; }
                try {
                    if ($supervisor->control($health['owner'], 'scale', $change['name'], $target)) {
                        $allocated += $target - $change['current'];
                        if ($group !== null) { $groupAllocation[$group] += $target - $change['current']; }
                    }
                } catch (Throwable) {
                    // A lost reply may have applied the control. Wait for fresh health
                    // before spending any possibly released capacity elsewhere.
                    break;
                }
            }
        } catch (Throwable) {
            // Supervisor execution must not depend on a successful load observation.
        }
    }

    private function groupChanges(string $group, array $members, $live, float $now, QueueLoad $load, RuntimeEstimate $runtime): array
    {
        if (($this->next['group:'.$group] ?? 0) > $now) { return []; }
        $this->next['group:'.$group] = $now + $members[0]['balance_cooldown'];
        try {
            $budget = $members[0]['group_limit']; $active = []; $weights = []; $counts = []; $probes = []; $dueConsumers = []; $unknown = false;
            foreach ($members as $pool) {
                $state = $live->get($pool['name']);
                if (! $state || $state['state'] === 'unknown') { return []; }
                if (in_array($state['state'], ['paused', 'pausing'], true)) { $budget -= $state['workers']; continue; }
                $count = $load->counts($pool['connection'], $pool['queues'])[$pool['queues'][0]];
                $counts[$pool['name']] = $count;
                if ($pool['min_workers'] === 0 && $state['workers'] <= 1 && $count === 0 && $this->dueCount($pool['connection'], $pool['queues']) > 0) { $dueConsumers[$pool['name']] = true; }
                if ($state['workers'] === 0 && ($count > 0 || isset($dueConsumers[$pool['name']]))) { $probes[$pool['name']] = true; }
                try {
                    $weights[$pool['name']] = match ($pool['auto_scale_strategy']) {
                        'size' => $count,
                        'log' => log1p($count),
                        'time' => $runtime->workSeconds($pool['connection'], [$pool['queues'][0] => $count]),
                    };
                } catch (Throwable) { $unknown = true; }
                $active[] = $pool;
            }
            $allocator = ($members[0]['balance_policy'] ?? 'horizon') === 'horizon' ? HorizonAllocation::class : Allocation::class;
            if ($unknown) {
                if (! $probes) { return []; }
                // Keep busy allocations whose duration is unknown. Confirmed idle
                // members may release capacity so one cold consumer can observe work.
                $targets = [];
                foreach ($active as $pool) {
                    $name = $pool['name']; $current = $live->get($name)['workers'];
                    $targets[$name] = isset($probes[$name]) ? max(1, $pool['min_workers'])
                        : ($counts[$name] === 0 ? $pool['min_workers'] : $current);
                    if (isset($dueConsumers[$name])) { $targets[$name] = max(1, $targets[$name]); }
                }
            } else {
                // The explicit bounded policy uses ready demand for the degenerate
                // measured-zero case, without recording a synthetic duration.
                if ($allocator === Allocation::class && (float) array_sum($weights) === 0.0 && array_sum($counts) > 0) { $weights = $counts; }
                $targets = $allocator === HorizonAllocation::class
                    ? $allocator::targets($active, $weights, $budget, $counts)
                    : $allocator::targets($active, $weights, $budget);
                foreach ($probes + $dueConsumers as $name => $_) { $targets[$name] = max(1, $targets[$name]); }
            }
            $changes = [];
            foreach ($active as $pool) {
                $current = $live->get($pool['name'])['workers'];
                $target = max($current - $pool['balance_shift'], min($current + $pool['balance_shift'], $targets[$pool['name']]));
                if ($target !== $current) { $changes[] = ['name' => $pool['name'], 'current' => $current, 'target' => $target]; }
            }
            return $changes;
        } catch (Throwable) {
            // Missing queue counts or due-work evidence holds the group's allocation.
            return [];
        }
    }

    /** Separate from LLEN: wake or retain one consumer until Laravel migrates due work. */
    private function dueCount(string $connection, array $queues): int
    {
        $name = config('queue.connections.'.$connection.'.connection', 'default');
        if (! is_string($name) || isset($this->dueFailed[$name])) { throw new RuntimeException('Queue connection unavailable.'); }
        try {
            if (microtime(true) > $this->dueDeadline) { throw new RuntimeException('Due-work observation time budget exceeded.'); }
            $redis = $this->dueConnections[$name] ??= ObservationRedis::connect($name);
            $driver = app('queue')->connection($connection); $total = 0;
            foreach ($queues as $queue) {
                $cache = json_encode([$connection, $queue], JSON_THROW_ON_ERROR);
                if (! array_key_exists($cache, $this->dueObserved)) {
                    if (microtime(true) > $this->dueDeadline) { throw new RuntimeException('Due-work observation time budget exceeded.'); }
                    $key = $driver->getQueue($queue); $due = 0; $cutoff = time();
                    foreach ([':delayed', ':reserved'] as $suffix) {
                        if (microtime(true) > $this->dueDeadline) { throw new RuntimeException('Due-work observation time budget exceeded.'); }
                        $count = $redis->zcount($key.$suffix, '-inf', $cutoff);
                        if (! is_int($count) || $count < 0) { throw new RuntimeException('Due queue load unavailable.'); }
                        $due += $count;
                    }
                    $this->dueObserved[$cache] = $due;
                }
                $total += $this->dueObserved[$cache];
            }
            return $total;
        } catch (Throwable $e) {
            unset($this->dueConnections[$name]); $this->dueFailed[$name] = true;
            throw $e;
        }
    }

}
