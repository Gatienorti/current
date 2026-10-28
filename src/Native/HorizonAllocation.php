<?php

namespace Current\Native;

use InvalidArgumentException;

/** Horizon's proportional recommendations; live capacity is enforced by the caller. */
final class HorizonAllocation
{
    public static function targets(array $pools, array $weights, int $budget, ?array $readyCounts = null): array
    {
        $total = 0.0;
        foreach ($pools as $pool) {
            $weight = $weights[$pool['name']] ?? null;
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0) {
                throw new InvalidArgumentException('Queue weight unavailable.');
            }
            $total += $weight;
            if ($readyCounts !== null && (! is_int($readyCounts[$pool['name']] ?? null) || $readyCounts[$pool['name']] < 0)) {
                throw new InvalidArgumentException('Queue ready count unavailable.');
            }
        }
        if ($budget < array_sum(array_column($pools, 'min_workers')) || ! is_finite($total)) {
            throw new InvalidArgumentException('Invalid queue allocation budget or weight.');
        }
        $targets = [];
        foreach ($pools as $pool) {
            // Horizon falls back to maximum demand for busy queues when every
            // measured time is zero. An unknown duration must never reach here.
            $share = $total > 0 ? (int) ceil(($weights[$pool['name']] / $total) * $budget)
                : (($readyCounts[$pool['name']] ?? 0) > 0 ? $budget : $pool['min_workers']);
            $targets[$pool['name']] = max($pool['min_workers'], min($pool['max_workers'], $share));
        }
        // As in Horizon, rounded recommendations can exceed the global budget.
        // Confirmed live-worker capacity and reductions must precede any growth.
        return $targets;
    }
}
