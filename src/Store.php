<?php

namespace Current;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Store
{
    public function connection(): Connection
    {
        return DB::connection(config('current.connection'));
    }

    public function table(string $table)
    {
        return $this->connection()->table('lens_'.$table);
    }

    public function latestAttemptSql(): string
    {
        return '(SELECT latest.id FROM lens_attempts latest WHERE latest.job_id = lens_jobs.id ORDER BY latest.started_at DESC, latest.id DESC LIMIT 1)';
    }

    public function outcomeSql(): string
    {
        // History completeness is independent of the most recent execution outcome.
        // Derive this on read too, so retained records need no inferred backfill.
        return "COALESCE((SELECT CASE a.status WHEN 'released' THEN CASE WHEN lens_jobs.cleared_at IS NOT NULL AND a.finished_at <= lens_jobs.cleared_at THEN 'cleared' ELSE 'pending' END WHEN 'exception' THEN 'unknown' WHEN 'timed_out' THEN 'unknown' ELSE a.status END FROM lens_attempts a WHERE a.id = ".$this->latestAttemptSql()."), CASE lens_jobs.status WHEN 'incomplete' THEN 'unknown' WHEN 'conflicting' THEN 'unknown' WHEN 'pending' THEN CASE WHEN lens_jobs.cleared_at IS NOT NULL THEN 'cleared' ELSE 'pending' END ELSE lens_jobs.status END)";
    }

    /** Call inside the job's cycle transaction, after changing its attempt. */
    public function latestOutcome(string $jobId): string
    {
        // Keep this a non-locking, job-scoped read. MariaDB can acquire shared
        // locks on unrelated attempts when the correlated read expression above
        // is embedded in an UPDATE, deadlocking simultaneous worker starts.
        $status = $this->table('attempts')->where('job_id', $jobId)
            ->orderByDesc('started_at')->orderByDesc('id')->value('status');

        return match ($status) {
            'released' => 'pending',
            'exception', 'timed_out', null => 'unknown',
            default => $status,
        };
    }

    public function unfinishedSql(): string
    {
        return '(SELECT COUNT(*) FROM lens_attempts a WHERE a.job_id = lens_jobs.id AND a.finished_at IS NULL)';
    }

    public function earlierUnfinishedSql(): string
    {
        return '(SELECT COUNT(*) FROM lens_attempts a WHERE a.job_id = lens_jobs.id AND a.finished_at IS NULL AND a.id <> '.$this->latestAttemptSql().')';
    }

    public function jobsWithObservationState(array $columns = ['lens_jobs.*'])
    {
        return $this->table('jobs')->select($columns)
            ->selectRaw($this->outcomeSql().' AS display_status')
            ->selectRaw($this->unfinishedSql().' AS unfinished_attempts')
            ->selectRaw($this->earlierUnfinishedSql().' AS earlier_unfinished_attempts')
            ->selectRaw('(SELECT MIN(a.started_at) FROM lens_attempts a WHERE a.job_id = lens_jobs.id AND a.finished_at IS NULL) AS oldest_unfinished_at');
    }

    public function transaction(callable $callback, int $attempts = 1): mixed
    {
        return $this->connection()->transaction(function () use ($callback) {
            if ($this->connection()->getDriverName() === 'sqlite') {
                // Older Laravel ignores SQLite's transaction_mode setting. Acquire
                // the write lock before reading, avoiding a deferred read-to-write
                // upgrade that can fail immediately even with busy_timeout set.
                // A no-op UPDATE also reserves the lock when this row is absent.
                $this->table('meta')->where('key', 'collection_started_at')->update([
                    'value' => $this->connection()->raw('value'),
                ]);
            }

            return $callback();
        }, $attempts);
    }

    public function cycleTransaction(string $uuid, string $queueConnection, callable $callback): mixed
    {
        $connection = $this->connection();
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $this->transaction($callback);
        }

