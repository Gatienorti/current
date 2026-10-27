<?php

namespace Current\Native;

use InvalidArgumentException;

/** Bounded proportional allocation with deterministic rounding and per-queue floors. */
final class Allocation
{
    public static function targets(array $pools, array $weights, int $budget): array
    {
        $entries = []; $minimum = 0; $maximum = 0; $high = 0.0;
        foreach ($pools as $pool) {
            $name = $pool['name']; $weight = $weights[$name] ?? null;
            if (! is_numeric($weight) || ! is_finite((float) $weight) || $weight < 0) {
                throw new InvalidArgumentException('Queue weight unavailable.');
            }
            // Empty queues retain their minimum; unused capacity is not filled artificially.
            $max = $weight > 0 ? $pool['max_workers'] : $pool['min_workers'];
            $entries[$name] = ['weight' => (float) $weight, 'min' => $pool['min_workers'], 'max' => $max];
            $minimum += $pool['min_workers']; $maximum += $max;
            if ($weight > 0) { $high = max($high, $max / $weight); }
        }
        if ($budget < $minimum || ! is_finite($high)) { throw new InvalidArgumentException('Invalid queue allocation budget or weight.'); }
        $budget = min($budget, $maximum); $low = 0.0;
        // Solve sum(clamp(weight * multiplier, min, max)) = budget. Applying
        // all floors first can incorrectly strand capacity when another queue caps out.
        for ($i = 0; $i < 64; $i++) {
            $middle = ($low + $high) / 2; $total = 0.0;
            foreach ($entries as $entry) { $total += max($entry['min'], min($entry['max'], $middle * $entry['weight'])); }
            if ($total < $budget) { $low = $middle; } else { $high = $middle; }
        }
        $result = []; $fractions = [];
        foreach ($entries as $name => $entry) {
            $share = max($entry['min'], min($entry['max'], $low * $entry['weight']));
            $result[$name] = (int) floor($share);
            $fractions[$name] = $share - $result[$name];
        }
        $remaining = $budget - array_sum($result);
        uksort($fractions, fn ($a, $b) => ($fractions[$b] <=> $fractions[$a]) ?: strcmp($a, $b));
        foreach ($fractions as $name => $_) {
            if ($remaining > 0 && $result[$name] < $entries[$name]['max']) { $result[$name]++; $remaining--; }
        }
        return $result;
    }
}
