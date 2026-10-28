<?php

namespace Current\Native;

use Throwable;

/** Shared, bounded process observations. This is not distributed execution fencing. */
final class Registry
{
    private float $nextPublish = 0;
    private ?array $settings = null;
    private mixed $redis = null;

    /** Match the local generation using each publisher host, not the reader's hostname. */
    public static function withoutLocal(array $instances, array $local): array
    {
        if (! ($local['available'] ?? false)) { return $instances; }
        return array_values(array_filter($instances, static function (array $instance) use ($local): bool {
            if (($instance['instance'] ?? null) !== $local['instance']
                || ! is_string($instance['host'] ?? null) || ! is_string($instance['id'] ?? null)) { return true; }
            $candidate = hash('sha256', $instance['host']."\0".$local['instance']."\0".$local['owner']);
            return ! hash_equals($candidate, $instance['id']);
        }));
    }

    public function tick(bool $final = false): void
    {
        if (! $final && microtime(true) < $this->nextPublish) { return; }
        $this->nextPublish = microtime(true) + 2;
        try {
            $health = app(Supervisor::class)->snapshot();
            if (! $health['available']) { return; }
            $this->publish($health, gethostname() ?: 'unknown-host');
        } catch (Throwable) {
            $this->redis = null;
            $this->settings = null;
            // Process execution never depends on observation storage.
        }
    }

