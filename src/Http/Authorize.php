<?php

namespace Current\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;

final class Authorize
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(in_array(config('current.execution_backend', 'horizon'), ['horizon', 'native'], true), 403);
        if (\Current\ExecutionBackend::native()) {
            abort_unless($request->user() && Gate::has('viewCurrent') && Gate::allows('viewCurrent'), 403);
        } else {
            abort_unless(class_exists(Horizon::class) && $request->user() && Gate::allows('viewHorizon') && Horizon::check($request), 403);
        }

        return $next($request);
    }
}
