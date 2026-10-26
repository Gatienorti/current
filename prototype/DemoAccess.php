<?php

namespace Current\Prototype;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;

// ONLY registered by the isolated lab. Never included in the package provider.
final class DemoAccess
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment('local', 'testing')) {
            abort(403);
        }
        $request->setUserResolver(fn () => new GenericUser(['id' => 1, 'name' => 'Local operator', 'is_admin' => true]));

        return $next($request);
    }
}
