<?php

namespace Current\Native;

use Current\Contracts\FailedJobMaintenance;
use Current\Support\DispatchIdentity;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\Failed\DatabaseFailedJobProvider;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Queue\Failed\FileFailedJobProvider;
use Illuminate\Queue\Failed\NullFailedJobProvider;

/** Isolate executable failed-store operations from display history and Laravel version details. */
final class FailedPayloads extends DatabaseUuidFailedJobProvider
{
    public static function customWithoutMaintenance(object $provider): bool
    {
        return ! ($provider instanceof FailedJobMaintenance) && ! in_array(get_class($provider), [DatabaseUuidFailedJobProvider::class, DatabaseFailedJobProvider::class, FileFailedJobProvider::class, DynamoDbFailedJobProvider::class, NullFailedJobProvider::class], true);
    }

    /** Explicit operator opt-in; the final find/forget gap is not atomic. */
    public static function forgetNonAtomic(object $provider, string $id, object $expected): bool
    {
        if (! self::customWithoutMaintenance($provider)) {
            return false;
        }
        $current = $provider->find($id);
        if (! is_object($current) || ! hash_equals(self::fingerprint($expected), self::fingerprint($current))) {
            return false;
        }

        return (bool) $provider->forget($id);
    }

    public static function nonAtomicScope(object $provider): string
    {
        return hash('sha256', get_class($provider).'|'.json_encode(config('queue.failed'), JSON_THROW_ON_ERROR));
    }

    private static function table(object $provider): ?Builder
    {
        if (get_class($provider) === DatabaseUuidFailedJobProvider::class) {
            return $provider->getTable();
        }
        if (get_class($provider) === DatabaseFailedJobProvider::class) {
            return NumericFailedPayloads::table($provider);
        }

        return null;
    }

    public static function findRetained(object $provider, string $uuid, ?string $connection = null, ?string $dispatch = null): ?object
    {
        if (get_class($provider) === DynamoDbFailedJobProvider::class) {
            return DynamoFailedPayloads::findRetained($provider, $uuid);
        }
        if (get_class($provider) !== DatabaseFailedJobProvider::class) {
            return $provider->find($uuid);
        }
        $escape = fn ($s) => '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s).'%';
        $query = self::table($provider)->whereRaw('payload LIKE ? ESCAPE ?', [$escape($uuid), '!']);
        if ($connection !== null) {
            $query->where('connection', $connection);
        }
        if ($dispatch !== null) {
            $query->whereRaw('payload LIKE ? ESCAPE ?', [$escape($dispatch), '!']);
        }
        foreach ($query->orderByDesc('id')->cursor() as $row) {
            $payload = json_decode($row->payload, true);
            if (is_array($payload) && ($payload['uuid'] ?? null) === $uuid && ($dispatch === null || DispatchIdentity::fromPayload($payload) === $dispatch)) {
                return $row;
            }
        }

        return null;
    }

    public static function findById(object $provider, string $id): ?object
    {
        return get_class($provider) === DynamoDbFailedJobProvider::class ? DynamoFailedPayloads::findRetained($provider, $id) : $provider->find($id);
    }

    public static function fingerprint(object $failed): string
    {
        return hash('sha256', json_encode([(string) ($failed->id ?? ''), $failed->connection ?? null, $failed->queue ?? null, hash('sha256', $failed->payload ?? ''),
            isset($failed->failed_at_timestamp) ? (string) $failed->failed_at_timestamp : (string) ($failed->failed_at ?? ''), hash('sha256', (string) ($failed->exception ?? '')),
            class_exists(DynamoFailedPayloads::class, false) ? DynamoFailedPayloads::itemHash($failed) : null], JSON_THROW_ON_ERROR));
    }

    public static function findSnapshot(object $provider, array $item): ?object
    {
        if (get_class($provider) === FileFailedJobProvider::class) {
            return FileFailedPayloads::findSnapshot($provider, $item);
        }
        $current = get_class($provider) === DynamoDbFailedJobProvider::class ? DynamoFailedPayloads::findRetained($provider, $item['id']) : $provider->find($item['id']);
        if ($current === null) {
            return null;
        }
        if (! is_object($current) || ! hash_equals($item['fingerprint'], self::fingerprint($current))) {
            throw new MaintenanceException('The retained failure changed; its replacement was preserved.');
        }

        return $current;
    }

    public static function maintenanceIdentity(object $provider, object $failed): ?array
    {
        if (! is_string($failed->payload ?? null) || strlen($failed->payload) > 1048576) {
            return null;
        }
        $p = json_decode($failed->payload, true);
        $uuid = is_array($p) ? ($p['uuid'] ?? null) : null;
        if (! is_string($uuid) || $uuid === '' || strlen($uuid) > 100) {
            return null;
        }
        if (in_array(get_class($provider), [DatabaseUuidFailedJobProvider::class, FileFailedJobProvider::class, DynamoDbFailedJobProvider::class], true) && (string) ($failed->id ?? '') !== $uuid) {
            return null;
        }

        return ['uuid' => $uuid, 'connection' => $failed->connection, 'queue' => $failed->queue, 'dispatch' => DispatchIdentity::fromPayload($p)];
    }

