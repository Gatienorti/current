<?php

namespace Current\Telemetry;

use Throwable;

final class Health
{
    public function __construct(private Transport $transport) {}

    /** Ownership and fresh capability evidence, without scanning telemetry streams. */
    public function supportsMonitoredRetention(): bool
    {
        if (config('current.telemetry.driver', 'sync') !== 'redis') { return true; }
        try {
            $redis = $this->transport->client();
            $health = $redis->hGetAll('health');
            $owner = $redis->get('owner');
            return is_string($owner) && $owner !== '' && ($health['owner_token'] ?? null) === $owner
                && microtime(true) - (float) ($health['heartbeat_at'] ?? 0) <= 15
                && (float) ($health['heartbeat_at'] ?? 0) <= microtime(true) + 5
                && (int) ($health['protocol'] ?? 0) === 2 && (int) ($health['maintenance_protocol'] ?? 0) === 1
                && (int) ($health['monitored_retention'] ?? 0) === 1;
        } catch (Throwable) { return false; }
    }

    public function snapshot(): ?array
    {
        if (config('current.telemetry.driver', 'sync') !== 'redis') {
            return null;
        }
        try {
            $r = $this->transport->client();
            $health = $r->hGetAll('health');
            $pending = 0;
            $lag = 0;
            foreach (['events', 'events:v2'] as $stream) {
                $pending += $r->xLen($stream);
                $oldest = $r->xRange($stream, '-', '+', 1);
                $id = array_key_first($oldest ?: []);
                if ($id) { $lag = max($lag, microtime(true) - ((float) explode('-', $id)[0] / 1000)); }
            }
            $owner = $r->get('owner');
            $customTls = (bool) array_filter($this->transport->settings()['redis_tls']);
            $stale = ! $owner || ($health['owner_token'] ?? null) !== $owner || microtime(true) - (float) ($health['heartbeat_at'] ?? 0) > 15
                || (int) ($health['protocol'] ?? 0) !== 2 || (int) ($health['maintenance_protocol'] ?? 0) !== 1
                || ($customTls && (int) ($health['redis_tls_protocol'] ?? 0) !== 1);
            $lost = (int) ($health['rejected'] ?? 0) + (int) ($health['invalid'] ?? 0) + (int) ($health['expired'] ?? 0);
            $unaccounted = max(0, (int) ($health['accepted'] ?? 0) - (int) ($health['processed'] ?? 0) - $pending);
            $warning = $stale || $lost > 0 || $unaccounted > 0 || ! empty($health['last_error']) || $lag > config('current.telemetry.lag_warning_seconds', 10);

            return ['available' => true, 'warning' => $warning, 'running' => ! $stale, 'pending' => $pending, 'lag_seconds' => round($lag, 1), 'dropped' => $lost, 'unaccounted' => $unaccounted, 'storage_error' => ! empty($health['last_error'])];
        } catch (Throwable) {
            return ['available' => false, 'warning' => true];
        }
    }
}
