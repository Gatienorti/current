<?php

namespace HorizonLens\Webhooks;

use Closure;
use Illuminate\Http\Request;
use Throwable;

/** Explicit route opt-in; never validates, consumes or changes the request. */
final class ObserveWebhook
{
    public function handle(Request $request, Closure $next, string $provider)
    {
        $start = microtime(true);
        try {
            app(WebhookRecorder::class)->start($request, $provider, $start);
        } catch (Throwable) {
            // Observation must not prevent the existing handler from running.
        }
        try {
            return $next($request);
        } catch (Throwable $error) {
            if ($request->attributes->has('current.webhook.id')) {
                $request->attributes->set('current.webhook.exception', $error::class);
            }
            throw $error;
        } finally {
            $request->attributes->remove('current.webhook.active');
        }
    }
}
