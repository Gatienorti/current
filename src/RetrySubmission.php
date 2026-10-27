<?php

namespace Current;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Shared durable claim for individual and batch retry requests. */
final class RetrySubmission
{
    public function submit(object $job): array
    {
        $store = app(Store::class); $native = ExecutionBackend::native();
        $backend = app($native ? Native\Retry::class : HorizonRetry::class);
        $key = 'retry:'.$job->id;
        $claimed = false;
        $claim = function () use ($store, $job, $native, $backend, $key, &$claimed) {
            if ($native && ! $store->table('jobs')->where('id', $job->id)->exists()) { throw new ConflictHttpException('This history has been removed.'); }
            $state = $store->table('meta')->where('key', $key)->value('value');
            if ($state) { return $state; }
            if (! $backend->available($job)) { throw new ConflictHttpException('The execution backend no longer retains this failed dispatch.'); }
            $claimed = (bool) $store->table('meta')->insertOrIgnore(['key' => $key, 'value' => '{"state":"requested"}']);
            return $store->table('meta')->where('key', $key)->value('value');
        };
        // Serialize the claim with native history removal, then release SQL before
        // enqueue callbacks. Nested history transactions must not break observation.
        $state = $native ? $store->cycleTransaction($job->external_id, $job->connection, $claim) : $claim();
        if ($claimed) {
            $uuid = $backend->retry($job->external_id);
            $state = json_encode(['state' => 'submitted', 'uuid' => $uuid, 'dispatch_id' => $native ? $backend->dispatchId : null], JSON_THROW_ON_ERROR);
            $store->table('meta')->where('key', $key)->update(['value' => $state]);
        }
        $record = json_decode($state ?? '{}', true);
        return is_array($record) ? $record : ['state' => 'unknown'];
    }
}
