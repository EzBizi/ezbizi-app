<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per process — see plan §5.1
        $this->app->singleton(\App\Support\Tenancy\TenantContext::class);

        /*
         * AuditLogger holds per-request state — the correlation id, and whether
         * Lead Developer suppression is active — and every caller is written as
         * though there were one of it.
         *
         * Unbound concrete classes are NOT shared by the container. Without this
         * line the middleware sets the correlation id on its own instance, each
         * controller and service gets a fresh one that lazily mints a different
         * uuid, and the X-Correlation-Id handed to the client matches no row in
         * audit_events — which is the entire point of having one. Worse,
         * suppressForLeadDeveloper() wrapped around the request pipeline
         * suppresses nothing outside the middleware object itself.
         */
        $this->app->singleton(\App\Support\Audit\AuditLogger::class);

        /*
         * SettingsResolver caches resolved overrides per scope, and set() flushes
         * that cache — but only its OWN. Left unbound, two instances mean two
         * caches: change a setting through one and the other keeps serving the
         * old value until the process ends. The failure is silent and looks like
         * the setting did not save, which is the worst kind of bug in a settings
         * system.
         *
         * One instance, one cache, and set() invalidates the one everything reads.
         *
         * Cache keys are scopeType:uuid, so this cannot serve one organization's
         * overrides to another even in a long-lived worker — staleness was the
         * risk here, not leakage.
         */
        $this->app->singleton(\App\Support\Settings\SettingsResolver::class);

        /*
         * Scoped, NOT singleton — the distinction is the whole point.
         *
         * SubscriptionGuard caches whether an organization is read-only, and a
         * singleton in a queue worker lives for days. The first organization the
         * worker ever touched would stay cached, so a subscription that lapsed at
         * noon kept writing until somebody restarted the process — the exact
         * failure §13 says must not be possible via "scheduled tasks, workflow
         * actions, queued jobs".
         *
         * `scoped` is reset by Laravel between requests AND between queue jobs,
         * which is precisely the lifetime the cache is allowed to have.
         */
        $this->app->scoped(\App\Support\Subscriptions\SubscriptionGuard::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Sanctum resolves a bearer token to the account that issued it, and
         * that lookup necessarily happens before any tenant is in context — the
         * tenant is a property of the account being looked up.
         *
         * The stock PersonalAccessToken has no way to say so, so the read went
         * through the tenant scope with nothing set. See App\Models\ApiToken for
         * what that meant while the scope failed open, and why the exemption is
         * one named scope on one relation rather than anything wider.
         */
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\ApiToken::class);
    }
}
