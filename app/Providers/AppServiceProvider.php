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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
