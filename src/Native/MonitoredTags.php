<?php

namespace Current\Native;

use Current\Support\Sanitizer;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Dynamic retention policy. Worker reads use private bounded Redis, never history SQL. */
final class MonitoredTags
{
    private mixed $redis = null;
    private ?string $configuration = null;
    private ?string $revision = null;
    private array $policy = [];
    private float $nextDiagnostic = 0;

    public function validateTag(string $tag): void
    {
        if ($tag === '' || strlen($tag) > 100 || preg_match('/[\x00-\x20\x7f]/', $tag)
            || preg_match('/(?:token|password|secret|api[_-]?key)\s*[=:]/i', $tag)
            || app(Sanitizer::class)->tags([$tag]) !== [$tag]) {
            throw new InvalidArgumentException('Use one approved tag of at most 100 bytes without whitespace or credential assignments.');
        }
    }

    public function settings(): array
    {
        $name = config('current.native.monitoring.redis_connection', config('current.native.registry.redis_connection', 'default'));
        $namespace = config('current.native.monitoring.namespace', config('current.native.registry.namespace', 'current'));
        $days = config('current.native.monitoring.retention_days', 7);
        $limit = config('current.native.monitoring.max_tags', 128);
        if (! is_string($name) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name)
            || ! is_string($namespace) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $namespace)
            || ! is_int($days) || $days < 1 || $days > 30 || ! is_int($limit) || $limit < 1 || $limit > 10000) {
            throw new InvalidArgumentException('Invalid native monitored-tag connection, namespace, retention or tag limit.');
        }
        return ['connection' => $name, 'key' => $namespace.':native-monitored-tags', 'max_tags' => $limit,
            'retention_days' => min($days, max(1, min(30, (int) config('current.max_retention_days', 30))))];
    }

    /** Revision-aware cache: changes are visible to already-running workers on their next observation. */
    public function all(): array
    {
        $settings = $this->settings(); $redis = $this->connection($settings);
        $reply = $redis->eval(<<<'LUA'
local revision = redis.call('GET', KEYS[2]) or '0'
if redis.call('HLEN', KEYS[1]) > tonumber(ARGV[2]) then return {revision, 'overflow'} end
if revision == ARGV[1] then return {revision} end
return {revision, redis.call('HGETALL', KEYS[1])}
LUA, 2, $settings['key'], $settings['key'].':revision', $this->revision ?? '', $settings['max_tags']);
        if (! is_array($reply) || ! is_string($reply[0] ?? null)) { throw new RuntimeException('Monitored-tag policy unavailable.'); }
        if (count($reply) > 1) {
            $raw = $reply[1]; $policy = [];
            if (! is_array($raw) || count($raw) > $settings['max_tags'] * 2 || count($raw) % 2 !== 0) {
                throw new RuntimeException('Monitored-tag policy exceeds its configured bound.');
            }
            for ($i = 0; $i < count($raw); $i += 2) {
                $tag = $raw[$i]; $record = json_decode($raw[$i + 1], true, 8, JSON_THROW_ON_ERROR);
                if (! is_string($tag) || strlen($tag) > 100 || ! is_array($record) || ! is_numeric($record['started_at'] ?? null)
                    || ! is_finite((float) $record['started_at']) || $record['started_at'] <= 0
                    || ! is_int($record['retention_days'] ?? null) || $record['retention_days'] < 1 || $record['retention_days'] > 30) {
                    throw new RuntimeException('Invalid monitored-tag policy.');
                }
                try { $this->validateTag($tag); } catch (InvalidArgumentException) { continue; }
                $policy[$tag] = $record;
            }
            ksort($policy); $this->policy = $policy;
        }
        $this->revision = $reply[0];
        if ($this->policy) { $this->assertCollectorCapability(); }
        return $this->policy;
    }

    /** Startup can inspect policy before this collector has acquired its owner lease. */
    public function hasPolicies(): bool
    {
        $settings = $this->settings();
        return (int) $this->connection($settings)->hlen($settings['key']) > 0;
    }

    public function add(string $tag): void
    {
        $this->validateTag($tag); $settings = $this->settings();
        $this->assertCollectorCapability();
        $record = json_encode(['started_at' => microtime(true), 'retention_days' => $settings['retention_days']], JSON_THROW_ON_ERROR);
        $result = $this->connection($settings)->eval(<<<'LUA'
if redis.call('HEXISTS', KEYS[1], ARGV[1]) == 1 then return 1 end
if redis.call('HLEN', KEYS[1]) >= tonumber(ARGV[3]) then return 0 end
redis.call('HSET', KEYS[1], ARGV[1], ARGV[2])
redis.call('INCR', KEYS[2])
return 1
LUA, 2, $settings['key'], $settings['key'].':revision', $tag, $record, $settings['max_tags']);
        if ((int) $result !== 1) { throw new RuntimeException('The configured monitored-tag limit has been reached.'); }
        $this->revision = null;
    }

    public function remove(string $tag): void
    {
        $this->validateTag($tag); $settings = $this->settings();
        $this->connection($settings)->eval("local n=redis.call('HDEL',KEYS[1],ARGV[1]); if n>0 then redis.call('INCR',KEYS[2]) end return n", 2,
            $settings['key'], $settings['key'].':revision', $tag);
        $this->revision = null;
    }

    /** A selected policy extends detail retention from this observation, including completion. */
    public function expiresAt(array $payload, ?float $observed = null): ?float
    {
        try {
            $tags = app(Sanitizer::class)->tags(is_array($payload['tags'] ?? null) ? $payload['tags'] : []);
            if (! $tags) { return null; }
            $policy = $this->all(); $seconds = 0;
            $cap = max(1, min(30, (int) config('current.max_retention_days', 30)));
            foreach ($tags as $tag) {
                if (isset($policy[$tag])) { $seconds = max($seconds, min($cap, $policy[$tag]['retention_days']) * 86400); }
            }
            return $seconds ? ($observed ?? microtime(true)) + $seconds : null;
        } catch (Throwable $exception) {
            $this->redis = null; $this->revision = null;
            if (microtime(true) >= $this->nextDiagnostic) {
                $this->nextDiagnostic = microtime(true) + 30;
                try { app('log')->warning('Current monitored-tag retention could not be observed.', ['error_class' => $exception::class]); } catch (Throwable) {}
            }
            return null;
        }
    }

    private function connection(array $settings): mixed
    {
        $fingerprint = hash('sha256', serialize([$settings, config('database.redis'), config('current.tag_prefixes')]));
        if ($fingerprint !== $this->configuration) {
            $this->redis = null; $this->revision = null; $this->policy = []; $this->configuration = $fingerprint;
        }
        return $this->redis ??= ObservationRedis::connect($settings['connection']);
    }

    private function assertCollectorCapability(): void
    {
        if (! app(\Current\Telemetry\Health::class)->supportsMonitoredRetention()) {
            throw new MonitoredRetentionUnavailable('Monitored retention requires a running matching collector with monitored_retention capability 1.');
        }
    }
}
