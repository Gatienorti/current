<?php

namespace HorizonLens\Broadcasts;

use HorizonLens\Store;
use HorizonLens\Support\JobContext;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Str;
use Throwable;

final class AuthorizationRecorder
{
    public function __construct(private Store $store) {}

    public function record(RequestHandled $event): void
    {
        try {
            if (! config('horizon-lens.enabled', true) || ! config('horizon-lens.broadcasts.authorization.enabled', false)) {
                return;
            }
            $route = $event->request->route();
            $name = $route?->getName();
            $path = $route?->uri();
            $approvedName = is_string($name) && in_array($name, (array) config('horizon-lens.broadcasts.authorization.routes', []), true);
            $approvedPath = is_string($path) && in_array($path, (array) config('horizon-lens.broadcasts.authorization.paths', []), true);
            $label = $approvedName ? $name : $path;
            $status = $event->response->getStatusCode();
            if ((! $approvedName && ! $approvedPath) || ! is_string($label) || strlen($label) > 160 || $status < 400) {
                return;
            }
            // Read only channel_name; never retain socket IDs, credentials, identities or response bodies.
            $channel = $event->request->input('channel_name');
            $patterns = [];
            if (is_string($channel) && strlen($channel) <= 240) {
                foreach (array_slice((array) config('horizon-lens.broadcasts.channels', []), 0, 50) as $pattern) {
                    if (is_string($pattern) && strlen($pattern) <= 160 && Str::is($pattern, $channel)) {
                        $patterns[] = $pattern;
                    }
                }
            }
            $json = null;
            $state = 'not_captured';
            if ($patterns && config('horizon-lens.broadcasts.capture_channels', false)) {
                [$json, $state] = (new JobContext)->encode([$channel]);
            }
            $now = microtime(true);
            $days = max(1, min(30, (int) config('horizon-lens.retention_days', 7), (int) config('horizon-lens.max_retention_days', 30)));
            $this->store->table('broadcast_auth_failures')->insert([
                'id' => (string) Str::ulid(), 'route_name' => $label, 'method' => mb_substr($event->request->method(), 0, 12),
                'http_status' => $status, 'channels_json' => json_encode(array_values(array_unique($patterns)), JSON_THROW_ON_ERROR),
                'channel_type' => ! $patterns ? 'Unknown' : (str_starts_with($channel, 'private-') ? 'Private' : (str_starts_with($channel, 'presence-') ? 'Presence' : 'Public')),
                'exact_channels_json' => $json, 'exact_channels_state' => $state,
                'observed_at' => $now, 'expires_at' => $now + $days * 86400,
            ]);
        } catch (Throwable) {
            // Observation must never change the authorization response.
        }
    }

    public function prune(): int
    {
        $ids = $this->store->table('broadcast_auth_failures')->where('expires_at', '<=', microtime(true))->limit(500)->pluck('id');

        return $this->store->table('broadcast_auth_failures')->whereIn('id', $ids)->delete();
    }
}
