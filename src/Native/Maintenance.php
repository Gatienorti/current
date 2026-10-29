<?php

namespace Current\Native;

use Current\Store;
use Current\Support\DispatchIdentity;
use Current\Telemetry\Transport;
use Illuminate\Queue\Failed\DatabaseUuidFailedJobProvider;
use Illuminate\Queue\Failed\DynamoDbFailedJobProvider;
use Illuminate\Queue\Failed\FileFailedJobProvider;

/** Administrative history changes. Native dispatch identity scopes every mutation. */
final class Maintenance
{
    public function assertCollectorCompatible(): void
    {
        $store = app(Store::class);
        $connection = $store->connection();
        $busy = false;
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $key = substr('current:'.hash('sha256', $connection->getDatabaseName()), 0, 63);
            $busy = $connection->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$key])->owner !== null;
        } elseif ($connection->getDriverName() === 'pgsql') {
            $pdo = $connection->getPdo();
            $key = Store::advisoryKey('current:'.$connection->getDatabaseName());
            $probe = $pdo->prepare('SELECT pg_try_advisory_lock(CAST(? AS BIGINT))');
            $probe->execute([$key]);
            $busy = ! filter_var($probe->fetchColumn(), FILTER_VALIDATE_BOOLEAN);
            if (! $busy) {
                $release = $pdo->prepare('SELECT pg_advisory_unlock(CAST(? AS BIGINT))');
                $release->execute([$key]);
            }
        } elseif ($connection->getDriverName() === 'sqlite') {
            $path = $connection->getDatabaseName().'.collector.lock';
            if (is_file($path)) {
                $file = fopen($path, 'r+');
                if (! $file) {
                    throw new MaintenanceException('Collector ownership could not be checked.');
                }
                try {
                    $busy = ! flock($file, LOCK_EX | LOCK_NB);
                    if (! $busy) {
                        flock($file, LOCK_UN);
                    }
                } finally {
                    fclose($file);
                }
            }
        }
        if (! $busy) {
            return;
        }
        $redis = app(Transport::class)->client();
        $owner = $redis->get('owner');
        $health = $redis->hGetAll('health');
        if (! $owner || ($health['owner_token'] ?? null) !== $owner || (int) ($health['maintenance_protocol'] ?? 0) !== 1
            || microtime(true) - (float) ($health['heartbeat_at'] ?? 0) > 15) {
            throw new MaintenanceException('Stop or upgrade the active collector before native history maintenance.');
        }
    }

    public function expiry(float $observed): float
    {
        return $observed + max(1, min(366, (int) config('current.max_retention_days', 30))) * 86400 + 86400;
    }

    private function action(Store $store, string $uuid, string $connection, string $dispatch, string $kind, float $observed, ?string $jobId = null): void
    {
        if ($store->connection()->getDriverName() === 'pgsql') { $store->jobAction($uuid, $connection, $dispatch); }
        $hash = DispatchIdentity::fingerprint($uuid, $connection, $dispatch);
        $old = $store->table('job_actions')->where('identity_hash', $hash)->lockForUpdate()->first();
        if ($old && $old->expires_at > microtime(true) && $old->kind === 'forgotten' && $kind !== 'forgotten') {
            return;
        }
        $store->table('job_actions')->updateOrInsert(['identity_hash' => $hash], ['kind' => $kind, 'job_id' => $jobId ?? ($old->job_id ?? null),
            'observed_at' => max($observed, (float) ($old->observed_at ?? 0)), 'expires_at' => max($this->expiry($observed), (float) ($old->expires_at ?? 0))]);
    }

    public function cleared(string $connection, array $record): void
    {
        $store = app(Store::class);
        $identities = $record['identities'];
        usort($identities, fn ($a, $b) => strcmp(DispatchIdentity::fingerprint($a['uuid'], $connection, $a['dispatch']), DispatchIdentity::fingerprint($b['uuid'], $connection, $b['dispatch'])));
        // SQL-only retries are safe: the Redis operation is already committed and idempotent.
        $store->transaction(function () use ($store, $connection, $record, $identities) {
            foreach ($identities as $identity) {
                $store->jobAction($identity['uuid'], $connection, $identity['dispatch']);
                $active = $store->jobsWithObservationState()->where('external_id', $identity['uuid'])->where('connection', $connection)
                    ->when($identity['dispatch'] === '', fn ($q) => $q->whereNull('dispatch_id'), fn ($q) => $q->where('dispatch_id', $identity['dispatch']))
                    ->whereRaw($store->unfinishedSql().' > 0')->exists();
                if ($active) {
                    continue;
                }
                $this->action($store, $identity['uuid'], $connection, $identity['dispatch'], 'cleared', (float) $record['observed_at']);
                $store->table('jobs')->where('external_id', $identity['uuid'])->where('connection', $connection)
                    ->when($identity['dispatch'] === '', fn ($q) => $q->whereNull('dispatch_id'), fn ($q) => $q->where('dispatch_id', $identity['dispatch']))->where('queue', $record['queue'])
                    ->update(['cleared_at' => (float) $record['observed_at']]);
            }
        }, 3);
    }

    public function forget(string $id, bool $historyOnly, bool $allowNonAtomic = false, ?string $expectedFingerprint = null): void
    {
        if (! $historyOnly && ! $allowNonAtomic && FailedPayloads::customWithoutMaintenance(app('queue.failer'))) {
            throw new MaintenanceException('Custom provider needs FailedJobMaintenance or explicit --allow-nonatomic-provider; nothing was removed.');
        }
        $this->assertCollectorCompatible();
        $store = app(Store::class);
        $job = $store->table('jobs')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        if (! $job || (($job->dispatch_id ?? null) !== null && ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $job->dispatch_id]))
            || config('queue.connections.'.$job->connection.'.driver') !== 'current-redis') {
            throw new MaintenanceException('No retained native dispatch matches that Current history ID.');
        }
        $payloadDeletionAttempted = false;
        try {
            $store->cycleTransaction($job->external_id, $job->connection, function () use ($store, $job, $historyOnly, $allowNonAtomic, $expectedFingerprint, &$payloadDeletionAttempted) {
                // Lock order matches the async projector: action identity before job rows.
                $this->action($store, $job->external_id, $job->connection, $job->dispatch_id ?? '', 'forgotten', microtime(true), $job->id);
                $current = $store->jobsWithObservationState()->where('id', $job->id)->lockForUpdate()->first();
                if (! $current || $current->display_status !== 'failed' || $current->unfinished_attempts > 0) {
                    throw new MaintenanceException('Only terminal failed histories without unfinished attempts can be forgotten.');
                }
                $claim = json_decode($store->table('meta')->where('key', 'retry:'.$job->id)->value('value') ?? '{}', true);
                if (($claim['state'] ?? '') === 'requested') {
                    throw new MaintenanceException('A retry is unconfirmed; resolve it before forgetting this history.');
                }
                $identityClaim = $store->table('native_retry_claims')->where('identity_hash', DispatchIdentity::fingerprint($job->external_id, $job->connection, $job->dispatch_id ?? ''))
                    ->where('expires_at', '>', microtime(true))->value('value');
                if ((json_decode($identityClaim ?? '{}', true)['state'] ?? '') === 'requested') {
                    throw new MaintenanceException('A retry is unconfirmed; resolve it before forgetting this history.');
                }
                if (! $historyOnly) {
                    $failed = FailedPayloads::findRetained(app('queue.failer'), $current->external_id, $current->connection, $current->dispatch_id ?? null);
                    if ($expectedFingerprint !== null && (! is_object($failed) || ! hash_equals($expectedFingerprint, FailedPayloads::fingerprint($failed)))) {
                        throw new MaintenanceException('The retained failure changed; its replacement was preserved.');
                    }
                    if (is_string($failed->payload ?? null)) {
                        $current->failed_payload_hash = hash('sha256', $failed->payload);
                    }
                    if (! app(Retry::class)->available($current)) {
                        throw new MaintenanceException('Laravel no longer retains this exact failed dispatch; use --history-only to retain its current failed payload.');
                    }
                    $payloadDeletionAttempted = true;
                    $removed = $allowNonAtomic && FailedPayloads::customWithoutMaintenance(app('queue.failer'))
                        ? FailedPayloads::forgetNonAtomic(app('queue.failer'), (string) ($failed->id ?? $current->external_id), $failed)
                        : FailedPayloads::forgetExact(app('queue.failer'), $current->external_id, $failed);
                    if (! $removed) {
                        throw new MaintenanceException('Exact failed-payload removal was not confirmed; custom failed providers require --history-only.');
                    }
                }
                $this->deleteCapturedJob($store, $job->id);
                // Keep a bounded retry claim until the action marker expires, blocking old requests.
                $store->table('meta')->updateOrInsert(['key' => 'retry:'.$job->id], ['value' => '{"state":"forgotten"}']);
            });
        } catch (\Throwable $e) {
            if ($payloadDeletionAttempted) {
                throw new MaintenanceException('Laravel failed-payload removal may have succeeded; history removal is unconfirmed. Inspect storage and use --history-only to reconcile.', previous: $e);
            }
            throw $e;
        }
    }

    /** Laravel's retained failure is authoritative when Current did not capture it. */
    public function forgetFailed(object $failed, bool $allowNonAtomic = false): array
    {
        if (! $allowNonAtomic && FailedPayloads::customWithoutMaintenance(app('queue.failer'))) {
            throw new MaintenanceException('Custom provider needs FailedJobMaintenance or explicit --allow-nonatomic-provider; nothing was removed.');
        }
        $this->assertCollectorCompatible();
        $connection = $failed->connection ?? null;
        $queue = $failed->queue ?? null;
        if (! is_string($connection) || ! is_string($queue) || ! is_string($failed->payload ?? null)
            || config('queue.connections.'.$connection.'.driver') !== 'current-redis'
            || ! in_array($connection, config('current.queue_connections', []), true)) {
            throw new MaintenanceException('The failed payload is outside observed native connections.');
        }
        $payload = strlen($failed->payload) <= 1048576 ? json_decode($failed->payload, true) : null;
        $uuid = is_array($payload) && is_string($payload['uuid'] ?? null) && strlen($payload['uuid']) <= 100 && $payload['uuid'] !== '' ? $payload['uuid'] : null;
        $dispatch = is_array($payload) ? DispatchIdentity::fromPayload($payload) : null;
        $provider = app('queue.failer');
        if (in_array(get_class($provider), [DatabaseUuidFailedJobProvider::class, FileFailedJobProvider::class, DynamoDbFailedJobProvider::class], true)
            && (string) ($failed->id ?? '') !== $uuid) {
            $uuid = null;
        }
        if ($uuid === null) {
            $removed = $allowNonAtomic && FailedPayloads::customWithoutMaintenance($provider)
                ? FailedPayloads::forgetNonAtomic($provider, (string) ($failed->id ?? ''), $failed)
                : FailedPayloads::forgetExact($provider, (string) ($failed->id ?? ''), $failed);
            if (! $removed) {
                throw new MaintenanceException('Exact failed-payload removal was not confirmed.');
            }

            return ['payloads' => 1, 'histories' => 0, 'untracked' => 1];
        }
        $histories = $this->retireFailedIdentity(['uuid' => $uuid, 'connection' => $connection, 'queue' => $queue, 'dispatch' => $dispatch]);
        // The replay tombstone and capture removal commit before external I/O.
        // A lost delete reply cannot allow buffered observations to recreate history.
        try {
            $removed = $allowNonAtomic && FailedPayloads::customWithoutMaintenance($provider)
                ? FailedPayloads::forgetNonAtomic($provider, (string) ($failed->id ?? $uuid), $failed)
                : FailedPayloads::forgetExact($provider, $uuid, $failed);
            if (! $removed) {
                throw new MaintenanceException('Exact cleanup was not confirmed.');
            }
        } catch (\Throwable $error) {
            throw new MaintenanceException('Failed history was removed; executable-payload cleanup is unconfirmed or the retained payload changed. Inspect Laravel failed storage.', previous: $error);
        }

        return ['payloads' => 1, 'histories' => $histories, 'untracked' => 0];
    }

    /** A durable maintenance receipt may confirm the executable record is already absent. */
    public function retireFailedIdentity(array $identity): int
    {
        $uuid = $identity['uuid'] ?? null;
        $connection = $identity['connection'] ?? null;
        $queue = $identity['queue'] ?? null;
        $dispatch = $identity['dispatch'] ?? null;
        if (! is_string($uuid) || $uuid === '' || strlen($uuid) > 100 || ! is_string($connection) || ! is_string($queue)
            || ($dispatch !== null && ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $dispatch]))
            || config('queue.connections.'.$connection.'.driver') !== 'current-redis' || ! in_array($connection, config('current.queue_connections', []), true)) {
            throw new MaintenanceException('Maintenance identity is unavailable.');
        }
        $store = app(Store::class);

        return $store->cycleTransaction($uuid, $connection, function () use ($store, $uuid, $connection, $dispatch, $queue) {
            $this->action($store, $uuid, $connection, $dispatch ?? '', 'forgotten', microtime(true));
            $jobs = $store->jobsWithObservationState()->where('external_id', $uuid)->where('connection', $connection)->where('queue', $queue)
                ->when($dispatch === null, fn ($q) => $q->whereNull('dispatch_id'), fn ($q) => $q->where('dispatch_id', $dispatch))->lockForUpdate()->get();
            if ($jobs->contains(fn ($job) => $job->unfinished_attempts > 0)) {
                throw new MaintenanceException('A captured attempt is unfinished; its failed payload and history were retained.');
            }
            $claim = $store->table('native_retry_claims')->where('identity_hash', DispatchIdentity::fingerprint($uuid, $connection, $dispatch ?? ''))->where('expires_at', '>', microtime(true))->value('value');
            if ((json_decode($claim ?? '{}', true)['state'] ?? '') === 'requested') {
                throw new MaintenanceException('A retry is unconfirmed; its failed payload and history were retained.');
            }
            foreach ($jobs as $job) {
                $legacy = $store->table('meta')->where('key', 'retry:'.$job->id)->value('value');
                if ((json_decode($legacy ?? '{}', true)['state'] ?? '') === 'requested') {
                    throw new MaintenanceException('A retry is unconfirmed; its failed payload and history were retained.');
                }
            }
            $removed = 0;
            foreach ($jobs as $job) {
                if ($job->display_status === 'completed') {
                    continue;
                }
                $this->deleteCapturedJob($store, $job->id);
                $removed++;
                $store->table('meta')->where('key', 'retry:'.$job->id)->delete();
            }

            return $removed;
        });
    }

    private function deleteCapturedJob(Store $store, string $id): void
    {
        do {
            $attempts = $store->table('attempts')->where('job_id', $id)->limit(100)->pluck('id');
            if ($attempts->isNotEmpty()) {
                $store->table('steps')->whereIn('attempt_id', $attempts)->delete();
                $store->table('attempts')->whereIn('id', $attempts)->delete();
            }
        } while ($attempts->isNotEmpty());
        $store->table('tags')->where('job_id', $id)->delete();
        $store->table('webhook_jobs')->where('job_id', $id)->delete();
        $store->table('jobs')->where('id', $id)->delete();
    }
}