        // Serialize even a missing identity without locking unrelated job ranges.
        // Acquire before beginning the transaction / establishing its snapshot.
        $pdo = $connection->getPdo();
        if ($connection->transactionLevel() !== 0 || $pdo->inTransaction()) {
            throw new \RuntimeException('Current requires a separate history connection outside application transactions.');
        }
        $name = 'current-cycle:'.substr(hash('sha256', json_encode([
            $connection->getDatabaseName(), $connection->getTablePrefix(), $queueConnection, $uuid,
        ], JSON_THROW_ON_ERROR)), 0, 50);
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 1)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new \RuntimeException('Current history identity lock unavailable.');
        }

        try {
            return $this->transaction(function () use ($connection, $pdo, $callback) {
                // Laravel may reconnect while beginning a transaction. That new
                // session would not own the lock; never proceed without it.
                if ($connection->getPdo() !== $pdo) {
                    throw new \RuntimeException('Current history lock session changed.');
                }

                return $callback();
            });
        } finally {
            // Use the same physical session; no read replica or reconnect here.
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }

    /** Call inside the identity transaction. Lock absent keys too on InnoDB. */
    public function jobAction(string $uuid, string $connection, ?string $dispatch): ?object
    {
        $action = $this->table('job_actions')->where('identity_hash', Support\DispatchIdentity::fingerprint($uuid, $connection, $dispatch ?? ''))->lockForUpdate()->first();
        return $action && $action->expires_at > microtime(true) ? $action : null;
    }

    public function newJob(string $externalId, string $connection, string $queue, array $payload, string $coverage = 'observed_enqueue', ?float $clearedAt = null): string
    {
        $id = (string) Str::ulid();
        $now = microtime(true);
        $retention = Support\JobRetention::seconds($payload);
        $monitoredExpiry = ExecutionBackend::native() ? app(Native\MonitoredTags::class)->expiresAt($payload, $now) : null;
        $dispatchId = Support\DispatchIdentity::fromPayload($payload);
        $this->table('jobs')->insert(array_merge($dispatchId ? ['dispatch_id' => $dispatchId] : [], [
            'id' => $id, 'external_id' => mb_substr($externalId, 0, 100),
            'connection' => mb_substr($connection, 0, 100), 'queue' => mb_substr($queue, 0, 100),
            'job_class' => mb_substr((string) data_get($payload, 'data.commandName', 'Unknown command'), 0, 240),
            'status' => 'pending', 'coverage' => $coverage, 'cleared_at' => $clearedAt,
            'silenced' => is_bool($payload['silenced'] ?? null) ? $payload['silenced'] : null,
            'retry_of' => isset($payload['retry_of']) ? mb_substr((string) $payload['retry_of'], 0, 100) : null,
            'display_name' => is_string($payload['displayName'] ?? null) ? mb_substr($payload['displayName'], 0, 240) : null,
            'max_tries' => is_int($payload['maxTries'] ?? null) && $payload['maxTries'] >= 0 ? $payload['maxTries'] : null,
            'timeout' => is_int($payload['timeout'] ?? null) && $payload['timeout'] >= 0 ? $payload['timeout'] : null,
            'backoff' => is_string($payload['backoff'] ?? null) || is_int($payload['backoff'] ?? null) ? mb_substr((string) $payload['backoff'], 0, 240) : null,
            'retry_until' => is_numeric($payload['retryUntil'] ?? null) ? (float) $payload['retryUntil'] : null,
            'payload_created_at' => is_numeric($payload['createdAt'] ?? null) ? (float) $payload['createdAt'] : null,
            'batch_id' => is_string(data_get($payload, 'data.batchId')) ? mb_substr($payload['data']['batchId'], 0, 100) : null,
            'first_observed_at' => $now, 'observed_at' => $now,
            'expires_at' => max($now + $retention, $monitoredExpiry ?? 0),
        ]));
        if (is_bool($payload['silenced'] ?? null)) {
            $this->table('meta')->insertOrIgnore(['key' => 'silenced_capture_started_at', 'value' => (string) $now]);
        }
        foreach (app(Support\Sanitizer::class)->tags($payload['tags'] ?? []) as $tag) {
            $this->table('tags')->insert(['job_id' => $id, 'tag' => $tag]);
        }
        $this->table('meta')->insertOrIgnore(['key' => 'collection_started_at', 'value' => (string) $now]);

        return $id;
    }

    public function findCycle(string $uuid, string $connection, ?string $dispatchId = null): ?object
    {
        return $this->table('jobs')->where('external_id', $uuid)->where('connection', $connection)
            ->when($dispatchId !== null, fn ($query) => $query->where('dispatch_id', $dispatchId))
            ->where('expires_at', '>', microtime(true))->orderByDesc('first_observed_at')->orderByDesc('id')->first();
    }

    public function prune(): int
    {
        return $this->transaction(function () {
            $expired = $this->table('jobs')->where('expires_at', '<=', microtime(true))->limit(500)->pluck('id');
            $attempts = $this->table('attempts')->whereIn('job_id', $expired)->pluck('id');
            $this->table('steps')->whereIn('attempt_id', $attempts)->delete();
            $this->table('attempts')->whereIn('job_id', $expired)->delete();
            $this->table('tags')->whereIn('job_id', $expired)->delete();
            $this->table('meta')->whereIn('key', $expired->map(fn ($id) => 'retry:'.$id)->all())->delete();

            return $this->table('jobs')->whereIn('id', $expired)->delete();
        });
    }
}
