<?php

namespace Current\Telemetry;

/** Internal raw-command shapes shared with phpredis; not a general Redis client facade. */
final class PredisClient
{
    public function __construct(private \Predis\Client $client) {}

    public function eval(string $script, array $arguments, int $keys): mixed
    {
        return $this->client->eval($script, $keys, ...$arguments);
    }

    public function xAdd(string $key, string $id, array $fields): mixed
    {
        return $this->client->xadd($key, $fields, $id);
    }

    public function __call(string $method, array $arguments): mixed
    {
        $result = $this->client->{$method}(...$arguments);
        if ($result instanceof \Predis\Response\Status) { return (string) $result === 'OK' ? true : (string) $result; }
        return $result === null && strtolower($method) === 'get' ? false : $result;
    }
}
