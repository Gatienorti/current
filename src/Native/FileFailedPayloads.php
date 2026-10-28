<?php

namespace Current\Native;

use Illuminate\Queue\Failed\FileFailedJobProvider;

/** Uses the same cross-process cache lock as Laravel's built-in file failer. */
final class FileFailedPayloads extends FileFailedJobProvider
{
    public static function scope(object $provider): string
    {
        return hash('sha256', $provider->path);
    }

    private static function key(object $job): string
    {
        return FailedPayloads::fingerprint($job);
    }

    public static function snapshot(object $provider): array
    {
        if (! $provider->lockProviderResolver) {
            throw new MaintenanceException('The file failed provider needs Laravel\'s shared lock resolver for safe maintenance.');
        }

        return $provider->lock(function () use ($provider) {
            $jobs = $provider->read();

            return ['anchor' => $jobs ? self::key($jobs[0]) : null];
        });
    }

    public static function findSnapshot(object $provider, array $item): ?object
    {
        return $provider->lock(function () use ($provider, $item) {
            $found = false;
            foreach ($provider->read() as $row) {
                if ((string) $row->id !== $item['id']) {
                    continue;
                }
                $found = true;
                if (hash_equals($item['fingerprint'], FailedPayloads::fingerprint($row))) {
                    return $row;
                }
            }
            if ($found) {
                throw new MaintenanceException('The file failure changed; its replacement was preserved.');
            }

            return null;
        });
    }

    public static function forgetExact(object $provider, string $id, object $expected): bool
    {
        if (! $provider->lockProviderResolver) {
            return false;
        }

        return $provider->lock(function () use ($provider, $id, $expected) {
            $jobs = $provider->read();
            $kept = [];
            $removed = false;
            foreach ($jobs as $job) {
                if ((string) $job->id === $id && $job->connection === $expected->connection && $job->queue === $expected->queue && hash_equals($expected->payload, $job->payload)
                    && ($job->failed_at_timestamp ?? null) === ($expected->failed_at_timestamp ?? null)
                    && (string) ($job->exception ?? '') === (string) ($expected->exception ?? '')) {
                    $removed = true;

                    continue;
                }
                $kept[] = $job;
            }
            if ($removed) {
                $provider->write($kept);
            }

            return $removed;
        });
    }

    public static function page(object $provider, array $snapshot, mixed $cursor, int $limit): array
    {
        if ($snapshot['anchor'] === null) {
            return ['jobs' => [], 'cursor' => null, 'complete' => true];
        }

        return $provider->lock(function () use ($provider, $snapshot, $cursor, $limit) {
            $jobs = $provider->read();
            $anchor = $cursor ?? $snapshot['anchor'];
            $start = null;
            foreach ($jobs as $index => $job) {
                if (self::key($job) === $anchor) {
                    $start = $index;
                    break;
                }
            }
            if ($start === null) {
                throw new MaintenanceException('The file snapshot continuation changed or expired; review before starting a new pass.');
            }
            $page = array_slice($jobs, $start, $limit);
            $next = $jobs[$start + $limit] ?? null;

            return ['jobs' => $page, 'cursor' => $next ? self::key($next) : null, 'complete' => $next === null];
        });
    }
}
