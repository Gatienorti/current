<?php

namespace Current\Telemetry;

use Throwable;

final class Health
{
    public function __construct(private Transport $transport) {}

    public function snapshot(): ?array
    {
        if (config('current.telemetry.driver', 'sync') !== 'redis') {
            return null;
        }
        try {
            $r = $this->transport->client();
            $health = $r->hGetAll('health');
            $pending = $r->xLen('events');
            $oldest = $r->xRange('events', '-', '+', 1);
            $id = array_key_first($oldest ?: []);
            $lag = $id ? max(0, microtime(true) - ((float) explode('-', $id)[0] / 1000)) : 0;
            $stale = ! $r->exists('owner') || microtime(true) - (float) ($health['heartbeat_at'] ?? 0) > 15;
            $lost = (int) ($health['rejected'] ?? 0) + (int) ($health['invalid'] ?? 0) + (int) ($health['expired'] ?? 0);
            $unaccounted = max(0, (int) ($health['accepted'] ?? 0) - (int) ($health['processed'] ?? 0) - $pending);
            $warning = $stale || $lost > 0 || $unaccounted > 0 || ! empty($health['last_error']) || $lag > config('current.telemetry.lag_warning_seconds', 10);

            return ['available' => true, 'warning' => $warning, 'running' => ! $stale, 'pending' => $pending, 'lag_seconds' => round($lag, 1), 'dropped' => $lost, 'unaccounted' => $unaccounted, 'storage_error' => ! empty($health['last_error'])];
        } catch (Throwable) {
            return ['available' => false, 'warning' => true];
        }
    }
}
