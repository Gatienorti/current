<?php

namespace Current\Native;

use Current\Store;
use Current\Support\DispatchIdentity;
use Current\Support\PayloadData;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Support\Carbon;

/** Laravel retains the executable failed payload and owns retry preparation. */
final class Retry
{
    public ?string $dispatchId = null;

    public function available(object $job): bool
    {
        if (config('queue.connections.'.$job->connection.'.driver') !== 'current-redis') {
            return false;
        }
        $failed = FailedPayloads::findRetained(app('queue.failer'), $job->external_id, $job->connection, $job->dispatch_id ?? null);

        return $this->matches($job, $failed);
    }

    private function matches(object $job, mixed $failed): bool
    {
        if (! is_object($failed) || ($failed->connection ?? null) !== $job->connection || ($failed->queue ?? null) !== $job->queue
            || ! is_string($failed->payload ?? null)) {
            return false;
        }
        $payload = json_decode($failed->payload, true, 512, JSON_THROW_ON_ERROR);

        return ($payload['uuid'] ?? null) === $job->external_id
            && DispatchIdentity::fromPayload($payload) === ($job->dispatch_id ?? null)
            && (! isset($job->failed_payload_hash) || hash_equals($job->failed_payload_hash, hash('sha256', $failed->payload)));
    }

    /** Read executable evidence only from Laravel, never from display snapshots. */
    public function batchJob(string $batchId, string $uuid, string $before): object
    {
        $batch = app(BatchRepository::class)->find($batchId);
        if (! $batch || ! in_array($uuid, $batch->failedJobIds, true)) {
            throw new \RuntimeException('Batch membership no longer available.');
        }
        $failed = FailedPayloads::findRetained(app('queue.failer'), $uuid);
        if (! $failed || ! is_string($failed->connection) || ! is_string($failed->queue)
            || $failed->queue === '' || strlen($failed->queue) > 100
            || ! is_string($failed->payload ?? null) || strlen($failed->payload) > 1048576
            || strlen($failed->connection) > 100 || config('queue.connections.'.$failed->connection.'.driver') !== 'current-redis') {
            throw new \RuntimeException('Native failed payload unavailable.');
        }
        $payload = json_decode($failed->payload, true, 512, JSON_THROW_ON_ERROR);
        $dispatch = DispatchIdentity::fromPayload($payload);
        // A pass never includes retries created by that pass, including on POST replay.
        $legacyBefore = 0;
        foreach (str_split($before) as $char) {
            $legacyBefore = $legacyBefore * 32 + strpos('0123456789ABCDEFGHJKMNPQRSTVWXYZ', $char);
        }
        $failedAt = isset($failed->failed_at_timestamp) ? (int) $failed->failed_at_timestamp : (isset($failed->failed_at) ? Carbon::parse($failed->failed_at, config('app.timezone', 'UTC'))->getTimestamp() : null);
        if (($payload['uuid'] ?? null) !== $uuid || ($dispatch ? strcmp(substr($dispatch, 0, 10), $before) >= 0 : ($failedAt === null || $failedAt >= floor($legacyBefore / 1000)))
            || (data_get($payload, 'data.batchId') !== null && data_get($payload, 'data.batchId') !== $batchId)) {
            throw new \RuntimeException('Failed payload identity or batch membership is unverified.');
        }
        // Inspect authenticated plaintext only for this explicit retry action.
        // No command objects are instantiated by membership verification.
        $command = $this->batchCommand(data_get($payload, 'data.command'));
        if (! is_string($command) || ! str_starts_with($command, 'O:')
            || ((new PayloadData($command))->read()['batchId'] ?? null) !== $batchId) {
            throw new \RuntimeException('Executable batch membership is unverified.');
        }
        $job = app(Store::class)->jobsWithObservationState()->where('external_id', $uuid)->where('connection', $failed->connection)
            ->when($dispatch === null, fn ($query) => $query->whereNull('dispatch_id'), fn ($query) => $query->where('dispatch_id', $dispatch))->where('expires_at', '>', microtime(true))->first();
        if ($job && (($job->batch_id !== null && $job->batch_id !== $batchId) || $job->queue !== $failed->queue || $job->display_status === 'completed')) {
            throw new \RuntimeException('Captured failed dispatch does not match retained batch evidence.');
        }
        $job ??= (object) ['id' => null, 'external_id' => $uuid, 'connection' => $failed->connection,
            'queue' => $failed->queue, 'dispatch_id' => $dispatch, 'batch_id' => $batchId];
        $job->failed_payload_hash = hash('sha256', $failed->payload);
        $job->retry_batch_id = $batchId;

        return $job;
    }

