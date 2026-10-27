<?php

namespace Current\Native;

use RuntimeException;
use Throwable;

/** One bounded observation pass, shared across pools to avoid retrying failed endpoints. */
final class QueueLoad
{
    private array $connections = [];
    private array $failed = [];
    private array $observed = [];
    private float $deadline;

    public function __construct() { $this->deadline = microtime(true) + 1; }

    public function counts(string $connection, array $queues, bool $reserved = false): array
    {
        $name = config('queue.connections.'.$connection.'.connection', 'default');
        if (! is_string($name) || isset($this->failed[$name])) { throw new RuntimeException('Queue connection unavailable.'); }
        try {
            if (microtime(true) > $this->deadline) { throw new RuntimeException('Queue observation time budget exceeded.'); }
            if (config('queue.connections.'.$connection.'.driver') !== 'current-redis') { throw new RuntimeException('Native queue connection required.'); }
            $redis = $this->connections[$name] ??= ObservationRedis::connect($name);
            $driver = app('queue')->connection($connection); $result = [];
            foreach ($queues as $queue) {
                $cache = json_encode([$connection, $queue, $reserved], JSON_THROW_ON_ERROR);
                if (array_key_exists($cache, $this->observed)) { $result[$queue] = $this->observed[$cache]; continue; }
                if (microtime(true) > $this->deadline) { throw new RuntimeException('Queue observation time budget exceeded.'); }
                $key = $driver->getQueue($queue); $ready = $redis->llen($key); $active = $reserved ? $redis->zcard($key.':reserved') : 0;
                if (! is_int($ready) || $ready < 0 || ! is_int($active) || $active < 0) { throw new RuntimeException('Queue load unavailable.'); }
                $result[$queue] = $this->observed[$cache] = $ready + $active;
            }
            return $result;
        } catch (Throwable $e) {
            unset($this->connections[$name]); $this->failed[$name] = true;
            throw $e;
        }
    }
}
