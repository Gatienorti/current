<?php

namespace HorizonLens\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

final class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user() && Gate::allows('viewHorizon') && Horizon::check($request), 403);

        return $next($request);
    }
}
