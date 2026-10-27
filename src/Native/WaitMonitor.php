<?php

namespace Current\Native;

use Current\Events\QueueWaitExceeded;
use InvalidArgumentException;
use Throwable;

/** Opt-in notification event with a shared cooldown; never executes on a job's path. */
final class WaitMonitor
{
    public function thresholds(): array
    {
        $waits = config('current.native.waits', []);
        if (! is_array($waits) || count($waits) > 128) { throw new InvalidArgumentException('Configure at most 128 native wait thresholds.'); }
        foreach ($waits as $key => $seconds) {
            if (! is_string($key) || strlen($key) > 4192 || ! str_contains($key, ':') || ! is_int($seconds) || $seconds < 1 || $seconds > 86400) {
                throw new InvalidArgumentException('Native wait thresholds require connection:queue keys and integer seconds between 1 and 86400.');
            }
        }
        return $waits;
    }

    public function inspect(array $workload): int
    {
        $waits = $this->thresholds();
        if (! $waits || ! $workload['available'] || $workload['partial']) { return 0; }
        $cooldown = config('current.native.wait_cooldown', 300);
        $namespace = config('current.native.registry.namespace', 'current');
        if (! is_int($cooldown) || $cooldown < 60 || $cooldown > 86400 || ! is_string($namespace) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $namespace)) {
            throw new InvalidArgumentException('Invalid native wait cooldown or namespace.');
        }
        $sent = 0; $redis = null;
        foreach ($workload['rows'] as $row) {
            $key = $row['connection'].':'.implode(',', $row['queues']);
            $threshold = $waits[$key] ?? null;
            if ($threshold === null || $row['wait'] === null || $row['wait'] <= $threshold || ! is_finite($row['wait'])) { continue; }
            try {
                $redis ??= ObservationRedis::connect(config('current.native.registry.redis_connection', 'default'));
                // Server-side claim suppresses simultaneous scheduler instances.
                $claimed = $redis->eval("return redis.call('SET', KEYS[1], '1', 'NX', 'EX', ARGV[1])", 1,
                    $namespace.':native-wait:'.hash('sha256', $key), $cooldown);
                if (! $claimed) { continue; }
                event(new QueueWaitExceeded($row['connection'], $row['queues'], $row['wait'], $threshold, time()));
                $sent++;
            } catch (Throwable) {
                // An ambiguous send/claim is never retried during this cooldown.
                // Observer or application-listener failures cannot affect workers.
                break;
            }
        }
        return $sent;
    }
}
