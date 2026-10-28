<?php

namespace Current;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared durable claim for individual and batch retry requests. */
final class RetrySubmission
{
    public function submit(object $job): array
    {
        if (ExecutionBackend::native()) { return $this->submitNative($job); }
        $store = app(Store::class); $backend = app(HorizonRetry::class);
        $key = 'retry:'.$job->id;
        $claimed = false;
        $claim = function () use ($store, $job, $backend, $key, &$claimed) {
            $state = $store->table('meta')->where('key', $key)->value('value');
            if ($state) { return $state; }
            if (! $backend->available($job)) { throw new ConflictHttpException('The execution backend no longer retains this failed dispatch.'); }
            $claimed = (bool) $store->table('meta')->insertOrIgnore(['key' => $key, 'value' => '{"state":"requested"}']);
            return $store->table('meta')->where('key', $key)->value('value');
        };
        $state = $claim();
        if ($claimed) {
            $uuid = $backend->retry($job->external_id);
            $state = json_encode(['state' => 'submitted', 'uuid' => $uuid, 'dispatch_id' => null], JSON_THROW_ON_ERROR);
            $store->table('meta')->where('key', $key)->update(['value' => $state]);
        }
        $record = json_decode($state ?? '{}', true);
        return is_array($record) ? $record : ['state' => 'unknown'];
    }

    private function submitNative(object $job): array
    {
        $store = app(Store::class); $backend = app(Native\Retry::class); $claimed = false;
        $identity = Support\DispatchIdentity::fingerprint($job->external_id, $job->connection, $job->dispatch_id ?? '');
        $key = $job->id ? 'retry:'.$job->id : null;
        $state = $store->cycleTransaction($job->external_id, $job->connection, function () use ($store, $backend, $job, $identity, $key, &$claimed) {
            // Same lock order as history removal and the async projector.
            if ($store->jobAction($job->external_id, $job->connection, $job->dispatch_id)?->kind === 'forgotten') {
                throw new ConflictHttpException('This failed dispatch has been forgotten.');
            }
            if ($job->id && ! $store->table('jobs')->where('id', $job->id)->exists()) { throw new ConflictHttpException('This history has been removed.'); }
            $old = $store->table('native_retry_claims')->where('identity_hash', $identity)->lockForUpdate()->first();
            if ($old && $old->expires_at > microtime(true)) { return $old->value; }
            if ($key && ($legacy = $store->table('meta')->where('key', $key)->value('value'))) {
                // Upgrade legacy claims before their observed history expires.
                $store->table('native_retry_claims')->updateOrInsert(['identity_hash' => $identity], ['value' => $legacy, 'expires_at' => app(Native\Maintenance::class)->expiry(microtime(true))]);
                return $legacy;
            }
            if (! $backend->available($job)) { throw new ConflictHttpException('Laravel no longer retains this exact failed dispatch.'); }
            if (isset($job->retry_batch_id)) {
                $batch = app(\Illuminate\Bus\BatchRepository::class)->find($job->retry_batch_id);
                if (! $batch || ! in_array($job->external_id, $batch->failedJobIds, true)) { throw new ConflictHttpException('Batch membership changed.'); }
            }
            $state = '{"state":"requested"}';
            $store->table('native_retry_claims')->updateOrInsert(['identity_hash' => $identity], ['value' => $state, 'expires_at' => app(Native\Maintenance::class)->expiry(microtime(true))]);
            if ($key) { $store->table('meta')->insertOrIgnore(['key' => $key, 'value' => $state]); }
            $claimed = true;
            return $state;
        });
        // A transport exception can follow a successful enqueue. Leave requested
        // durable in that case; no request path automatically clears the claim.
        if ($claimed) {
            $uuid = $backend->retry($job->external_id, $job);
            $state = json_encode(['state' => 'submitted', 'uuid' => $uuid, 'dispatch_id' => $backend->dispatchId], JSON_THROW_ON_ERROR);
            $store->table('native_retry_claims')->where('identity_hash', $identity)->update(['value' => $state]);
            if ($key) { $store->table('meta')->where('key', $key)->update(['value' => $state]); }
        }
        $record = json_decode($state ?? '{}', true);
        return is_array($record) ? $record : ['state' => 'unknown'];
    }
}
