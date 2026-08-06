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
        $middleware->alias([
            // Order matters where these are applied (routes/api.php):
            // auth -> tenant -> subscription. See the note there.
            'tenant'       => App\Http\Middleware\SetTenantContext::class,
            'subscription' => App\Http\Middleware\EnforceSubscriptionState::class,
        ]);

        /*
         * Never redirect an API caller to a login PAGE.
         *
         * Laravel's default is to send unauthenticated users to route('login'),
         * which for /api/* produces either a 302 to HTML or — when no such route
         * exists — a 500 that hides the real problem. An API client cannot use a
         * login form; it needs 401 so it knows to re-authenticate.
         *
         * Returning null here makes the framework throw AuthenticationException
         * instead, which the JSON renderer below turns into a proper 401.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Anything under /api/* answers in JSON, regardless of the Accept header.
        // Relying on the client to ask for JSON means a caller that forgets gets
        // an HTML error page it cannot parse.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
