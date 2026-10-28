<?php

namespace Current\Native;

use Current\Store;
use InvalidArgumentException;
use RuntimeException;

/** SQL aggregation runs only in the isolated sampler process. */
final class RuntimeSnapshot
{
    public const TTL = 30;
    public const MAX_PAIRS = 1024;

    public function source(): array
    {
        $source = config('database.connections.'.config('current.connection'));
        if (! is_array($source)) { throw new RuntimeException('History connection unavailable.'); }
        return $source;
    }

    /** Standard Laravel connections can be read without booting application providers. */
    public function readerSource(): ?array
    {
        $manager = app('db');
        if ($manager::class !== \Illuminate\Database\DatabaseManager::class
            || app('db.factory')::class !== \Illuminate\Database\Connectors\ConnectionFactory::class) { return null; }
        try {
            $extensions = (new \ReflectionProperty(\Illuminate\Database\DatabaseManager::class, 'extensions'))->getValue($manager);
            if ($extensions !== []) { return null; }
        } catch (\Throwable) { return null; }
        $connection = app(Store::class)->connection();
        $driver = $connection->getDriverName();
        $type = match ($driver) {
            'sqlite' => \Illuminate\Database\SQLiteConnection::class,
            'mysql' => \Illuminate\Database\MySqlConnection::class,
            'mariadb' => \Illuminate\Database\MariaDbConnection::class,
            'pgsql' => \Illuminate\Database\PostgresConnection::class,
            'sqlsrv' => \Illuminate\Database\SqlServerConnection::class,
            default => null,
        };
        if ($type === null || $connection::class !== $type || \Illuminate\Database\Connection::getResolver($driver)
            || app()->bound('db.connector.'.$driver)) { return null; }
        $grammar = 'Illuminate\\Database\\Query\\Grammars\\'.match ($driver) {
            'sqlite' => 'SQLiteGrammar', 'mysql' => 'MySqlGrammar', 'mariadb' => 'MariaDbGrammar',
            'pgsql' => 'PostgresGrammar', 'sqlsrv' => 'SqlServerGrammar',
        };
        if ($connection->getQueryGrammar()::class !== $grammar) { return null; }
        // DatabaseManager has already resolved URL configuration; preserve all PDO/TLS options.
        return $connection->getConfig();
    }

    public function routes(array $pools): array
    {
        $routes = [];
        foreach ($pools as $pool) {
            foreach ($pool['queues'] as $queue) { $routes[$pool['connection']][$queue] = true; }
        }
        return array_map(fn ($queues) => array_keys($queues), $routes);
    }

    public function validateRoutes(array $routes): void
    {
        $count = 0;
        foreach ($routes as $connection => $queues) {
            if (! is_string($connection) || $connection === '' || strlen($connection) > 100 || ! is_array($queues) || ! $queues) {
                throw new InvalidArgumentException('Invalid runtime observation routing.');
            }
            foreach ($queues as $queue) {
                if (! is_string($queue) || $queue === '' || strlen($queue) > 100 || ++$count > self::MAX_PAIRS) {
                    throw new InvalidArgumentException('Runtime observation routing exceeds bounds.');
                }
            }
        }
        if (! $count) { throw new InvalidArgumentException('Runtime observation routing is empty.'); }
    }

    public function sample(array $routes, ?\Illuminate\Database\Connection $database = null): array
    {
        $this->validateRoutes($routes);
        $now = microtime(true); $rows = [];
        foreach ($routes as $connection => $queues) {
            $runtimes = ($database ?? app(Store::class)->connection())->table('lens_attempts as a')->join('lens_jobs as j', 'j.id', '=', 'a.job_id')
                ->where('j.connection', $connection)->whereIn('j.queue', $queues)->where('j.expires_at', '>', $now)
                ->where('a.finished_at', '>=', $now - 300)->where('a.finished_at', '<=', $now)
                ->whereIn('a.status', ['completed', 'failed'])->whereColumn('a.finished_at', '>=', 'a.started_at')
                ->select('j.queue')->selectRaw('AVG(a.finished_at - a.started_at) AS runtime')->groupBy('j.queue')->pluck('runtime', 'queue');
            foreach ($queues as $queue) {
                $value = $runtimes->get($queue);
                $rows[] = ['connection' => $connection, 'queue' => $queue, 'runtime' => $value === null ? null : (float) $value];
            }
        }
        return ['observed_at' => $now, 'rows' => $rows];
    }

    public function key(string $connection, string $queue): string
    {
        $namespace = config('current.native.registry.namespace', 'current');
        if (! is_string($namespace) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $namespace)) {
            throw new RuntimeException('Invalid runtime observation namespace.');
        }
        return $namespace.':native-runtime:'.hash('sha256', json_encode([$this->source(), $connection, $queue], JSON_THROW_ON_ERROR));
    }

    public function publish(array $snapshot, array $routes): void
    {
        $this->validateRoutes($routes);
        $observed = $snapshot['observed_at'] ?? null;
        if (! is_numeric($observed) || ! is_finite((float) $observed) || $observed > microtime(true) + 1 || $observed < microtime(true) - 5 || ! is_array($snapshot['rows'] ?? null)) {
            throw new RuntimeException('Runtime snapshot is stale or invalid.');
        }
        $expected = [];
        foreach ($routes as $connection => $queues) { foreach ($queues as $queue) { $expected[$this->key($connection, $queue)] = true; } }
        $values = [];
        foreach ($snapshot['rows'] as $row) {
            if (! is_array($row) || ! is_string($row['connection'] ?? null) || ! is_string($row['queue'] ?? null) || ! array_key_exists('runtime', $row)) {
                throw new RuntimeException('Invalid runtime snapshot row.');
            }
            $key = $this->key($row['connection'], $row['queue']); $runtime = $row['runtime'];
            if (! isset($expected[$key]) || isset($values[$key]) || ($runtime !== null && (! is_numeric($runtime) || ! is_finite((float) $runtime) || $runtime < 0))) {
                throw new RuntimeException('Invalid runtime snapshot values.');
            }
            $values[$key] = json_encode(['observed_at' => (float) $observed, 'runtime' => $runtime], JSON_THROW_ON_ERROR);
        }
        if (count($values) !== count($expected)) { throw new RuntimeException('Incomplete runtime snapshot.'); }
        $redis = ObservationRedis::connect(config('current.native.registry.redis_connection', 'default'));
        // One bounded round trip publishes the whole sample; missing evidence replaces old values.
        $arguments = array_merge(array_keys($values), [self::TTL], array_values($values));
        $redis->eval("for i=1,#KEYS do redis.call('SET',KEYS[i],ARGV[i+1],'EX',ARGV[1]) end return #KEYS", count($values), ...$arguments);
    }
}
