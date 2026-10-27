<?php

namespace Current\Native;

use Current\Recorder;
use Illuminate\Queue\RedisQueue as LaravelRedisQueue;
use Throwable;
use Illuminate\Support\Str;

/** Opt-in adapter: Laravel retains all Redis queue/reservation semantics. */
final class RedisQueue extends LaravelRedisQueue implements EnqueueObservations
{
    public ?array $lastEnqueued = null;
    private int $enqueueDepth = 0;
    private ?array $observations = null;
    private int $observationBytes = 0;
    private bool $overflow = false;
    private int $bulkCommands = 0;

    public function observeEnqueue(callable $observation, int $bytes): void
    {
        if ($this->observations !== null) {
            // Drop the whole observation set on overflow; never constrain application dispatch.
            if ($this->overflow || count($this->observations) >= 2048 || $this->observationBytes + $bytes > 4194304) {
                $this->overflow = true;
                $this->observations = [];
                return;
            }
            $this->observationBytes += $bytes;
            $this->observations[] = $observation;
            return;
        }
        // Arbitrary caller-owned pipelines have no observable commit boundary here.
        $client = $this->getConnection()->client();
        if ($client instanceof \Redis && $client->getMode() === \Redis::ATOMIC && $client->getLastError() === null) {
            try { $observation(); } catch (Throwable) {}
        }
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        // Bulk command construction follows Illuminate\Queue\RedisQueue (MIT); see ../../LICENSE-LARAVEL.md.
        // Preserve Laravel's transaction arrangement.
        // Only standalone phpredis confirmation is supported by this experiment.
        if ($this->observations !== null) { return parent::bulk($jobs, $data, $queue); }
        $connection = $this->getConnection();
        $client = $connection->client();
        $this->observations = [];
        $this->observationBytes = 0;
        $this->overflow = false;
        $this->bulkCommands = 0;
        $pending = [];
        try {
            if (! $client instanceof \Redis || $client->getMode() !== \Redis::ATOMIC) {
                parent::bulk($jobs, $data, $queue);
                return;
            }
            $client->clearLastError();
            $results = $connection->pipeline(fn () => $connection->transaction(function () use ($jobs, $data, $queue) {
                foreach ((array) $jobs as $job) {
                    if (isset($job->delay)) { $this->later($job->delay, $job, $data, $queue); }
                    else { $this->push($job, $data, $queue); }
                }
            }));
            // Lua pushes return nil (false in phpredis); command errors also can return
            // false, so require both the complete EXEC reply and no Redis error.
            if (! $this->overflow && $client->getLastError() === null && is_array($results)
                && count($results) === 1 && is_array($results[0]) && count($results[0]) === $this->bulkCommands) {
                $pending = $this->observations;
            }
        } finally {
            $this->observations = null;
            $this->observationBytes = 0;
            $this->overflow = false;
        }
        foreach ($pending as $observation) {
            try { $observation(); } catch (Throwable) {}
        }
    }

    protected function createObjectPayload($job, $queue)
    {
        $payload = parent::createObjectPayload($job, $queue);
        try {
            // Older supported Laravel versions may omit batchId from display payload data.
            $batchId = $job->batchId ?? null;
            if (is_string($batchId) && strlen($batchId) <= 100) { $payload['data']['batchId'] = $batchId; }
        } catch (Throwable) {}
        try {
            $tags = app(Tags::class)->for($job);
            $payload['tags'] = app(\Current\Support\Sanitizer::class)->tags($tags);
            $payload['silenced'] = in_array(get_class($job), (array) config('current.native.silenced', []), true)
                || count(array_intersect($payload['tags'], (array) config('current.native.silenced_tags', []))) > 0;
        } catch (Throwable) {
            // Classification stays unknown when an application's tag method fails.
        }
        return $payload;
    }

    protected function enqueueUsing($job, $payload, $queue, $delay, $callback)
    {
        // This payload also reaches JobQueued. The wrapper runs at commit time,
        // so after-commit dispatch cannot accidentally receive two identities.
        return parent::enqueueUsing($job, $this->identify($payload), $queue, $delay, function (...$args) use ($callback) {
            $this->enqueueDepth++;
            try {
                return $callback(...$args);
            } finally {
                $this->enqueueDepth--;
            }
        });
    }

    private function identify(string $payload): string
    {
        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data) || ! is_string($data['uuid'] ?? null) || $data['uuid'] === '') {
                return $payload;
            }
            $data['_current_dispatch_id'] = (string) Str::ulid();

            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (Throwable) {
            // Preserve the native queue's behavior if observation preparation fails.
            return $payload;
        }
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if ($this->enqueueDepth === 0) {
            $payload = $this->identify($payload);
        }
        $client = $this->getConnection()->client();
        if ($client instanceof \Redis && $client->getMode() === \Redis::ATOMIC) { $client->clearLastError(); }
        $result = parent::pushRaw($payload, $queue, $options);
        if ($this->observations !== null) { $this->bulkCommands++; }
        $this->observe($payload, $queue);

        return $result;
    }

    protected function laterRaw($delay, $payload, $queue = null)
    {
        $client = $this->getConnection()->client();
        if ($client instanceof \Redis && $client->getMode() === \Redis::ATOMIC) { $client->clearLastError(); }
        $result = parent::laterRaw($delay, $payload, $queue);
        if ($this->observations !== null) { $this->bulkCommands++; }
        $this->observe($payload, $queue);

        return $result;
    }

    private function observe(string $payload, ?string $queue): void
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $this->observeEnqueue(function () use ($decoded, $queue) {
                $this->lastEnqueued = ['uuid' => $decoded['uuid'] ?? null, 'dispatch_id' => \Current\Support\DispatchIdentity::fromPayload($decoded)];
                app(Recorder::class)->enqueued($decoded, $this->getConnectionName(), $queue ?? $this->default);
            }, strlen($payload));
        } catch (Throwable) {
            // Collection must never turn a successful Redis push into a failed dispatch.
        }
    }
}
