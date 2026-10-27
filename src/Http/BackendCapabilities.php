<?php

namespace Current\Http;

use Closure;
use Current\ExecutionBackend;
use Illuminate\Http\Request;

final class BackendCapabilities
{
    public function handle(Request $request, Closure $next)
    {
        $name = str_replace('current.', '', $request->route()->getName() ?? '');
        $capability = $name === 'retry' ? 'retry' : explode('.', $name)[0];
        if (! ExecutionBackend::supports($capability)) {
            return response()->view('current::backend-unavailable', [], 503)
                ->header('Cache-Control', 'private, no-store');
        }

        return $next($request);
    }
}
