<?php

namespace Current\Native;

use Current\Support\DispatchIdentity;
use Illuminate\Support\Facades\Artisan;

/** Laravel retains the executable failed payload and owns retry preparation. */
final class Retry
{
    public ?string $dispatchId = null;

    public function available(object $job): bool
    {
        if (config('queue.connections.'.$job->connection.'.driver') !== 'current-redis') {
            return false;
        }
        $failed = app('queue.failer')->find($job->external_id);
        if (! $failed || $failed->connection !== $job->connection || $failed->queue !== $job->queue) {
            return false;
        }
        $payload = json_decode($failed->payload, true, 512, JSON_THROW_ON_ERROR);
        return is_string($job->dispatch_id ?? null)
            && DispatchIdentity::fromPayload($payload) === $job->dispatch_id;
    }

    public function retry(string $id): string
    {
        $failed = app('queue.failer')->find($id);
        if (! $failed) { throw new \RuntimeException('Failed job no longer available.'); }
        $queue = app('queue')->connection($failed->connection);
        if (! $queue instanceof RedisQueue) { throw new \RuntimeException('Native retry connection unavailable.'); }
        $queue->lastEnqueued = null;
        if (Artisan::call('queue:retry', ['id' => [$id]]) !== 0) {
            throw new \RuntimeException('Retry outcome unconfirmed.');
        }
        $enqueue = $queue->lastEnqueued;
        if (($enqueue['uuid'] ?? null) !== $id || ! is_string($enqueue['dispatch_id'] ?? null)) {
            throw new \RuntimeException('Retry enqueue unconfirmed.');
        }
        $this->dispatchId = $enqueue['dispatch_id'];
        return $id;
    }
}