    public static function forgetExact(object $provider, string $uuid, object $failed): bool
    {
        $id = (string) ($failed->id ?? $uuid);
        if ($provider instanceof FailedJobMaintenance) {
            return $provider->forgetCurrentFailedExact($id, $failed);
        }
        if (get_class($provider) === FileFailedJobProvider::class) {
            return FileFailedPayloads::forgetExact($provider, $id, $failed);
        }
        if (get_class($provider) === DynamoDbFailedJobProvider::class) {
            return DynamoFailedPayloads::forgetExact($provider, $id, $failed);
        }
        $query = self::table($provider);
        if (! $query) {
            return false;
        }
        $driver = $query->getConnection()->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'], true)) {
            return false;
        }
        $identity = get_class($provider) === DatabaseFailedJobProvider::class ? ['id' => $id] : ['uuid' => $id];
        $columns = $identity + ['connection' => $failed->connection, 'queue' => $failed->queue, 'payload' => $failed->payload];
        foreach (['exception', 'failed_at'] as $column) {
            if (property_exists($failed, $column)) {
                $columns[$column] = $failed->{$column};
            }
        }
        foreach ($columns as $column => $value) {
            $wrapped = $query->getGrammar()->wrap($column);
            if ($column === 'id' || $column === 'failed_at') {
                $query->where($column, $value);
            } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
                $query->whereRaw('BINARY '.$wrapped.' = BINARY ?', [$value]);
            } elseif ($driver === 'sqlite') {
                $query->whereRaw($wrapped.' = ? COLLATE BINARY', [$value]);
            } elseif ($driver === 'sqlsrv') {
                $query->whereRaw('CONVERT(VARBINARY(MAX),'.$wrapped.') = CONVERT(VARBINARY(MAX),?)', [$value]);
            } else {
                $query->whereRaw('convert_to('.$wrapped.",'UTF8') = convert_to(CAST(? AS text),'UTF8')", [$value]);
            }
        }

        return $query->delete() === 1;
    }

    public static function snapshot(object $provider): array
    {
        if ($provider instanceof FailedJobMaintenance) {
            return ['provider' => get_class($provider), 'scope' => self::scope($provider), 'custom' => $provider->currentFailedSnapshot()];
        }
        if ($query = self::table($provider)) {
            return ['provider' => get_class($provider), 'scope' => self::scope($provider), 'upper' => (string) ($query->max('id') ?? 0)];
        }
        if (get_class($provider) === FileFailedJobProvider::class) {
            return ['provider' => get_class($provider), 'scope' => self::scope($provider)] + FileFailedPayloads::snapshot($provider);
        }
        if (get_class($provider) === DynamoDbFailedJobProvider::class) {
            // DynamoDB's Laravel failed_at has second precision. Start at the next
            // boundary so already retained same-second failures are included,
            // while records logged at/after the boundary remain outside this pass.
            $before = time() + 1;
            usleep((int) max(1, ceil(($before - microtime(true)) * 1000000)));

            return ['provider' => get_class($provider), 'scope' => self::scope($provider), 'before' => $before];
        }
        if (get_class($provider) === NullFailedJobProvider::class) {
            return ['provider' => get_class($provider), 'scope' => self::scope($provider)];
        }
        throw new MaintenanceException('This custom failed provider needs Current\\Contracts\\FailedJobMaintenance for atomic deletion and bounded paging; no unsafe UUID deletion was performed.');
    }

    public static function page(object $provider, array $snapshot, mixed $cursor, int $limit = 100): array
    {
        self::assertSnapshot($provider, $snapshot);
        if ($provider instanceof FailedJobMaintenance) {
            $page = $provider->currentFailedPage($snapshot['custom'], $cursor, $limit);
        } elseif ($query = self::table($provider)) {
            $jobs = $query->where('id', '>', (string) ($cursor ?? 0))->where('id', '<=', $snapshot['upper'])->orderBy('id')->limit($limit)->get()->all();
            $next = $jobs ? (string) end($jobs)->id : $cursor;
            if (get_class($provider) === DatabaseUuidFailedJobProvider::class) {
                foreach ($jobs as $job) {
                    $job->id = $job->uuid;
                    unset($job->uuid);
                }
            }
            $page = ['jobs' => $jobs, 'cursor' => $next, 'complete' => count($jobs) < $limit];
        } elseif (get_class($provider) === FileFailedJobProvider::class) {
            $page = FileFailedPayloads::page($provider, $snapshot, $cursor, $limit);
        } elseif (get_class($provider) === DynamoDbFailedJobProvider::class) {
            $page = DynamoFailedPayloads::page($provider, $snapshot, $cursor, $limit);
        } else {
            $page = ['jobs' => [], 'cursor' => null, 'complete' => true];
        }
        if (! is_array($page['jobs'] ?? null) || count($page['jobs']) > $limit || ! is_bool($page['complete'] ?? null) || (! $page['complete'] && ($page['cursor'] ?? null) === $cursor)) {
            throw new MaintenanceException('The failed provider returned an invalid maintenance page.');
        }

        return $page;
    }

    private static function scope(object $provider): string
    {
        if ($provider instanceof FailedJobMaintenance) {
            return hash('sha256', get_class($provider).'|'.$provider->currentFailedMaintenanceScope());
        }
        if ($query = self::table($provider)) {
            return hash('sha256', json_encode([$query->getConnection()->getName(), $query->getConnection()->getDatabaseName(), $query->from], JSON_THROW_ON_ERROR));
        }
        if (get_class($provider) === FileFailedJobProvider::class) {
            return FileFailedPayloads::scope($provider);
        }
        if (get_class($provider) === DynamoDbFailedJobProvider::class) {
            return DynamoFailedPayloads::scope($provider);
        }

        return hash('sha256', get_class($provider));
    }

    public static function assertSnapshot(object $provider, array $snapshot): void
    {
        if (($snapshot['provider'] ?? null) !== get_class($provider) || ($snapshot['scope'] ?? null) !== self::scope($provider)) {
            throw new MaintenanceException('Failed storage scope changed; no current failures were removed.');
        }
    }
}
