<?php

namespace Current\Http;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class RefreshResponse
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        if ($request->isMethod('GET') && ($response->original ?? null) instanceof View) {
            // Full and partial HTML share a URL; neither belongs in a shared cache.
            $response->setVary('X-Current-Refresh', false);
            $response->headers->set('Cache-Control', 'private, no-store');
            if ($request->header('X-Current-Refresh') === '1' && $response->isSuccessful()) {
                $response->headers->set('X-Current-Refresh', '1');
            }
        }

        return $response;
    }
}
