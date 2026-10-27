<?php

namespace Current\Native;

use DateTimeImmutable;
use Throwable;

/** Reads only an explicitly configured local supervisor; never infers queue health. */
final class Supervisor
{
    public function paths(): ?array
    {
        $directory = config('current.native.state_directory');
        $instance = config('current.native.instance');
        if (! is_string($directory) || ! str_starts_with($directory, '/') || str_contains($directory, "\0")
            || ! is_string($instance) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $instance)) {
            return null;
        }

        return ['health' => rtrim($directory, '/').'/'.$instance.'.json', 'socket' => rtrim($directory, '/').'/'.$instance.'.sock', 'instance' => $instance];
    }

    public function control(string $owner, string $action, ?string $pool, ?int $workers = null): bool
    {
        $paths = $this->paths();
        if (! $paths) {
            throw new \RuntimeException('Native control unavailable.');
        }
        $socket = @stream_socket_client('unix://'.$paths['socket'], $code, $message, 0.5);
        if (! $socket) {
            throw new \RuntimeException('Native control unavailable.');
        }
        try {
            stream_set_timeout($socket, 1);
            $command = ['owner' => $owner, 'action' => $action, 'pool' => $pool ?? ''];
            if ($workers !== null) { $command['workers'] = $workers; }
            $request = json_encode($command, JSON_THROW_ON_ERROR)."\n";
            if (fwrite($socket, $request) !== strlen($request)) {
                throw new \RuntimeException('Control outcome unconfirmed.');
            }
            $line = fgets($socket, 4097);
            $response = json_decode($line ?: '', true, 8, JSON_THROW_ON_ERROR);
            if (! is_bool($response['accepted'] ?? null)) {
                throw new \RuntimeException('Control outcome unconfirmed.');
            }

            return $response['accepted'];
        } finally {
            fclose($socket);
        }
    }

    public function snapshot(): array
    {
        $unavailable = ['available' => false, 'state' => 'unavailable', 'pools' => []];
        try {
            $paths = $this->paths();
            if (! $paths || is_link($paths['health']) || ! is_file($paths['health'])) {
                return $unavailable;
            }
            $file = @fopen($paths['health'], 'rb');
            if (! $file) {
                return $unavailable;
            }
            try {
                $stat = fstat($file);
                if (($stat['mode'] & 0170000) !== 0100000 || $stat['size'] > 1048576) {
                    return $unavailable;
                }
                $data = json_decode(stream_get_contents($file, 1048577), true, 32, JSON_THROW_ON_ERROR);
            } finally {
                fclose($file);
            }
            if (($data['version'] ?? null) !== 1 || ($data['instance'] ?? null) !== $paths['instance']
                || ! is_string($data['owner'] ?? null) || ! preg_match('/^[a-f0-9]{32}$/D', $data['owner'])
                || ! in_array($data['state'] ?? null, ['starting', 'running', 'degraded', 'partial', 'pausing', 'paused', 'stopping', 'stopped', 'failed'], true)
                || ! is_string($data['updated_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T.+(?:Z|[+-]\d{2}:\d{2})$/D', $data['updated_at'])
                || ! is_array($data['pools'] ?? null) || count($data['pools']) > 32 || ! $data['pools']) {
                return $unavailable;
            }
            $observed = new DateTimeImmutable($data['updated_at']);
            if (DateTimeImmutable::getLastErrors() !== false) {
                return $unavailable;
            }
            $age = microtime(true) - (float) $observed->format('U.u');
            $stale = $age < -1 || ($age > 3 && ! in_array($data['state'], ['stopped', 'failed'], true));
            $names = []; $total = 0; $pools = [];
            foreach ($data['pools'] as $pool) {
                if (! is_string($pool['name'] ?? null) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pool['name']) || isset($names[$pool['name']])
                    || ! is_int($pool['workers'] ?? null) || ! is_int($pool['min_workers'] ?? null) || ! is_int($pool['max_workers'] ?? null)
                    || $pool['min_workers'] < 1 || $pool['max_workers'] > 256 || $pool['workers'] < $pool['min_workers'] || $pool['workers'] > $pool['max_workers']
                    || (isset($pool['state']) && ! in_array($pool['state'], ['starting', 'running', 'degraded', 'pausing', 'paused', 'stopping', 'stopped', 'failed'], true))
                    || ! is_array($pool['slots'] ?? null) || count($pool['slots']) < 1 || count($pool['slots']) > $pool['max_workers']) {
                    return $unavailable;
                }
                $group = $pool['group'] ?? ''; $groupLimit = $pool['group_limit'] ?? 0; $queue = $pool['queue'] ?? '';
                if (! is_string($group) || ! is_int($groupLimit) || ! is_string($queue)
                    || ($group === '' ? ($groupLimit !== 0 || $queue !== '') : (! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $group) || $groupLimit < 1 || $groupLimit > 256 || ! preg_match('/^[a-zA-Z0-9_.:{}-]{1,128}$/D', $queue)))) { return $unavailable; }
                $connection = $pool['connection'] ?? ''; $queues = $pool['queues'] ?? [];
                if (! is_string($connection) || ! is_array($queues) || ! array_is_list($queues) || count($queues) > 32
                    || ($connection === '' ? (bool) $queues : (! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $connection) || ! $queues))) { return $unavailable; }
                foreach ($queues as $q) { if (! is_string($q) || ! preg_match('/^[a-zA-Z0-9_.:{}-]{1,128}$/D', $q)) { return $unavailable; } }
                $names[$pool['name']] = true;
                $total += $pool['workers']; $running = 0;
                foreach ($pool['slots'] as $slot) {
                    if (! in_array($slot['state'] ?? null, ['starting', 'running', 'backoff', 'stopping', 'stopped', 'paused', 'failed'], true)
                        || ! is_int($slot['pid'] ?? null) || $slot['pid'] < 0 || ! is_int($slot['starts'] ?? null) || $slot['starts'] < 0
                        || ($slot['state'] === 'running' && $slot['pid'] === 0)) {
                        return $unavailable;
                    }
                    $running += $slot['pid'] > 0 ? 1 : 0;
                }
                $pools[] = ['name' => $pool['name'], 'connection' => $connection, 'queues' => $queues, 'group' => $group, 'group_limit' => $groupLimit, 'queue' => $queue, 'workers' => $pool['workers'], 'min' => $pool['min_workers'], 'max' => $pool['max_workers'],
                    'running' => $stale ? null : $running, 'state' => $stale ? 'unknown' : ($pool['state'] ?? ($data['state'] === 'stopped' ? 'stopped' : ($running === $pool['workers'] ? 'running' : 'degraded')))];
            }
            if ($total > 256) {
                return $unavailable;
            }

            return ['available' => true, 'state' => $stale ? 'stale' : $data['state'], 'owner' => $data['owner'], 'instance' => $data['instance'],
                'updated_at' => $data['updated_at'], 'pools' => $pools];
        } catch (Throwable) {
            return $unavailable;
        }
    }
}
