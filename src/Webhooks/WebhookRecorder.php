<?php

namespace Current\Webhooks;

use Current\Store;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Str;
use Throwable;

final class WebhookRecorder
{
    public function __construct(private Store $store) {}

    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (Throwable) {
            // No request content or exception messages in diagnostics.
            try {
                error_log('Current webhook observation unavailable.');
            } catch (Throwable) {
            }
        }
    }

    public function start(Request $request, string $provider, float $start): void
    {
        if (! config('current.enabled', true) || ! config('current.webhooks.enabled', false)
            || ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $provider)) {
            return;
        }
        $this->guard(function () use ($request, $provider, $start) {
            $id = (string) Str::ulid();
            $days = max(1, min(30, (int) config('current.retention_days', 7), (int) config('current.max_retention_days', 30)));
            $this->store->table('webhook_receipts')->insert([
                'id' => $id, 'provider' => $provider, 'route' => mb_substr($request->route()?->uri() ?? 'Unresolved route', 0, 240),
                'method' => mb_substr($request->method(), 0, 12), 'received_at' => $start, 'expires_at' => $start + $days * 86400,
            ]);
            $request->attributes->set('current.webhook.id', $id);
            $request->attributes->set('current.webhook.started', $start);
            $request->attributes->set('current.webhook.active', true);
        });
    }

    public function finish(RequestHandled $event): void
    {
        $id = $event->request->attributes->get('current.webhook.id');
        if (! $id) {
            return;
        }
        $this->guard(function () use ($event, $id) {
            $now = microtime(true);
            $signature = $event->request->attributes->get('current.webhook.signature', 'unknown');
            $this->store->table('webhook_receipts')->where('id', $id)->update([
                'finished_at' => $now, 'duration_ms' => max(0, ($now - $event->request->attributes->get('current.webhook.started', $now)) * 1000),
                'http_status' => $event->response->getStatusCode(),
                'receipt_key' => $this->groupKey($event->request),
                ...WebhookContext::capture($event->request->attributes->get('current.webhook.context')),
                'signature' => in_array($signature, ['valid', 'invalid'], true) ? $signature : 'unknown',
                'exception_class' => $event->request->attributes->get('current.webhook.exception')
                    ?? (($event->response->exception ?? null) instanceof Throwable ? $event->response->exception::class : null),
            ]);
        });
    }

    private function groupKey(Request $request): ?string
    {
        // Set only after the application trusts the provider's event identity.
        $identity = $request->attributes->get('current.webhook.group');
        if (! is_array($identity) || $request->attributes->get('current.webhook.signature') === 'invalid') {
            return null;
        }
        foreach (['event_id', 'scope'] as $field) {
            if (! isset($identity[$field]) || ! is_string($identity[$field]) || trim($identity[$field]) === '' || strlen($identity[$field]) > 160) {
                return null;
            }
        }
        $receipt = $this->store->table('webhook_receipts')->where('id', $request->attributes->get('current.webhook.id'))->first();
        if (! $receipt) {
            return null;
        }

        return hash('sha256', serialize([$receipt->provider, $receipt->route, $receipt->method, $identity['scope'], $identity['event_id']]));
    }

    public function queued(JobQueued $event): void
    {
        if (! app()->bound('request')) {
            return;
        }
        $request = app('request');
        if (! $request->attributes->get('current.webhook.active')) {
            return;
        }
        $this->guard(function () use ($request, $event) {
            $payload = $event->payload();
            $uuid = $payload['uuid'] ?? null;
            $dispatch = \Current\Support\DispatchIdentity::fromPayload($payload);
            $jobId = $uuid ? (config('current.telemetry.driver') === 'redis'
                ? app(\Current\Telemetry\Transport::class)->cycle($uuid, $event->connectionName, $dispatch)['id']
                : $this->store->findCycle($uuid, $event->connectionName, $dispatch)?->id) : null;
            if ($jobId && $this->store->table('webhook_jobs')->where('receipt_id', $request->attributes->get('current.webhook.id'))->count() < 100) {
                $this->store->table('webhook_jobs')->insertOrIgnore(['receipt_id' => $request->attributes->get('current.webhook.id'), 'job_id' => $jobId]);
            }
        });
    }

    public function prune(): int
    {
        return $this->store->transaction(function () {
            $ids = $this->store->table('webhook_receipts')->where('expires_at', '<=', microtime(true))->limit(500)->pluck('id');
            $this->store->table('webhook_jobs')->whereIn('receipt_id', $ids)->delete();

            return $this->store->table('webhook_receipts')->whereIn('id', $ids)->delete();
        });
    }
}
