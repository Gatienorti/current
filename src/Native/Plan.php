<?php

namespace Current\Native;

use InvalidArgumentException;
use Illuminate\Support\Str;

/** PHP owns application configuration; Go receives validated commands, never PHP/.env parsing. */
final class Plan
{
    public const MAX_TIMEOUT_SECONDS = 86400;
    public const MAX_GRACE_SECONDS = 86430;

    public function pools(): array
    {
        $configured = $this->configuredPools();
        if (! is_array($configured) || ! $configured || count($configured) > 32) {
            throw new InvalidArgumentException('Configure between 1 and 32 native pools.');
        }
        $limit = $this->workerLimit();
        $pools = []; $total = 0;
        foreach ($configured as $name => $pool) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) || ! is_array($pool)) {
                throw new InvalidArgumentException('Invalid native pool name or configuration.');
            }
            if (array_diff(array_keys($pool), ['connection', 'queues', 'workers', 'min_workers', 'max_workers', 'timeout', 'memory', 'tries', 'sleep', 'backoff', 'max_jobs', 'max_time', 'rest', 'force', 'nice', 'balance', 'balance_cooldown', 'balance_shift', 'jobs_per_worker', 'target_clear_seconds', 'auto_scale_strategy', 'balance_policy'])) {
                throw new InvalidArgumentException('Unknown native pool option.');
            }
            $balance = $pool['balance'] ?? 'fixed';
            if (! in_array($balance, ['fixed', 'size', 'time', 'simple', 'auto'], true)) { throw new InvalidArgumentException('Native balance must be fixed, size, time, simple or auto.'); }
            $strategy = $pool['auto_scale_strategy'] ?? 'time';
            if (! in_array($strategy, ['size', 'time', 'log'], true)) { throw new InvalidArgumentException('Invalid native auto_scale_strategy.'); }
            $policy = $pool['balance_policy'] ?? 'horizon';
            if (! in_array($policy, ['horizon', 'bounded'], true)) { throw new InvalidArgumentException('Invalid native balance_policy.'); }
            $connection = $pool['connection'] ?? 'redis';
            if (! is_string($connection) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $connection)
                || config('queue.connections.'.$connection.'.driver') !== 'current-redis') {
                throw new InvalidArgumentException('Each native pool requires a current-redis queue connection.');
            }
            $queues = $pool['queues'] ?? [config('queue.connections.'.$connection.'.queue', 'default')];
            if (! is_array($queues) || ! array_is_list($queues) || ! $queues || count($queues) > 32) {
                throw new InvalidArgumentException('Native queues must be a nonempty ordered list (maximum 32).');
            }
            foreach ($queues as $queue) {
                if (! is_string($queue) || ! preg_match('/^[a-zA-Z0-9_.:{}-]{1,100}$/D', $queue)) {
                    throw new InvalidArgumentException('Invalid native queue name; use at most 100 characters.');
                }
            }
            if (count(array_unique($queues)) !== count($queues)) { throw new InvalidArgumentException('Duplicate queue in native pool.'); }
            $values = [];
            foreach (['workers' => [1, 1, 256], 'min_workers' => [1, 1, 256], 'max_workers' => [$pool['workers'] ?? 1, 1, 256],
                'timeout' => [60, 0, self::MAX_TIMEOUT_SECONDS], 'memory' => [128, 16, 65536], 'tries' => [3, 0, 100], 'sleep' => [1, 0, 60],
                'balance_cooldown' => [5, 1, 60], 'balance_shift' => [1, 1, 32], 'jobs_per_worker' => [10, 1, 10000], 'target_clear_seconds' => [60, 1, 3600],
                'nice' => [0, -20, 19], 'max_jobs' => [0, 0, 1000000], 'max_time' => [0, 0, 86400], 'rest' => [0, 0, 60]] as $key => [$default, $min, $max]) {
                $value = $pool[$key] ?? $default;
                if (! is_int($value) || $value < $min || $value > $max) { throw new InvalidArgumentException('Invalid native pool option: '.$key); }
                $values[$key] = $value;
            }
            $backoff = $pool['backoff'] ?? 0;
            $delays = is_array($backoff) ? $backoff : [$backoff];
            if (! array_is_list($delays) || ! $delays || count($delays) > 100) {
                throw new InvalidArgumentException('Native backoff must be an integer or a nonempty list of at most 100 delays.');
            }
            foreach ($delays as $delay) {
                if (! is_int($delay) || $delay < 0 || $delay > 86400) {
                    throw new InvalidArgumentException('Native backoff delays must be integer seconds between 0 and 86400.');
                }
            }
            $values['backoff'] = $backoff;
            $values['force'] = $pool['force'] ?? false;
            if (! is_bool($values['force'])) { throw new InvalidArgumentException('Native force must be a boolean.'); }
            $grace = config('current.native.grace_seconds', 90);
            $retry = config('queue.connections.'.$connection.'.retry_after', 60);
            $blocking = config('queue.connections.'.$connection.'.block_for');
            if ($values['workers'] < $values['min_workers'] || $values['workers'] > $values['max_workers']
                || ! is_int($grace) || $grace <= $values['timeout'] || $grace > self::MAX_GRACE_SECONDS
                || ! is_int($retry) || $retry <= $values['timeout']
                || ($blocking !== null && (! is_int($blocking) || $blocking < 1 || $blocking >= $grace))) {
                throw new InvalidArgumentException('Native worker range invalid, or timeout must be below grace/retry_after with finite Redis blocking.');
            }
            if (in_array($balance, ['simple', 'auto'], true) && $values['workers'] < count($queues) * $values['min_workers']) {
                throw new InvalidArgumentException('Balanced pools require at least min_workers for every queue.');
            }
            $total += $values['workers'];
            $pools[] = array_merge(['name' => $name, 'connection' => $connection, 'queues' => $queues, 'balance' => $balance, 'auto_scale_strategy' => $strategy, 'balance_policy' => $policy], $values);
        }
        if ($total > $limit) { throw new InvalidArgumentException('Native allocation exceeds the instance worker_limit.'); }
        return $pools;
    }

    /** Separate queue consumers for simple/auto; priority pools keep their ordered queue list. */
    public function workerPools(): array
    {
        $result = []; $names = [];
        foreach ($this->pools() as $pool) {
            $split = in_array($pool['balance'], ['simple', 'auto'], true);
            $queues = $split ? $pool['queues'] : [null];
            foreach ($queues as $index => $queue) {
                $worker = $pool;
                if ($split) {
                    $worker['name'] = substr($pool['name'], 0, 45).'--'.substr(hash('sha256', $queue), 0, 16);
                    $worker['group'] = $pool['name'];
                    $worker['group_limit'] = $pool['max_workers'];
                    $worker['queues'] = [$queue];
                    $worker['workers'] = intdiv($pool['workers'], count($queues)) + ($index < $pool['workers'] % count($queues) ? 1 : 0);
                    $worker['max_workers'] = $pool['max_workers'] - (count($queues) - 1) * $pool['min_workers'];
                }
                if (isset($names[$worker['name']])) { throw new InvalidArgumentException('Native worker pool name collision.'); }
                $names[$worker['name']] = true;
                $result[] = $worker;
            }
        }
        if (count($result) > 32) { throw new InvalidArgumentException('Native configuration exceeds 32 queue worker pools.'); }
        return $result;
    }

    private function configuredPools(): array
    {
        $defaults = config('current.native.defaults', []);
        $environments = config('current.native.environments', []);
        $configured = config('current.native.pools', []);
        if (! is_array($defaults) || ! is_array($environments) || ! is_array($configured)) {
            throw new InvalidArgumentException('Native defaults, environments and pools must be arrays.');
        }
        if ($environments) {
            if ($configured) {
                throw new InvalidArgumentException('Configure native pools or environments, not both.');
            }
            $matched = false;
            foreach ($environments as $pattern => $pools) {
                if (! is_string($pattern) || $pattern === '' || strlen($pattern) > 128 || ! is_array($pools)) {
                    throw new InvalidArgumentException('Invalid native environment configuration.');
                }
                // As in Horizon, first matching environment wins; put * last.
                if (! $matched && Str::is($pattern, app()->environment())) {
                    $configured = $pools;
                    $matched = true;
                }
            }
            if (! $matched) { throw new InvalidArgumentException('No native pools match the current application environment.'); }
        }
        $result = $defaults;
        foreach ($configured as $name => $options) {
            if ($options === false) {
                unset($result[$name]);
                continue;
            }
            if (! is_array($options) || (isset($result[$name]) && ! is_array($result[$name]))) {
                throw new InvalidArgumentException('Native pool defaults and overrides must be arrays; use false to disable a pool.');
            }
            // Replace list-valued options entirely, never retain trailing default queues/delays.
            $result[$name] = array_replace($result[$name] ?? [], $options);
        }
        return $result;
    }

    private function workerLimit(): int
    {
        $limit = config('current.native.worker_limit', 256);
        if (! is_int($limit) || $limit < 1 || $limit > 256) {
            throw new InvalidArgumentException('Native worker_limit must be an integer between 1 and 256.');
        }
        return $limit;
    }

    public function runtime(?string $environment = null): array
    {
        if ($environment !== null && ! preg_match('/^[a-zA-Z0-9_.-]{1,128}$/D', $environment)) {
            throw new InvalidArgumentException('Invalid native worker environment.');
        }
        $paths = app(Supervisor::class)->paths();
        if (! $paths) { throw new InvalidArgumentException('Configure an absolute native state_directory and valid instance name.'); }
        $pools = [];
        foreach ($this->workerPools() as $pool) {
            $command = [PHP_BINARY, base_path('artisan'), 'queue:work', $pool['connection'], '--queue='.implode(',', $pool['queues']), '--name='.($pool['group'] ?? $pool['name']), '--no-interaction', '--quiet'];
            foreach (['timeout', 'memory', 'tries', 'sleep', 'backoff', 'max_jobs', 'max_time', 'rest'] as $key) {
                $value = is_array($pool[$key]) ? implode(',', $pool[$key]) : $pool[$key];
                $command[] = '--'.str_replace('_', '-', $key).'='.$value;
            }
            if ($pool['force']) { $command[] = '--force'; }
            if ($environment !== null) { $command[] = '--env='.$environment; }
            if ($pool['nice'] !== 0) {
                $nice = null;
                foreach (['/usr/bin/nice', '/bin/nice'] as $candidate) {
                    if (is_executable($candidate)) { $nice = $candidate; break; }
                }
                if ($nice === null) { throw new InvalidArgumentException('Native nice requires /usr/bin/nice or /bin/nice.'); }
                $command = array_merge([$nice, '-n', (string) $pool['nice']], $command);
            }
            $pools[] = ['name' => $pool['name'], 'workers' => $pool['workers'], 'min_workers' => $pool['min_workers'], 'max_workers' => $pool['max_workers'],
                'directory' => base_path(), 'command' => $command, 'connection' => $pool['connection'], 'queues' => $pool['queues'],
                'group' => $pool['group'] ?? '', 'group_limit' => $pool['group_limit'] ?? 0, 'queue' => isset($pool['group']) ? $pool['queues'][0] : ''];
        }
        return ['version' => 1, 'instance' => $paths['instance'], 'state_directory' => dirname($paths['health']),
            'grace_seconds' => config('current.native.grace_seconds', 90), 'worker_limit' => $this->workerLimit(), 'pools' => $pools];
    }
}
