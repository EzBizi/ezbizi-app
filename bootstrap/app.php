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

            // The two halves of §6's security policy that are enforced per
            // REQUEST rather than at sign-in. Both read a setting against the
            // caller's organization, and both do so through a helper that enters
            // that organization itself — so neither depends on where the
            // framework's priority sort happens to place it.
            'two-factor'   => App\Http\Middleware\RequireTwoFactorEnrolment::class,
            'idle'         => App\Http\Middleware\SlideIdleWindow::class,

            // The control plane (§4.2) — deliberately not 'tenant'.
            'staff'        => App\Http\Middleware\StaffOnly::class,
        ]);

        /*
         * Tenancy is established BEFORE route model binding.
         *
         * Listing middleware in a route's ->middleware([...]) does not decide the
         * order it runs in. The framework sorts the gathered list against the
         * priority map, and SubstituteBindings is in that map while our two are
         * not — so whatever the route file said, binding won and the real
         * pipeline for api/v1/customers/{customer} was:
         *
         *     auth:sanctum -> SubstituteBindings -> tenant -> subscription
         *
         * Every {model} in routes/api.php therefore resolved with no tenant in
         * context. That is only survivable while TenantScope fails OPEN, which
         * is the bug it pairs with (see TenantScope in BelongsToTenant.php): a
         * findOrFail that runs unscoped will happily bind another organization's
         * row and hand it to a controller that assumed the scope had already
         * refused it.
         *
         * Both entries below establish a tenancy MODE — SetTenantContext an
         * organization, StaffOnly platform scope — and binding is only safe once
         * one of them has run. They must still come after authentication, since
         * neither has anything to work from until there is a principal.
         *
         * prependToPriorityList() rather than priority(): priority() REPLACES the
         * framework's default map, which would mean pasting a copy of Laravel's
         * eleven-entry list into this file and silently keeping the Laravel 13
         * version of it through every future upgrade. The relative form states
         * the only thing we actually care about — "before binding" — and inherits
         * the rest.
         */
        $middleware->prependToPriorityList(
            Illuminate\Routing\Middleware\SubstituteBindings::class,
            App\Http\Middleware\SetTenantContext::class,
        );

        $middleware->prependToPriorityList(
            Illuminate\Routing\Middleware\SubstituteBindings::class,
            App\Http\Middleware\StaffOnly::class,
        );

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

        /*
         * Errors and crashes reach the audit log (§2.2, §10).
         *
         * "Audit everything" names three streams — actions, errors, crashes —
         * and only the first existed. AuditLogger::logError was written for this
         * and documented as the one thing the Lead Developer exemption does NOT
         * cover, but nothing had ever handed it an unhandled exception: five
         * hand-written refusals called it and the framework called it never. A
         * 500 left a line in storage/logs and no evidence in the audit trail at
         * all, which is the stream a compliance question is actually asked of.
         *
         * WHAT REACHES HERE. Handler::report() consults shouldntReport() first,
         * so the framework's own ignore list — ValidationException,
         * AuthenticationException, AuthorizationException, HttpException,
         * ModelNotFoundException — is filtered out before this runs. Every 404,
         * 403 and 422 the application answers on purpose is therefore NOT a row.
         * What is left is the class of thing nobody intended, in requests, queued
         * jobs and console commands alike.
         *
         * RETURNS NOTHING, deliberately. A reportable callback that returns false
         * stops the chain, and the next link is the framework writing the full
         * exception — message included — to the log channel. That copy is the
         * one with the detail; see ExceptionAudit for why the audit row does not
         * repeat it, and why the correlation id is the join between them. The
         * join is carried by AuditLogger::useCorrelationId, which shares the id
         * with the log channel — without that this sentence was describing
         * something nothing in the codebase did.
         *
         * ExceptionAudit::record() resolves its own collaborators inside its own
         * try/catch, so a container or database failure here cannot bury the
         * exception it was called about. Nothing is captured in this closure for
         * the same reason — it is built while the exception handler is being
         * resolved, which is far too early to construct anything that talks to
         * the database.
         */
        $exceptions->report(function (Throwable $e): void {
            App\Support\Audit\ExceptionAudit::record($e);
        });
    })->create();
