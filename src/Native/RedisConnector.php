<?php

namespace Current\Native;

use Current\ExecutionBackend;
use Illuminate\Queue\Connectors\RedisConnector as LaravelRedisConnector;
use LogicException;

final class RedisConnector extends LaravelRedisConnector
{
    public function connect(array $config)
    {
        if (! ExecutionBackend::native()) {
            throw new LogicException('The current-redis driver requires the explicit native execution backend.');
        }
        return new RedisQueue($this->redis, $config['queue'], $config['connection'] ?? $this->connection,
            $config['retry_after'] ?? 60, $config['block_for'] ?? null, $config['after_commit'] ?? null, $config['migration_batch_size'] ?? -1);
    }
}
