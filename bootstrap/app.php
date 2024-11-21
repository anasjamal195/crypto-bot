<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'trigger-buy-event', // <-- exclude this route
            'update-order', // <-- exclude this route
            'buy-market',
            'sell-market',
            'check-open-order'
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
