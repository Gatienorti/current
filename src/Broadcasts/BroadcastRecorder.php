<?php

namespace Current\Broadcasts;

use Current\Recorder;
use Current\Store;
use Current\Support\Sanitizer;
use Illuminate\Support\Str;
use Throwable;

final class BroadcastRecorder
{
    public function __construct(private Store $store) {}

    public function start(string $connection, array $channels, mixed $event, array $payload = []): ?array
    {
        if (! config('current.enabled', true) || ! config('current.broadcasts.enabled', false) || ! is_string($event)) {
            return null;
        }
        $events = (array) config('current.broadcasts.events', []);
        if (! in_array($event, $events, true) || strlen($event) > 160) {
            return null;
        }
        $patterns = [];
        $approved = [];
        $channelTypes = [];
        $channelsLimited = false;
        foreach (array_slice((array) config('current.broadcasts.channels', []), 0, 50) as $pattern) {
            if (is_string($pattern) && strlen($pattern) <= 160) {
                foreach ($channels as $channel) {
                    if (Str::is($pattern, (string) $channel)) {
                        $patterns[$pattern] = $pattern;
                        $name = (string) $channel;
                        $channelTypes[str_starts_with($name, 'private-') ? 'Private' : (str_starts_with($name, 'presence-') ? 'Presence' : 'Public')] = true;
                        if (count($approved) < 100) {
                            $approved[$name] = $name;
                        } elseif (! isset($approved[$name])) {
                            $channelsLimited = true;
                        }
                    }
                }
            }
        }
        if (! $patterns) {
            return null;
        }
        $now = microtime(true);
        $id = (string) Str::ulid();
        $job = app(Recorder::class)->currentExecution();
        $days = max(1, min(30, (int) config('current.retention_days', 7), (int) config('current.max_retention_days', 30)));
        $snapshot = BroadcastContext::capture($event, array_values($approved), $payload);
        if ($channelsLimited && $snapshot['exact_channels_state'] === 'captured') {
            $snapshot['exact_channels_state'] = 'limited';
        }
        $this->store->table('broadcasts')->insert([
            'id' => $id, 'connection' => mb_substr($connection, 0, 80), 'event' => $event,
            'channels_json' => json_encode(array_values(array_unique($patterns)), JSON_THROW_ON_ERROR),
            'channel_types_json' => json_encode(array_keys($channelTypes), JSON_THROW_ON_ERROR),
            ...$snapshot,
            'started_at' => $now, 'expires_at' => $now + $days * 86400,
            'job_id' => $job['job_id'] ?? null, 'attempt_id' => $job['attempt_id'] ?? null,
        ]);

        return ['id' => $id, 'clock' => hrtime(true)];
    }

    public static function finish(?array $context, ?Throwable $error = null): void
    {
        if (! $context) {
            return;
        }
        $details = [];
        if ($error) {
            try {
                $details = app(Sanitizer::class)->exception($error, config('current.broadcasts.message_sanitizer'), false);
                $details['error_state'] = 'captured';
            } catch (Throwable) {
                $details = ['error_state' => 'failed'];
            }
        }
        try {
            app(Store::class)->table('broadcasts')->where('id', $context['id'])->update([
                ...$details, 'finished_at' => microtime(true), 'duration_ms' => (hrtime(true) - $context['clock']) / 1e6,
                'outcome' => $error ? 'failed' : 'accepted', 'exception_class' => $error ? mb_substr($error::class, 0, 240) : null,
            ]);
        } catch (Throwable) {
        }
    }

    public function prune(): int
    {
        $ids = $this->store->table('broadcasts')->where('expires_at', '<=', microtime(true))->limit(500)->pluck('id');

        return $this->store->table('broadcasts')->whereIn('id', $ids)->delete();
    }
}
