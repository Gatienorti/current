<?php

namespace Current\Telemetry;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use RuntimeException;

/** Dedicated, bounded connection; never mutates the application's Redis client. */
final class Transport
{
    private \Redis|PredisClient|null $client = null;

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
        $redisSource = Arr::get($database, 'redis.'.($telemetry['redis_connection'] ?? ''));
        $prefix = Arr::get($database, 'redis.options.prefix', '');
        $client = Arr::get($database, 'redis.client', 'phpredis');
        $options = Arr::get($database, 'redis.options', []);
        $redis = $this->redisConfiguration($redisSource, $options, $client);
        $input = [$sql, $redisSource, $telemetry, $prefix, $client, $options];
        if ($input !== $this->settingsInput) {
            $this->disconnect();
        } elseif ($this->validatedSettings !== null && empty($sql['options']) && empty($sql['sslrootcert']) && empty($sql['sslcert']) && empty($sql['sslkey'])
            && empty($this->validatedSettings['redis_tls']['ca']) && empty($this->validatedSettings['redis_tls']['cert']) && empty($this->validatedSettings['redis_tls']['key'])) {
            return $this->validatedSettings;
        }
        // SQL/Redis TLS options include mutable filesystem inputs. Revalidate
        // those on each access, preserving readability and verification checks.
        $driver = $sql['driver'] ?? '';
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true) || empty($sql['database']) || ! empty($sql['prefix'])) {
            throw new RuntimeException('Async telemetry requires SQLite, MySQL, MariaDB or PostgreSQL without a table prefix.');
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
        if (! \Current\Native\ObservationRedis::supportedClient() || ! is_array($redis) || isset($redis['url']) || ! isset($redis['host'])) {
            throw new RuntimeException('Async telemetry requires phpredis or installed Predis 2.3/3.x and a named standalone Redis host connection (no URL/cluster).');
        }
        $host = $redis['host'];
        if (! is_string($host) || str_contains($host, '://') || str_contains($host, '/')) {
            throw new RuntimeException('Configure the Redis hostname and optional scheme=tls separately.');
        }
        if (str_contains($host, ':') && ! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            throw new RuntimeException('Configure Redis with an unbracketed IPv6 literal and a separate port.');
        }
        $address = str_contains($host, ':') ? '['.$host.']:'.($redis['port'] ?? 6379) : $host.':'.($redis['port'] ?? 6379);
        $scheme = $redis['scheme'] ?? 'tcp';
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['tcp', 'tls'], true)) {
            throw new RuntimeException('Async Redis requires scheme=tcp or scheme=tls.');
        }
        $redis['scheme'] = strtolower($scheme);
        $redisTls = $this->redisTls($redis, $client);
        $namespace = $telemetry['namespace'] ?? null;
        if (! is_string($namespace) || ! preg_match('/^[a-zA-Z0-9_-]{1,60}$/D', $namespace)) {
            throw new RuntimeException('Invalid telemetry namespace.');
        }

        $settings = [
            'client' => $client,
            'protocol' => 2,
            'sql' => ['driver' => $driver, 'host' => $sql['host'] ?? '', 'port' => (int) ($sql['port'] ?? ($driver === 'pgsql' ? 5432 : 3306)), 'socket' => $sql['unix_socket'] ?? '', 'database' => $sql['database'], 'username' => $sql['username'] ?? '', 'password' => $sql['password'] ?? '', 'tls' => $driver === 'pgsql' ? $this->postgresTls($sql) : $this->sqlTls($sql['options'] ?? [])],
            'address' => $address, 'host' => $host,
            'port' => (int) ($redis['port'] ?? 6379), 'database' => (int) ($redis['database'] ?? 0),
            'username' => $redis['username'] ?? '', 'password' => $redis['password'] ?? '',
            'tls' => ($redis['scheme'] ?? 'tcp') === 'tls',
            'redis_tls' => $redisTls,
            'prefix' => '{current:'.$namespace.':'.substr(hash('sha256', (string) $prefix), 0, 16).'}:', 'file' => $sql['database'],
            'max_age' => max(60, min(86400, (int) ($telemetry['max_age_seconds'] ?? 3600))),
            'max_bytes' => max(4096, min(262144, (int) ($telemetry['max_event_bytes'] ?? 131072))),
            'max_pending' => max(1, min(20000, (int) ($telemetry['max_pending'] ?? 2000))),
        ];
        $this->settingsInput = $input;

        return $this->validatedSettings = $settings;
    }

    private function redisConfiguration(mixed $source, array $options, string $client): mixed
    {
        if (! is_array($source)) { return $source; }
        if ($client === 'predis') {
            $defaults = $source['options']['parameters'] ?? $options['parameters'] ?? [];
            return array_replace(is_array($defaults) ? $defaults : [], $source);
        }
        return array_replace($source, $options, $source['options'] ?? []);
    }

    private function redisTls(array $redis, string $client): array
    {
        $tls = ['ca' => '', 'cert' => '', 'key' => '', 'server_name' => ''];
        $ssl = $client === 'predis' ? ($redis['ssl'] ?? []) : ($redis['context']['stream'] ?? []);
        if (! is_array($ssl)) { throw new RuntimeException('Redis TLS options must be an array.'); }
        if ($ssl && ($redis['scheme'] ?? 'tcp') !== 'tls') { throw new RuntimeException('Redis TLS options require scheme=tls.'); }
        foreach ($ssl as $option => $value) {
            if (in_array($option, ['verify_peer', 'verify_peer_name', 'SNI_enabled'], true)) {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) { throw new RuntimeException('Async Redis TLS requires peer and hostname verification.'); }
            } elseif ($option === 'allow_self_signed') {
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false) { throw new RuntimeException('Async Redis TLS requires trusted server certificates.'); }
            } elseif ($option === 'peer_name') {
                if (! is_string($value) || ! preg_match('/^[a-zA-Z0-9_.:-]{1,253}$/D', $value)) { throw new RuntimeException('Redis TLS server name is invalid.'); }
                $tls['server_name'] = $value;
            } elseif (isset(['cafile' => true, 'local_cert' => true, 'local_pk' => true][$option])) {
                if (is_string($value)) { clearstatcache(true, $value); }
                if (! is_string($value) || strlen($value) > 4096 || str_contains($value, "\0") || ! is_file($value) || ! is_readable($value) || filesize($value) > 1048576) {
                    throw new RuntimeException('Redis TLS certificate files must be readable and at most 1 MiB.');
                }
                $tls[['cafile' => 'ca', 'local_cert' => 'cert', 'local_pk' => 'key'][$option]] = realpath($value);
            } else { throw new RuntimeException('This Redis TLS option cannot be mirrored by the collector.'); }
        }
        if ($tls['cert'] !== '' && $tls['key'] === '') { $tls['key'] = $tls['cert']; } // PHP also accepts a combined certificate/key PEM.
        if ($tls['cert'] === '' && $tls['key'] !== '') { throw new RuntimeException('Redis TLS requires a client certificate with its key.'); }
        return $tls;
    }

    private function postgresTls(array $sql): array
    {
        if (! empty($sql['options']) || ($sql['search_path'] ?? 'public') !== 'public'
            || isset($sql['schema']) || ! empty($sql['unix_socket'])
            || ! in_array(strtolower($sql['charset'] ?? 'utf8'), ['utf8', 'utf-8'], true)
            || str_contains($sql['host'], '/') || str_contains($sql['host'], ',') || str_contains($sql['host'], ';')) {
            throw new RuntimeException('Async PostgreSQL requires UTF8, public search_path and one TCP host without custom PDO options.');
        }
        $mode = $sql['sslmode'] ?? 'prefer';
        if (! in_array($mode, ['disable', 'verify-full'], true)) {
            throw new RuntimeException('Async PostgreSQL requires sslmode=verify-full or explicit sslmode=disable; TLS downgrade is not supported.');
        }
        $tls = ['enabled' => $mode === 'verify-full', 'ca' => '', 'cert' => '', 'key' => ''];
        foreach (['sslrootcert' => 'ca', 'sslcert' => 'cert', 'sslkey' => 'key'] as $option => $field) {
            if (empty($sql[$option])) { continue; }
            $value = $sql[$option];
            if (! $tls['enabled'] || ! is_string($value)) { throw new RuntimeException('PostgreSQL certificate files require sslmode=verify-full.'); }
            clearstatcache(true, $value);
            if (! is_file($value) || ! is_readable($value) || filesize($value) > 1048576) {
                throw new RuntimeException('SQL TLS certificate files must be readable and at most 1 MiB.');
            }
            $tls[$field] = realpath($value);
        }
        if (($tls['cert'] === '') !== ($tls['key'] === '')) { throw new RuntimeException('SQL TLS requires both client certificate and key.'); }
        return $tls;
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

    public function client(): \Redis|PredisClient
    {
        return $this->clientForSettings($this->settings());
    }

    private function clientForSettings(array $s): \Redis|PredisClient
    {
        if ($this->client) {
            return $this->client;
        }
        if ($s['client'] === 'predis') {
            $ssl = $this->redisSslOptions($s['redis_tls']);
            return $this->client = new PredisClient(new \Predis\Client([
                'scheme' => $s['tls'] ? 'tls' : 'tcp', 'host' => $s['host'], 'port' => $s['port'],
                'database' => $s['database'], 'username' => $s['username'] === '' ? null : $s['username'],
                'password' => $s['password'] === '' ? null : $s['password'],
                'timeout' => 0.2, 'read_write_timeout' => 0.2, 'persistent' => false, 'protocol' => 2,
                'ssl' => $ssl,
            ], ['prefix' => $s['prefix'], 'exceptions' => true]));
        }
        $r = new \Redis;
        $r->connect(($s['tls'] ? 'tls://' : '').$s['host'], $s['port'], 0.2, null, 0, 0.2,
            $s['tls'] ? ['stream' => $this->redisSslOptions($s['redis_tls'])] : []);
        if ($s['password'] !== '') {
            $r->auth($s['username'] !== '' ? [$s['username'], $s['password']] : $s['password']);
        }
        $r->select($s['database']);
        $r->setOption(\Redis::OPT_PREFIX, $s['prefix']);

        return $this->client = $r;
    }

    private function redisSslOptions(array $tls): array
    {
        $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
        foreach (['ca' => 'cafile', 'cert' => 'local_cert', 'key' => 'local_pk', 'server_name' => 'peer_name'] as $field => $option) {
            if ($tls[$field] !== '') { $ssl[$option] = $tls[$field]; }
        }
        return $ssl;
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