    public function publish(array $health, string $host): void
    {
        [$redis, $prefix] = $this->connection();
        $host = mb_substr($host, 0, 128);
        $id = hash('sha256', $host."\0".$health['instance']."\0".$health['owner']);
        $record = ['id' => $id, 'host' => $host, 'instance' => $health['instance'], 'state' => $health['state'],
            'updated_at' => $health['updated_at'], 'pools' => $health['pools']];
        $json = json_encode($record, JSON_THROW_ON_ERROR);
        if (strlen($json) > Plan::MAX_REGISTRY_BYTES) {
            // Preserve an explicit invalid observation in the index. Omitting a
            // host entirely would make the rest of the fleet look complete.
            $record['pools'] = []; $record['state'] = 'partial'; $record['unavailable'] = 'size_limit';
            $json = json_encode($record, JSON_THROW_ON_ERROR);
        }
        // Use Redis time, not clocks from different hosts. Retain stale observations
        // briefly and cap the index even when hosts repeatedly change identity.
        $redis->eval(<<<'LUA'
local now = tonumber(redis.call('TIME')[1])
redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - 60)
redis.call('SET', KEYS[2], ARGV[2], 'EX', 60)
redis.call('ZADD', KEYS[1], now, ARGV[1])
redis.call('ZREMRANGEBYRANK', KEYS[1], 0, -257)
redis.call('EXPIRE', KEYS[1], 60)
return 1
LUA, 2, $prefix.':index', $prefix.':record:'.$id, $id, $json);
    }

    public function all(): array
    {
        $empty = ['available' => false, 'instances' => [], 'invalid' => 0, 'limit' => 256];
        try {
            [$redis, $prefix] = $this->connection();
            $rows = $redis->eval(<<<'LUA'
local now = tonumber(redis.call('TIME')[1])
local rows = redis.call('ZREVRANGEBYSCORE', KEYS[1], '+inf', now - 60, 'WITHSCORES', 'LIMIT', 0, 256)
return {now, rows}
LUA, 1, $prefix.':index');
            if (! is_array($rows) || count($rows) !== 2 || ! is_numeric($rows[0]) || ! is_array($rows[1])) { return $empty; }
            $instances = []; $invalid = 0;
            $ids = [];
            for ($i = 0; $i + 1 < count($rows[1]); $i += 2) {
                $id = $rows[1][$i];
                if (is_string($id) && preg_match('/^[a-f0-9]{64}$/D', $id) && is_numeric($rows[1][$i + 1])) {
                    $ids[$id] = (float) $rows[1][$i + 1];
                } else { $invalid++; }
            }
            $records = $ids ? $redis->mget(array_map(fn ($id) => $prefix.':record:'.$id, array_keys($ids))) : [];
            foreach (array_keys($ids) as $i => $id) {
                $json = $records[$i] ?? null;
                if (! is_string($json) || strlen($json) > 2097152) { $invalid++; continue; }
                $record = json_decode($json, true, 16);
                if (! $this->valid($record, $id)) { $invalid++; continue; }
                $age = (float) $rows[0] - $ids[$id];
                if (($age > 6 || $age < 0) && ! in_array($record['state'], ['stopped', 'failed'], true)) {
                    $record['state'] = 'stale';
                }
                if ($record['state'] === 'stale') {
                    foreach ($record['pools'] as &$pool) { $pool['running'] = null; $pool['consuming'] = null; $pool['state'] = 'unknown'; }
                    unset($pool);
                }
                $instances[] = $record;
            }
            return ['available' => true, 'instances' => $instances, 'invalid' => $invalid, 'limit' => 256];
        } catch (Throwable) {
            $this->redis = null;
            $this->settings = null;
            return $empty;
        }
    }

    private function connection(): array
    {
        if (config('current.native.registry.enabled', true) !== true) { throw new \RuntimeException('Registry disabled.'); }
        $name = config('current.native.registry.redis_connection', 'default');
        $namespace = config('current.native.registry.namespace', 'current');
        if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)
            || ! is_string($namespace) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $namespace)) {
            throw new \RuntimeException('Invalid native registry configuration.');
        }
        $source = config('database.redis.'.$name);
        if (! ObservationRedis::supportedClient() || (! is_array($source) && ! is_string($source))) {
            throw new \RuntimeException('Registry requires a supported standalone Redis connection.');
        }
        $settings = [config('database.redis.client', 'phpredis'), $source, config('database.redis.options', [])];
        if ($this->redis === null || $settings !== $this->settings) {
            $this->redis = ObservationRedis::connect($name);
            $this->settings = $settings;
        }
        return [$this->redis, $namespace.':native-registry'];
    }

    private function valid(mixed $record, string $id): bool
    {
        if (! is_array($record) || ($record['id'] ?? null) !== $id
            || ! is_string($record['host'] ?? null) || strlen($record['host']) > 512
            || ! is_string($record['instance'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $record['instance'])
            || ! in_array($record['state'] ?? null, ['starting', 'running', 'degraded', 'partial', 'pausing', 'paused', 'stopping', 'stopped', 'failed', 'stale'], true)
            || ! is_string($record['updated_at'] ?? null) || strlen($record['updated_at']) > 64
            || ! is_array($record['pools'] ?? null) || ! array_is_list($record['pools']) || ! $record['pools'] || count($record['pools']) > Plan::MAX_POOLS) { return false; }
        $total = 0; $names = [];
        foreach ($record['pools'] as $pool) {
            if (! is_array($pool) || ! is_string($pool['name'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pool['name']) || isset($names[$pool['name']])
                || ! is_int($pool['workers'] ?? null) || ! is_int($pool['min'] ?? null) || ! is_int($pool['max'] ?? null)
                || $pool['min'] < 0 || $pool['max'] < 1 || $pool['max'] > Plan::MAX_WORKERS || $pool['workers'] < $pool['min'] || $pool['workers'] > $pool['max']
                || ! array_key_exists('running', $pool) || ($pool['running'] !== null && (! is_int($pool['running']) || $pool['running'] < 0 || $pool['running'] > $pool['max']))
                || ! in_array($pool['state'] ?? null, ['starting', 'running', 'degraded', 'pausing', 'paused', 'stopping', 'stopped', 'failed', 'unknown'], true)) { return false; }
            if (isset($pool['group']) && (! is_string($pool['group']) || ($pool['group'] !== '' && ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pool['group'])))) { return false; }
            if (array_key_exists('consuming', $pool) && $pool['consuming'] !== null
                && (! is_int($pool['consuming']) || $pool['consuming'] < 0 || $pool['running'] === null || $pool['consuming'] > $pool['running'])) { return false; }
            if (isset($pool['queue']) && (! is_string($pool['queue']) || strlen($pool['queue']) > 128)) { return false; }
            if (isset($pool['connection']) && (! is_string($pool['connection']) || ($pool['connection'] !== '' && ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $pool['connection'])))) { return false; }
            if (isset($pool['queues'])) {
                if (! is_array($pool['queues']) || ! array_is_list($pool['queues']) || count($pool['queues']) > Plan::MAX_QUEUES) { return false; }
                foreach ($pool['queues'] as $queue) { if (! is_string($queue) || ! preg_match('/^[a-zA-Z0-9_.:{}-]{1,128}$/D', $queue)) { return false; } }
            }
            $total += $pool['workers']; $names[$pool['name']] = true;
        }
        return $total <= Plan::MAX_WORKERS;
    }
}
