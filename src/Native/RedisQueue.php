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
    private array $bulkReplyKinds = [];
    private bool $predisAcknowledged = false;

    private function standalonePredis(mixed $client): bool
    {
        return $client instanceof \Predis\Client && $client->getConnection() instanceof \Predis\Connection\NodeConnectionInterface;
    }

    private function trackBulkCommand(string $kind): void
    {
        if ($this->observations === null) { return; }
        $this->bulkCommands++;
        if (! $this->overflow && count($this->bulkReplyKinds) < 2048) { $this->bulkReplyKinds[] = $kind; }
        else { $this->overflow = true; $this->bulkReplyKinds = []; $this->observations = []; }
    }

    private function confirmedPredisBulk(mixed $results): bool
    {
        if ($this->overflow || ! is_array($results) || count($results) !== $this->bulkCommands || count($results) !== count($this->bulkReplyKinds)) { return false; }
        foreach ($results as $index => $reply) {
            if ($this->bulkReplyKinds[$index] === 'nil' ? $reply !== null : (! is_int($reply) || $reply < 0 || $reply > 1)) { return false; }
        }
        return true;
    }

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
        if (($client instanceof \Redis && $client->getMode() === \Redis::ATOMIC && $client->getLastError() === null)
            || ($this->standalonePredis($client) && $this->predisAcknowledged)) {
            try { $observation(); } catch (Throwable) {}
        }
    }

    public function bulk($jobs, $data = '', $queue = null)
    {
        // Bulk command construction follows Illuminate\Queue\RedisQueue (MIT); see ../../LICENSE-LARAVEL.md.
        // Preserve Laravel's transaction arrangement.
        if ($this->observations !== null) { return parent::bulk($jobs, $data, $queue); }
        $connection = $this->getConnection();
        $client = $connection->client();
        $this->observations = [];
        $this->observationBytes = 0;
        $this->overflow = false;
        $this->bulkCommands = 0;
        $this->bulkReplyKinds = [];
        $pending = [];
        try {
            if ($this->standalonePredis($client)) {
                // Claim this transaction explicitly. A caller-owned MULTI must
                // never be committed by treating its queued reply as our ACK.
                $owned = false;
                try {
                    $reply = $client->multi();
                    if (! $reply instanceof \Predis\Response\Status || (string) $reply !== 'OK') {
                        throw new \RuntimeException('Native bulk transaction ownership is unconfirmed.');
                    }
                    $owned = true;
                    foreach ((array) $jobs as $job) {
                        if (isset($job->delay)) { $this->later($job->delay, $job, $data, $queue); }
                        else { $this->push($job, $data, $queue); }
                    }
                    $results = $client->exec();
                    $owned = false;
                    if ($this->confirmedPredisBulk($results)) { $pending = $this->observations; }
                } finally {
                    // A construction failure or lost reply leaves no confirmed
                    // outcome. Closing the owned connection aborts pending work;
                    // it never retries an EXEC or acts on a caller's transaction.
                    if ($owned) { $client->disconnect(); }
                }
            } else {
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
            }
        } finally {
            $this->predisAcknowledged = false;
            $this->observations = null;
            $this->bulkReplyKinds = [];
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
            $tags = array_values(array_unique(array_filter(array_slice(array_merge(
                is_array($payload['tags'] ?? null) ? $payload['tags'] : [], app(Tags::class)->for($job)
            ), 0, 100), 'is_string')));
            $payload['tags'] = app(\Current\Support\Sanitizer::class)->tags($tags);
            $payload['silenced'] = app(Silencing::class)->for($job, $tags);
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
        $this->predisAcknowledged = false;
        if ($client instanceof \Redis && $client->getMode() === \Redis::ATOMIC) { $client->clearLastError(); }
        if ($this->standalonePredis($client)) {
            $reply = $this->getConnection()->eval(\Illuminate\Queue\LuaScripts::push(), 2,
                $this->getQueue($queue), $this->getQueue($queue).':notify', $payload);
            $this->predisAcknowledged = $reply === null;
            $result = json_decode($payload, true)['id'] ?? null;
        } else { $result = parent::pushRaw($payload, $queue, $options); }
        $this->trackBulkCommand('nil');
        $this->observe($payload, $queue);

        return $result;
    }

    protected function laterRaw($delay, $payload, $queue = null)
    {
        $client = $this->getConnection()->client();
        $this->predisAcknowledged = false;
        // Laravel 11.44 uses ZADD directly; newer versions add an enqueue Lua
        // script. Keep that version's behavior and its distinct ACK shape.
        $usesLua = method_exists(\Illuminate\Queue\LuaScripts::class, 'later');
        if ($client instanceof \Redis && $client->getMode() === \Redis::ATOMIC) { $client->clearLastError(); }
        if ($this->standalonePredis($client)) {
            $reply = $usesLua
                ? $this->getConnection()->eval(\Illuminate\Queue\LuaScripts::later(), 1, $this->getQueue($queue).':delayed', $this->availableAt($delay), $payload)
                : $this->getConnection()->zadd($this->getQueue($queue).':delayed', $this->availableAt($delay), $payload);
            $this->predisAcknowledged = $usesLua ? $reply === null : (is_int($reply) && $reply >= 0 && $reply <= 1);
            $result = json_decode($payload, true)['id'] ?? null;
        } else { $result = parent::laterRaw($delay, $payload, $queue); }
        $this->trackBulkCommand($usesLua ? 'nil' : 'zadd');
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