    private function batchCommand(mixed $command): string
    {
        if (! is_string($command)) {
            throw new \RuntimeException('Executable batch membership is unverified.');
        }
        if (str_starts_with($command, 'O:')) {
            return $command;
        }
        if (! app()->bound(Encrypter::class)) {
            throw new \RuntimeException('Encrypted batch membership is unavailable.');
        }
        // Laravel encrypts serialize(job) with encrypt's default serialization,
        // so authenticated plaintext is itself a serialized string. Decode that
        // bounded string envelope as data, avoiding Encrypter's PHP unserialize.
        $plaintext = app(Encrypter::class)->decrypt($command, false);
        if (! is_string($plaintext) || strlen($plaintext) > 262144) {
            throw new \RuntimeException('Encrypted batch command exceeds inspection limits.');
        }
        $decoded = (new PayloadData($plaintext))->read();
        if (! is_string($decoded) || ! str_starts_with($decoded, 'O:')) {
            throw new \RuntimeException('Encrypted batch membership is unverified.');
        }

        return $decoded;
    }

    public function retry(string $id, ?object $expected = null): string
    {
        $failed = FailedPayloads::findRetained(app('queue.failer'), $id, $expected->connection ?? null, $expected->dispatch_id ?? null);
        if (! $expected || ! $this->matches($expected, $failed)) {
            throw new \RuntimeException('Failed dispatch changed before retry.');
        }
        $snapshot = clone $expected;
        $snapshot->failed_payload_hash = hash('sha256', $failed->payload);
        $queue = app('queue')->connection($failed->connection);
        if (! $queue instanceof RedisQueue) {
            throw new \RuntimeException('Native retry connection unavailable.');
        }
        $queue->lastEnqueued = null;
        app('events')->dispatch(new JobRetryRequested(clone $failed));
        if (! $this->available($snapshot)) {
            throw new \RuntimeException('Failed payload changed before enqueue.');
        }
        // Use Laravel's installed retry preparation on this exact retained object;
        // queue:retry would re-find the UUID and could execute a replacement payload.
        $command = new RetainedRetryCommand;
        $command->setLaravel(app());
        $execution = clone $failed;
        if (isset($expected->retry_batch_id)) {
            $payload = json_decode($execution->payload, true, 512, JSON_THROW_ON_ERROR);
            // Membership was read from the actual retained serialized command.
            // Preserve that verified observation on a legacy raw retry too.
            $payload['data']['batchId'] = $expected->retry_batch_id;
            $execution->payload = json_encode($payload, JSON_THROW_ON_ERROR);
        }
        $command->enqueue($execution);
        $enqueue = $queue->lastEnqueued;
        if (($enqueue['uuid'] ?? null) !== $id || ! is_string($enqueue['dispatch_id'] ?? null)) {
            throw new \RuntimeException('Retry enqueue unconfirmed.');
        }
        $this->dispatchId = $enqueue['dispatch_id'];
        // Failed storage can change after enqueue. Compare all original bytes
        // atomically so cleanup cannot remove a replacement failure.
        if (! FailedPayloads::forgetExact(app('queue.failer'), $id, $failed)) {
            throw new \RuntimeException('Retry submitted; failed-payload cleanup unconfirmed.');
        }

        return $id;
    }
}
