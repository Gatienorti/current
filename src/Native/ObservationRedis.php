<?php

namespace Current\Native;

use RuntimeException;

/** Private bounded read connection; never changes application queue connections. */
final class ObservationRedis
{
    public static function supportedClient(): bool
    {
        return match (config('database.redis.client', 'phpredis')) {
            'phpredis' => extension_loaded('redis'),
            'predis' => class_exists(\Predis\Client::class) && version_compare(\Predis\Client::VERSION, '2.3.0', '>=') && version_compare(\Predis\Client::VERSION, '4.0.0', '<'),
            default => false,
        };
    }

    public static function connect(string $name): mixed
    {
        $source = config('database.redis.'.$name);
        if (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $name) || ! self::supportedClient()
            || (! is_array($source) && ! is_string($source))) { throw new RuntimeException('Observation requires standalone phpredis or installed Predis 2.3/3.x.'); }
        $parsed = (new \Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($source);
        $options = array_replace(config('database.redis.options', []), $parsed['options'] ?? []);
        if (isset($parsed['prefix'])) { $options['prefix'] = $parsed['prefix']; }
        if (config('database.redis.client', 'phpredis') === 'predis') {
            $parsed = array_replace(is_array($options['parameters'] ?? null) ? $options['parameters'] : [], $parsed);
            $parsed = self::scheme($parsed);
            if (! is_string($parsed['host'] ?? null) || $parsed['host'] === '' || isset($parsed[0])) { throw new RuntimeException('Observation requires one standalone Redis endpoint.'); }
            $configuration = array_replace($parsed, [
                'timeout' => 0.5, 'read_write_timeout' => 0.5, 'persistent' => false, 'protocol' => 2,
            ]);
            unset($configuration['options'], $configuration['driver']);
            return (new \Illuminate\Redis\Connectors\PredisConnector)->connect($configuration, ['prefix' => $options['prefix'] ?? '', 'exceptions' => true]);
        }
        if (! is_string($parsed['host'] ?? null) || $parsed['host'] === '' || isset($parsed[0])) { throw new RuntimeException('Observation requires one standalone Redis endpoint.'); }
        $configuration = array_replace($parsed, $options, [
            'timeout' => 0.5, 'read_timeout' => 0.5, 'persistent' => false, 'retry_interval' => 0, 'max_retries' => 0,
            'serializer' => \Redis::SERIALIZER_NONE, 'compression' => \Redis::COMPRESSION_NONE,
        ]);
        $configuration = self::scheme($configuration);
        unset($configuration['options'], $configuration['driver']);
        return (new \Illuminate\Redis\Connectors\PhpRedisConnector)->connect($configuration, []);
    }

    private static function scheme(array $configuration): array
    {
        $driver = $configuration['driver'] ?? null;
        if ($driver !== null && (! is_string($driver) || ! in_array(strtolower($driver), ['tcp', 'tls'], true))) {
            throw new RuntimeException('Observation requires a supported Redis TCP/TLS URL scheme.');
        }
        $scheme = $configuration['scheme'] ?? $driver ?? 'tcp';
        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['tcp', 'tls'], true)) {
            throw new RuntimeException('Observation requires scheme=tcp or scheme=tls.');
        }
        $configuration['scheme'] = strtolower($scheme);
        return $configuration;
    }
}
