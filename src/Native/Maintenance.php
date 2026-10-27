<?php

namespace Current\Native;

use Current\Store;
use Current\Support\DispatchIdentity;

/** Administrative history changes. Native dispatch identity scopes every mutation. */
final class Maintenance
{
    public function assertCollectorCompatible(): void
    {
        $store = app(Store::class); $connection = $store->connection(); $busy = false;
        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $key = substr('current:'.hash('sha256', $connection->getDatabaseName()), 0, 63);
            $busy = $connection->selectOne('SELECT IS_USED_LOCK(?) AS owner', [$key])->owner !== null;
        } elseif ($connection->getDriverName() === 'sqlite') {
            $path = $connection->getDatabaseName().'.collector.lock';
            if (is_file($path)) {
                $file = fopen($path, 'r+');
                if (! $file) { throw new MaintenanceException('Collector ownership could not be checked.'); }
                try { $busy = ! flock($file, LOCK_EX | LOCK_NB); if (! $busy) { flock($file, LOCK_UN); } }
                finally { fclose($file); }
            }
        }
        if (! $busy) { return; }
        $redis = app(\Current\Telemetry\Transport::class)->client();
        $owner = $redis->get('owner'); $health = $redis->hGetAll('health');
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
        $hash = DispatchIdentity::fingerprint($uuid, $connection, $dispatch);
        $old = $store->table('job_actions')->where('identity_hash', $hash)->lockForUpdate()->first();
        if ($old && $old->expires_at > microtime(true) && $old->kind === 'forgotten' && $kind !== 'forgotten') { return; }
        $store->table('job_actions')->updateOrInsert(['identity_hash' => $hash], ['kind' => $kind, 'job_id' => $jobId,
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
                $this->action($store, $identity['uuid'], $connection, $identity['dispatch'], 'cleared', (float) $record['observed_at']);
                $store->table('jobs')->where('external_id', $identity['uuid'])->where('connection', $connection)->where('dispatch_id', $identity['dispatch'])->where('queue', $record['queue'])
                    ->update(['cleared_at' => (float) $record['observed_at']]);
            }
        }, 3);
    }

    public function forget(string $id, bool $historyOnly): void
    {
        $this->assertCollectorCompatible();
        $store = app(Store::class);
        $job = $store->table('jobs')->where('id', $id)->where('expires_at', '>', microtime(true))->first();
        if (! $job || ! DispatchIdentity::fromPayload(['_current_dispatch_id' => $job->dispatch_id ?? null])
            || config('queue.connections.'.$job->connection.'.driver') !== 'current-redis') {
            throw new MaintenanceException('No retained native dispatch matches that Current history ID.');
        }
        $payloadDeletionAttempted = false;
        try {
            $store->cycleTransaction($job->external_id, $job->connection, function () use ($store, $job, $historyOnly, &$payloadDeletionAttempted) {
                // Lock order matches the async projector: action identity before job rows.
                $this->action($store, $job->external_id, $job->connection, $job->dispatch_id, 'forgotten', microtime(true), $job->id);
                $current = $store->jobsWithObservationState()->where('id', $job->id)->lockForUpdate()->first();
                if (! $current || $current->display_status !== 'failed' || $current->unfinished_attempts > 0) {
                    throw new MaintenanceException('Only terminal failed histories without unfinished attempts can be forgotten.');
                }
                $claim = json_decode($store->table('meta')->where('key', 'retry:'.$job->id)->value('value') ?? '{}', true);
                if (($claim['state'] ?? '') === 'requested') { throw new MaintenanceException('A retry is unconfirmed; resolve it before forgetting this history.'); }
                $identityClaim = $store->table('native_retry_claims')->where('identity_hash', DispatchIdentity::fingerprint($job->external_id, $job->connection, $job->dispatch_id))
                    ->where('expires_at', '>', microtime(true))->value('value');
                if ((json_decode($identityClaim ?? '{}', true)['state'] ?? '') === 'requested') {
                    throw new MaintenanceException('A retry is unconfirmed; resolve it before forgetting this history.');
                }
                if (! $historyOnly) {
                    $failed = app('queue.failer')->find($current->external_id);
                    if (is_string($failed->payload ?? null)) { $current->failed_payload_hash = hash('sha256', $failed->payload); }
                    if (! app(Retry::class)->available($current)) { throw new MaintenanceException('Laravel no longer retains this exact failed dispatch; use --history-only to retain its current failed payload.'); }
                    $payloadDeletionAttempted = true;
                    if (! FailedPayloads::forgetExact(app('queue.failer'), $current->external_id, $failed)) { throw new MaintenanceException('Exact failed-payload removal was not confirmed; custom failed providers require --history-only.'); }
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
