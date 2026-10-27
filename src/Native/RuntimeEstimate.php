<?php

namespace Current\Native;

use RuntimeException;
use Throwable;

/** Reads short-lived observations only; SQL never runs on the launcher/request path. */
final class RuntimeEstimate
{
    private mixed $redis = null;
    private bool $failed = false;
    private array $observed = [];
    private float $deadline;

    public function __construct() { $this->deadline = microtime(true) + 1; }

    public function workSeconds(string $connection, array $counts): float
    {
        $busy = array_filter($counts, fn ($count) => $count > 0);
        if (! $busy) { return 0.0; }
        try {
            if ($this->failed || microtime(true) > $this->deadline) { throw new RuntimeException('Runtime observation unavailable.'); }
            $keys = [];
            foreach ($busy as $queue => $count) {
                if (! is_numeric($count) || ! is_finite((float) $count)) { throw new RuntimeException('Invalid queue load.'); }
                $key = app(RuntimeSnapshot::class)->key($connection, $queue);
                if (! array_key_exists($key, $this->observed)) { $keys[] = $key; }
            }
            if ($keys) {
                try {
                    $this->redis ??= ObservationRedis::connect(config('current.native.registry.redis_connection', 'default'));
                    $rows = $this->redis->mget($keys);
                } catch (Throwable $e) { $this->failed = true; $this->redis = null; throw $e; }
                if (! is_array($rows) || count($rows) !== count($keys)) { $this->failed = true; throw new RuntimeException('Runtime observation unavailable.'); }
                foreach ($keys as $index => $key) { $this->observed[$key] = $rows[$index]; }
            }
            $seconds = 0.0; $now = microtime(true);
            foreach ($busy as $queue => $count) {
                $raw = $this->observed[app(RuntimeSnapshot::class)->key($connection, $queue)];
                $row = is_string($raw) ? json_decode($raw, true, 8, JSON_THROW_ON_ERROR) : null;
                $observed = $row['observed_at'] ?? null; $runtime = $row['runtime'] ?? null;
                if (! is_numeric($observed) || ! is_finite((float) $observed) || $observed > $now + 1 || $observed <= $now - RuntimeSnapshot::TTL
                    || ! is_numeric($runtime) || ! is_finite((float) $runtime) || $runtime < 0) {
                    throw new RuntimeException('Recent queue runtime is unavailable; hold worker allocation.');
                }
                $seconds += $count * (float) $runtime;
            }
            if (! is_finite($seconds)) { throw new RuntimeException('Queue runtime estimate exceeds bounds.'); }
            return $seconds;
        } catch (Throwable $e) {
            // Missing evidence for one queue must not hide other valid groups.
            throw $e;
        }
    }
}
