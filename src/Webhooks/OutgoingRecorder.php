<?php

namespace HorizonLens\Webhooks;

use GuzzleHttp\Promise\Create;
use HorizonLens\Recorder;
use HorizonLens\Store;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Throwable;

final class OutgoingRecorder
{
    // Context lives in each promise closure, never in shared request state.
    public static function middleware(): \Closure
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler) {
            $context = null;
            try {
                $context = app(self::class)->start($request, $options);
            } catch (Throwable) {
            }
            try {
                $promise = $handler($request, $options);
            } catch (Throwable $error) {
                self::finish($context, null, $error);
                throw $error;
            }

            if (! $context) {
                return $promise;
            }

            return $promise->then(
                function ($response) use ($context) {
                    self::finish($context, $response->getStatusCode(), null, $response);

                    return $response;
                },
                function ($reason) use ($context) {
                    self::finish($context, null, $reason);

                    return Create::rejectionFor($reason);
                }
            );
        };
    }

    public function __construct(private Store $store) {}

    private function endpoint(string $url): ?string
    {
        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return $scheme.'://'.strtolower($parts['host']).':'.$port.($parts['path'] ?? '/');
    }

    public function start(RequestInterface $request, array $options = []): ?array
    {
        if (! config('horizon-lens.enabled', true) || ! config('horizon-lens.webhooks.outgoing.enabled', false)) {
            return null;
        }
        $endpoint = $this->endpoint((string) $request->getUri());
        foreach ((array) config('horizon-lens.webhooks.outgoing.destinations', []) as $label => $url) {
            if (! is_string($url) || ! is_string($label) || ! preg_match('/^[a-zA-Z0-9_-]{1,80}$/D', $label)
                || ! $endpoint || $endpoint !== $this->endpoint($url)) {
                continue;
            }
            $now = microtime(true);
            $id = (string) Str::ulid();
            $execution = app(Recorder::class)->currentExecution();
            $days = max(1, min(30, (int) config('horizon-lens.retention_days', 7), (int) config('horizon-lens.max_retention_days', 30)));
            $this->store->table('webhook_deliveries')->insert([
                'id' => $id,
                'delivery_key' => isset($options['current_delivery_id']) && is_string($options['current_delivery_id']) && strlen($options['current_delivery_id']) > 0 && strlen($options['current_delivery_id']) <= 160
                    ? hash('sha256', json_encode([$label, $endpoint, $request->getMethod(), $options['current_delivery_id']], JSON_THROW_ON_ERROR))
                    : null,
                'destination' => $label, 'endpoint' => mb_substr($endpoint, 0, 500),
                'method' => mb_substr($request->getMethod(), 0, 12), 'started_at' => $now,
                'job_id' => $execution['job_id'] ?? null, 'attempt_id' => $execution['attempt_id'] ?? null,
                'expires_at' => $now + $days * 86400,
                ...array_diff_key(WebhookContext::capture($options['current_webhook'] ?? null), array_flip(['response_json', 'response_state'])),
            ]);

            return ['id' => $id, 'clock' => hrtime(true), 'destination' => $label];
        }

        return null;
    }

    private static function finish(?array $context, ?int $status, mixed $error, mixed $response = null): void
    {
        if (! $context) {
            return;
        }
        try {
            $snapshot = [];
            if ($response && ($resolver = config('horizon-lens.webhooks.response_context'))) {
                $snapshot = array_intersect_key(WebhookContext::capture(fn () => ['response' => $resolver($response, $context['destination'])]), array_flip(['response_json', 'response_state']));
            }
            app(Store::class)->table('webhook_deliveries')->where('id', $context['id'])->update([
                'finished_at' => microtime(true), 'duration_ms' => max(0, (hrtime(true) - $context['clock']) / 1e6),
                'http_status' => $status, ...$snapshot,
                'exception_class' => is_object($error) ? mb_substr($error::class, 0, 240) : ($error !== null ? 'Transport failure' : null),
            ]);
        } catch (Throwable) {
            // Observation must not change the response or rejection, even if storage is unavailable.
        }
    }

    public function prune(): int
    {
        $ids = $this->store->table('webhook_deliveries')->where('expires_at', '<=', microtime(true))->limit(500)->pluck('id');

        return $this->store->table('webhook_deliveries')->whereIn('id', $ids)->delete();
    }
}
