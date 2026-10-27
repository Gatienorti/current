<?php

namespace Current\Telemetry;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use RuntimeException;

/** Dedicated, bounded connection; never mutates the application's Redis client. */
final class Transport
{
    private ?\Redis $client = null;

    private ?array $settingsInput = null;

    private ?array $validatedSettings = null;

    public function settings(): array
    {
        // Two root reads avoid repeated dotted config lookups on every publication.
        // Keep one snapshot, and compare values so runtime config changes cannot use
        // stale validation or publish through the previous Redis namespace/client.
        $config = app('config');
        $current = $config->get('current', []);
        $database = $config->get('database', []);
        $telemetry = $current['telemetry'] ?? [];
        $sql = Arr::get($database, 'connections.'.($current['connection'] ?? ''));
        $redis = Arr::get($database, 'redis.'.($telemetry['redis_connection'] ?? ''));
        $prefix = Arr::get($database, 'redis.options.prefix', '');
        $input = [$sql, $redis, $telemetry, $prefix];
        if ($input !== $this->settingsInput) {
            $this->disconnect();
        } elseif ($this->validatedSettings !== null && empty($sql['options'])) {
            return $this->validatedSettings;
        }
        // PDO TLS options include mutable filesystem inputs. Revalidate those on
        // each access, preserving certificate readability and verification checks.
        $driver = $sql['driver'] ?? '';
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true) || empty($sql['database']) || ! empty($sql['prefix'])) {
            throw new RuntimeException('Async telemetry requires SQLite, MySQL or MariaDB without a table prefix.');
        }
        if ($driver === 'sqlite' && $sql['database'] === ':memory:') {
            throw new RuntimeException('Async telemetry requires a file-backed SQLite connection.');
        }
        if ($driver !== 'sqlite' && (isset($sql['url']) || ! empty($sql['read']) || ! empty($sql['write']))) {
            throw new RuntimeException('Async SQL requires one explicit server connection; URL and read/write splitting are not supported.');
        }
        if ($driver !== 'sqlite' && (! is_string($sql['host'] ?? null) || ! is_string($sql['database']) || ! is_string($sql['username'] ?? null))) {
            throw new RuntimeException('Configure an explicit SQL host, database and username for async telemetry.');
        }
        if (! extension_loaded('redis') || ! is_array($redis) || isset($redis['url']) || ! isset($redis['host'])) {
            throw new RuntimeException('Async telemetry requires phpredis and a named standalone Redis host connection (no URL/cluster).');
        }
        $host = $redis['host'];
        if (! is_string($host) || str_contains($host, '://') || str_contains($host, '/')) {
            throw new RuntimeException('Configure the Redis hostname and optional scheme=tls separately.');
        }
        $namespace = $telemetry['namespace'] ?? null;
        if (! is_string($namespace) || ! preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $namespace)) {
            throw new RuntimeException('Invalid telemetry namespace.');
        }

        $settings = [
            'protocol' => 2,
            'sql' => ['driver' => $driver, 'host' => $sql['host'] ?? '', 'port' => (int) ($sql['port'] ?? 3306), 'socket' => $sql['unix_socket'] ?? '', 'database' => $sql['database'], 'username' => $sql['username'] ?? '', 'password' => $sql['password'] ?? '', 'tls' => $this->sqlTls($sql['options'] ?? [])],
            'address' => $host.':'.($redis['port'] ?? 6379), 'host' => $host,
            'port' => (int) ($redis['port'] ?? 6379), 'database' => (int) ($redis['database'] ?? 0),
            'username' => $redis['username'] ?? '', 'password' => $redis['password'] ?? '',
            'tls' => ($redis['scheme'] ?? 'tcp') === 'tls',
            'prefix' => '{current:'.$namespace.':'.substr(hash('sha256', (string) $prefix), 0, 16).'}:', 'file' => $sql['database'],
            'max_age' => max(60, min(86400, (int) ($telemetry['max_age_seconds'] ?? 3600))),
            'max_bytes' => max(4096, min(262144, (int) ($telemetry['max_event_bytes'] ?? 131072))),
            'max_pending' => max(1, min(20000, (int) ($telemetry['max_pending'] ?? 2000))),
        ];
        $this->settingsInput = $input;

        return $this->validatedSettings = $settings;
    }

    private function sqlTls(array $options): array
    {
        $tls = ['enabled' => false, 'ca' => '', 'cert' => '', 'key' => ''];
        $supported = [];
        foreach (['MYSQL_ATTR_SSL_CA' => 'ca', 'MYSQL_ATTR_SSL_CERT' => 'cert', 'MYSQL_ATTR_SSL_KEY' => 'key', 'MYSQL_ATTR_SSL_VERIFY_SERVER_CERT' => 'verify'] as $constant => $field) {
            if (defined('PDO::'.$constant)) {
                $supported[constant('PDO::'.$constant)] = $field;
            }
        }
        foreach ($options as $option => $value) {
            if (! isset($supported[$option])) {
                throw new RuntimeException('This SQL PDO option is not supported by async telemetry.');
            }
            $tls['enabled'] = true;
            $field = $supported[$option];
            if ($field === 'verify') {
                if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
                    throw new RuntimeException('Async SQL TLS requires server certificate verification.');
                }
            } elseif (! is_string($value) || $value === '' || ! is_readable($value)) {
                throw new RuntimeException('SQL TLS certificate files must be readable by the worker and collector.');
            } else {
                $tls[$field] = $value;
            }
        }
        if (($tls['cert'] === '') !== ($tls['key'] === '')) {
            throw new RuntimeException('SQL TLS requires both client certificate and key.');
        }

        return $tls;
    }

    public function client(): \Redis
    {
        return $this->clientForSettings($this->settings());
    }

    private function clientForSettings(array $s): \Redis
    {
        if ($this->client) {
            return $this->client;
        }
        $r = new \Redis;
        $r->connect(($s['tls'] ? 'tls://' : '').$s['host'], $s['port'], 0.2, null, 0, 0.2);
        if ($s['password'] !== '') {
            $r->auth($s['username'] !== '' ? [$s['username'], $s['password']] : $s['password']);
        }
        $r->select($s['database']);
        $r->setOption(\Redis::OPT_PREFIX, $s['prefix']);

        return $this->client = $r;
    }

    public function cycle(string $uuid, string $connection, ?string $dispatchId = null, array $payload = []): array
    {
        $s = $this->settings();
        $r = $this->clientForSettings($s);
        $key = 'cycle:v2:'.hash('sha256', $dispatchId === null ? $connection.'|'.$uuid : json_encode([$connection, $uuid, $dispatchId], JSON_THROW_ON_ERROR));
        $now = microtime(true);
        if ($cached = $r->get($key)) {
            $row = json_decode($cached, true, flags: JSON_THROW_ON_ERROR);
            if ($row['expires_at'] > $now) {
                return $row;
            }
        }
        // The collector resolves retained history; dispatch never reads history SQL.
        $expires = $now + \Current\Support\JobRetention::seconds($payload);
        $candidate = json_encode(['id' => (string) Str::ulid(), 'first_observed_at' => $now, 'expires_at' => $expires], JSON_THROW_ON_ERROR);
        $value = $r->eval("local old=redis.call('GET',KEYS[1]); if old and cjson.decode(old).expires_at > tonumber(ARGV[1]) then return old end redis.call('SET',KEYS[1],ARGV[2],'EX',ARGV[3]); return ARGV[2]", [$key, $now, $candidate, max(60, min((int) ceil($expires - $now) + $s['max_age'], 2 * $s['max_age']))], 1);

        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function emit(array $event): void
    {
        $s = $this->settings();
        $event['version'] = 2;
        $event['id'] = (string) Str::ulid();
        $event['observed_at'] = microtime(true);
        $data = json_encode($event, JSON_THROW_ON_ERROR);
        if (strlen($data) > $s['max_bytes']) {
            $this->clientForSettings($s)->hIncrBy('health', 'rejected', 1);
            throw new RuntimeException('Telemetry envelope exceeds the configured bound.');
        }
        $ok = $this->clientForSettings($s)->eval("if redis.call('XLEN',KEYS[1])+redis.call('XLEN',KEYS[3])>=tonumber(ARGV[1]) then redis.call('HINCRBY',KEYS[2],'rejected',1); return 0 end redis.call('XADD',KEYS[1],'*','data',ARGV[2]); redis.call('HINCRBY',KEYS[2],'accepted',1); redis.call('EXPIRE',KEYS[1],ARGV[3]); return 1", ['events:v2', 'health', 'events', $s['max_pending'], $data, $s['max_age'] * 2], 3);
        if ($ok !== 1) {
            throw new RuntimeException('Telemetry buffer full.');
        }
    }

    public function disconnect(): void
    {
        $this->client = null;
        $this->settingsInput = null;
        $this->validatedSettings = null;
    }
}
