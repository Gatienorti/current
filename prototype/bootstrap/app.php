<?php

use HorizonLens\Prototype\Jobs\GatewayDeclined;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(health: '/up')
    ->withMiddleware(fn (Middleware $middleware) => null)
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport([GatewayDeclined::class]);
    })
    ->create();
