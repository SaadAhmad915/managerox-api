<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum's SPA cookie auth needs the api routes to carry a session.
        // The api group is stateless by default, so requests from the hosts in
        // SANCTUM_STATEFUL_DOMAINS would otherwise have no session store.
        $middleware->statefulApi();

        /*
         * The API sits behind a proxy in production (the CRM forwards /api/*
         * to it, and the host itself terminates TLS). Without trusting the
         * X-Forwarded-* headers Laravel believes every request is plain HTTP
         * on the wrong host, so it refuses to mark the session cookie Secure
         * and generates wrong absolute URLs.
         */
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
