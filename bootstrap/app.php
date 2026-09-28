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
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirect to login when unauthenticated
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Railway (and most PaaS hosts) terminate TLS at a reverse proxy and
        // forward plain HTTP internally — without trusting that proxy's
        // X-Forwarded-* headers, Laravel thinks every request is HTTP, which
        // breaks secure cookies and generates http:// URLs behind an https:// site.
        $middleware->trustProxies(at: '*');

        // PayMongo can't send a CSRF token; the webhook verifies its signature instead.
        $middleware->validateCsrfTokens(except: ['webhooks/paymongo']);

        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->append(\App\Http\Middleware\TrackLastSeen::class);

        $middleware->alias([
            'role'      => \App\Http\Middleware\EnsureUserHasRole::class,
            'guest.only' => \App\Http\Middleware\RedirectIfAuthenticated::class,
            'no.cache'  => \App\Http\Middleware\PreventBackHistory::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();