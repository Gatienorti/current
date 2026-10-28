<?php

namespace Current\Native;

use Current\Store;
use Current\Support\DispatchIdentity;
use Illuminate\Support\Str;

/** A bounded cursor pass over captured failures and Laravel's retained executable store. */
final class BulkForget
{
    private string $owner;

    private int $revision;

    public function run(string $operation, bool $resume, bool $historyOnly = false, int $maxChunks = 0, bool $allowNonAtomic = false): array
    {
        if (! DispatchIdentity::fromPayload(['_current_dispatch_id' => $operation]) || $maxChunks < 0) {
            throw new MaintenanceException('Choose a valid maintenance operation ID.');
        }
        $store = app(Store::class);
        $maintenance = app(Maintenance::class);
        $maintenance->assertCollectorCompatible();
        $row = $store->table('maintenance_operations')->where('id', $operation)->first();
        if (! $row) {
            if ($resume) {
                throw new MaintenanceException('Operation expired or unavailable; no current failures were removed.');
            }
            $connections = array_values(array_filter(config('current.queue_connections', []), fn ($name) => is_string($name) && config('queue.connections.'.$name.'.driver') === 'current-redis'));
            $nonAtomic = ! $historyOnly && $allowNonAtomic && FailedPayloads::customWithoutMaintenance(app('queue.failer'));
            $snapshot = $historyOnly || $nonAtomic ? null : FailedPayloads::snapshot(app('queue.failer'));
            $state = ['operation' => $operation, 'history_only' => $historyOnly, 'connections' => $connections, 'started_at' => $snapshot['before'] ?? microtime(true),
                'history_upper' => $store->table('jobs')->max('id'), 'history_cursor' => '', 'failed_cursor' => null,
                'failed_snapshot' => $snapshot, 'allow_nonatomic_provider' => $allowNonAtomic, 'non_atomic' => $nonAtomic, 'provider_scope' => $nonAtomic ? FailedPayloads::nonAtomicScope(app('queue.failer')) : null,
                'phase' => 'history', 'complete' => false, 'histories' => 0, 'payloads' => 0, 'untracked' => 0, 'blocked' => 0, 'blocked_examples' => []];
            $store->table('maintenance_operations')->insertOrIgnore(['id' => $operation, 'kind' => 'forget_all', 'value' => $this->encode($state), 'expires_at' => $maintenance->expiry(microtime(true))]);
            $row = $store->table('maintenance_operations')->where('id', $operation)->first();
        }
        if ($row->kind !== 'forget_all' || $row->expires_at <= microtime(true)) {
            throw new MaintenanceException('Operation expired or unavailable; start a new pass explicitly.');
        }
        $this->owner = (string) Str::ulid();
        $row = $store->transaction(function () use ($store, $operation) {
            $current = $store->table('maintenance_operations')->where('id', $operation)->lockForUpdate()->first();
            if (! $current || $current->expires_at <= microtime(true) || ($current->owner_token && $current->lease_until > microtime(true))) {
                throw new MaintenanceException('This maintenance operation is active or expired; do not overlap resumes.');
            }
            $store->table('maintenance_operations')->where('id', $operation)->update(['owner_token' => $this->owner, 'lease_until' => microtime(true) + 30]);

            return $current;
        });
        $this->revision = (int) $row->revision;
        try {
            $state = json_decode($row->value, true, 32, JSON_THROW_ON_ERROR);
            if (($state['operation'] ?? null) !== $operation || ($state['history_only'] ?? null) !== $historyOnly || ($state['allow_nonatomic_provider'] ?? false) !== $allowNonAtomic) {
                throw new MaintenanceException('Resume with the original maintenance scope.');
            }

            return $this->process($store, $maintenance, $state, $maxChunks);
        } finally {
            $store->table('maintenance_operations')->where('id', $operation)->where('owner_token', $this->owner)->update(['owner_token' => null, 'lease_until' => null]);
        }
    }

