<?php

namespace HorizonLens;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Store
{
    public function connection(): Connection
    {
        return DB::connection(config('horizon-lens.connection'));
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
        return "COALESCE((SELECT CASE a.status WHEN 'released' THEN 'pending' WHEN 'exception' THEN 'unknown' WHEN 'timed_out' THEN 'unknown' ELSE a.status END FROM lens_attempts a WHERE a.id = ".$this->latestAttemptSql()."), CASE lens_jobs.status WHEN 'incomplete' THEN 'unknown' WHEN 'conflicting' THEN 'unknown' ELSE lens_jobs.status END)";
    }

    public function unfinishedSql(): string
    {
        return '(SELECT COUNT(*) FROM lens_attempts a WHERE a.job_id = lens_jobs.id AND a.finished_at IS NULL)';
    }

    public function earlierUnfinishedSql(): string
    {
        return '(SELECT COUNT(*) FROM lens_attempts a WHERE a.job_id = lens_jobs.id AND a.finished_at IS NULL AND a.id <> '.$this->latestAttemptSql().')';
    }

    public function jobsWithObservationState()
    {
        return $this->table('jobs')->select('lens_jobs.*')
            ->selectRaw($this->outcomeSql().' AS display_status')
            ->selectRaw($this->unfinishedSql().' AS unfinished_attempts')
            ->selectRaw($this->earlierUnfinishedSql().' AS earlier_unfinished_attempts')
            ->selectRaw('(SELECT MIN(a.started_at) FROM lens_attempts a WHERE a.job_id = lens_jobs.id AND a.finished_at IS NULL) AS oldest_unfinished_at');
    }

    public function transaction(callable $callback): mixed
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
        });
    }

    public function newJob(string $externalId, string $connection, string $queue, array $payload, string $coverage = 'observed_enqueue'): string
    {
        $id = (string) Str::ulid();
        $now = microtime(true);
        $retention = max(1, min((int) config('horizon-lens.retention_days', 7), (int) config('horizon-lens.max_retention_days', 30)));
        $this->table('jobs')->insert([
            'id' => $id, 'external_id' => mb_substr($externalId, 0, 100),
            'connection' => mb_substr($connection, 0, 100), 'queue' => mb_substr($queue, 0, 100),
            'job_class' => mb_substr((string) data_get($payload, 'data.commandName', 'Unknown command'), 0, 240),
            'status' => 'pending', 'coverage' => $coverage,
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
            'expires_at' => $now + $retention * 86400,
        ]);
        if (is_bool($payload['silenced'] ?? null)) {
            $this->table('meta')->insertOrIgnore(['key' => 'silenced_capture_started_at', 'value' => (string) $now]);
        }
        foreach (app(Support\Sanitizer::class)->tags($payload['tags'] ?? []) as $tag) {
            $this->table('tags')->insert(['job_id' => $id, 'tag' => $tag]);
        }
        $this->table('meta')->insertOrIgnore(['key' => 'collection_started_at', 'value' => (string) $now]);

        return $id;
    }

    public function findCycle(string $uuid, string $connection): ?object
    {
        return $this->table('jobs')->where('external_id', $uuid)->where('connection', $connection)
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
