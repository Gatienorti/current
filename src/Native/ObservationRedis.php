<?php

namespace Current\Native;

use RuntimeException;

/** Private bounded read connection; never changes application queue connections. */
final class ObservationRedis
{
    public static function connect(string $name): mixed
    {
        $source = config('database.redis.'.$name);
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name) || config('database.redis.client', 'phpredis') !== 'phpredis'
            || (! is_array($source) && ! is_string($source))) { throw new RuntimeException('Observation requires standalone phpredis.'); }
        $parsed = (new \Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($source);
        if (in_array(strtolower($parsed['driver'] ?? ''), ['tcp', 'tls'], true)) { $parsed['scheme'] = strtolower($parsed['driver']); }
        $options = array_replace(config('database.redis.options', []), $parsed['options'] ?? []);
        if (isset($parsed['prefix'])) { $options['prefix'] = $parsed['prefix']; }
        $configuration = array_replace($parsed, $options, [
            'timeout' => 0.5, 'read_timeout' => 0.5, 'persistent' => false, 'retry_interval' => 0, 'max_retries' => 0,
            'serializer' => \Redis::SERIALIZER_NONE, 'compression' => \Redis::COMPRESSION_NONE,
        ]);
        unset($configuration['options'], $configuration['driver']);
        return (new \Illuminate\Redis\Connectors\PhpRedisConnector)->connect($configuration, []);
    }
}