    private function process(Store $store, Maintenance $maintenance, array $state, int $maxChunks): array
    {
        $chunks = 0;
        while (! $state['complete'] && ($maxChunks === 0 || $chunks < $maxChunks)) {
            $this->save($store, $state);
            if ($state['phase'] === 'history') {
                if (($state['non_atomic'] ?? false) && ! hash_equals($state['provider_scope'], FailedPayloads::nonAtomicScope(app('queue.failer')))) {
                    throw new MaintenanceException('Custom failed-provider scope changed; no later failures were removed.');
                }
                if (isset($state['history_pending'])) {
                    $this->reconcileNonAtomic($store, $maintenance, $state);
                }
                $jobs = $store->jobsWithObservationState()->whereIn('connection', $state['connections'])->where('id', '>', $state['history_cursor'])
                    ->where('id', '<=', $state['history_upper'] ?? '')->where('observed_at', '<=', $state['started_at'])->where('expires_at', '>', microtime(true))
                    ->whereRaw($store->outcomeSql()." = 'failed'")->orderBy('id')->limit(100)->get();
                foreach ($jobs as $job) {
                    // A slow query may outlive the lease. Fence the next item before
                    // invoking any provider or changing captured history.
                    $this->save($store, $state);
                    if ($state['non_atomic'] ?? false) {
                        $this->forgetNonAtomicCaptured($store, $maintenance, $state, $job);

                        continue;
                    }
                    try {
                        $maintenance->forget($job->id, true);
                        $state['histories']++;
                    } catch (\Throwable) {
                        $this->blocked($state, $job->id);
                    }
                    $state['history_cursor'] = $job->id;
                    $this->save($store, $state);
                }
                if ($jobs->count() < 100) {
                    $state['phase'] = $state['history_only'] || ($state['non_atomic'] ?? false) ? 'done' : 'failed';
                }
            } elseif ($state['phase'] === 'failed') {
                $provider = app('queue.failer');
                FailedPayloads::assertSnapshot($provider, $state['failed_snapshot']);
                if (! isset($state['pending'])) {
                    $page = FailedPayloads::page($provider, $state['failed_snapshot'], $state['failed_cursor']);
                    $state['pending'] = [];
                    foreach ($page['jobs'] as $failed) {
                        if (! is_object($failed) || ! in_array($failed->connection ?? null, $state['connections'], true)) {
                            continue;
                        }
                        $state['pending'][] = ['id' => (string) $failed->id, 'fingerprint' => FailedPayloads::fingerprint($failed), 'identity' => FailedPayloads::maintenanceIdentity($provider, $failed)];
                    }
                    $state['pending_next'] = $page['cursor'];
                    $state['pending_complete'] = $page['complete'];
                    $this->save($store, $state);
                }
                while ($state['pending']) {
                    $this->save($store, $state);
                    $item = $state['pending'][0];
                    try {
                        $failed = FailedPayloads::findSnapshot($provider, $item);
                        $this->save($store, $state);
                        $result = $failed ? $maintenance->forgetFailed($failed) : ['payloads' => 1, 'histories' => $item['identity'] ? $maintenance->retireFailedIdentity($item['identity']) : 0, 'untracked' => $item['identity'] ? 0 : 1];
                        foreach (['histories', 'payloads', 'untracked'] as $key) {
                            $state[$key] += $result[$key];
                        }
                    } catch (\Throwable) {
                        $this->blocked($state, $item['id']);
                    }
                    array_shift($state['pending']);
                    $this->save($store, $state);
                }
                $state['failed_cursor'] = $state['pending_next'];
                if ($state['pending_complete']) {
                    $state['phase'] = 'done';
                }
                unset($state['pending'],$state['pending_next'],$state['pending_complete']);
            } else {
                $state['complete'] = true;
            }
            if ($state['phase'] === 'done') {
                $state['complete'] = true;
            }
            $this->save($store, $state);
            $chunks++;
        }

        return $state;
    }

    private function forgetNonAtomicCaptured(Store $store, Maintenance $maintenance, array &$state, object $job): void
    {
        try {
            $provider = app('queue.failer');
            $failed = FailedPayloads::findRetained($provider, $job->external_id, $job->connection, $job->dispatch_id ?? null);
            $this->save($store, $state);
            if ($failed === null) {
                $maintenance->forget($job->id, true);
                $state['histories']++;
            } else {
                $identity = FailedPayloads::maintenanceIdentity($provider, $failed);
                if (! $identity || $identity['uuid'] !== $job->external_id || $identity['connection'] !== $job->connection || $identity['queue'] !== $job->queue || $identity['dispatch'] !== ($job->dispatch_id ?? null)) {
                    throw new MaintenanceException('Retained custom failure no longer matches captured history.');
                }
                $state['history_pending'] = ['capture_id' => $job->id, 'id' => (string) ($failed->id ?? $job->external_id), 'fingerprint' => FailedPayloads::fingerprint($failed), 'identity' => $identity];
                $this->save($store, $state);
                $maintenance->forget($job->id, false, true, $state['history_pending']['fingerprint']);
                $state['histories']++;
                $state['payloads']++;
            }
        } catch (\Throwable) {
            $this->blocked($state, $job->id);
        }
        $state['history_cursor'] = $job->id;
        unset($state['history_pending']);
        $this->save($store, $state);
    }

    private function reconcileNonAtomic(Store $store, Maintenance $maintenance, array &$state): void
    {
        $pending = $state['history_pending'];
        $this->save($store, $state);
        try {
            $failed = FailedPayloads::findSnapshot(app('queue.failer'), $pending);
            $this->save($store, $state);
            if ($failed !== null) {
                throw new MaintenanceException('Previous non-atomic outcome is unknown; the delete request will not be automatically resent.');
            }
            $maintenance->retireFailedIdentity($pending['identity']);
            $state['histories']++;
            $state['payloads']++;
        } catch (\Throwable) {
            $this->blocked($state, $pending['capture_id']);
        }
        $state['history_cursor'] = $pending['capture_id'];
        unset($state['history_pending']);
        $this->save($store, $state);
    }

    private function blocked(array &$state, string $id): void
    {
        $state['blocked']++;
        if (count($state['blocked_examples']) < 20) {
            $state['blocked_examples'][] = mb_substr($id, 0, 100);
        }
    }

    private function encode(array $state): string
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        if (strlen($json) > 65536) {
            throw new MaintenanceException('The failed provider returned excessive operation metadata.');
        }

        return $json;
    }

    private function save(Store $store, array $state): void
    {
        $changed = $store->table('maintenance_operations')->where('id', $state['operation'])->where('owner_token', $this->owner)->where('revision', $this->revision)->where('expires_at', '>', microtime(true))
            ->update(['value' => $this->encode($state), 'lease_until' => microtime(true) + 30, 'revision' => $this->revision + 1]);
        if ($changed !== 1) {
            throw new MaintenanceException('Maintenance ownership changed; last action checkpoint is unconfirmed. Do not automatically resend.');
        }
        $this->revision++;
    }
}
