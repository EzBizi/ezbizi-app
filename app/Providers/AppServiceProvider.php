<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

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
         * failure §13 says must not be possible via "scheduled tasks, automation
         * actions, queued jobs".
         *
         * `scoped` is reset by Laravel between requests AND between queue jobs,
         * which is precisely the lifetime the cache is allowed to have.
         */
        $this->app->scoped(\App\Support\Subscriptions\SubscriptionGuard::class);

        /*
         * Scoped for the same reason, and it was UNBOUND — which meant its cache
         * had never worked once.
         *
         * `User::hasPermission()` resolves ModuleAvailability out of the
         * container on every call, and an unbound concrete class is built fresh
         * each time. So the `$cache` field on it was populated, used once, and
         * thrown away: every single permission check in the application ran
         * `PlatformModuleFlag::all()`. A request that authorises a list of
         * twenty rows did twenty full table reads of a table that changes about
         * once a quarter.
         *
         * Not a singleton: platform flags are read from the database, and a
         * queue worker lives for days. A module disabled during an incident has
         * to take effect in the workers too, and `scoped` is reset between
         * requests AND between queue jobs — exactly the lifetime this cache is
         * allowed to have. setState() and flushCache() clear it within one.
         */
        $this->app->scoped(\App\Support\Modules\ModuleAvailability::class);

        /*
         * Scoped, for the same reason and with the same lifetime.
         *
         * ScopeReach memoises what each person can reach, and the answer costs
         * two full table reads to work out (`reachableCompanies` and
         * `reachableFranchises` both read everything and filter in PHP). One HTTP
         * request asks that question from the controller, from the policy, and
         * once per row of the people list — and an unbound concrete class is
         * built fresh at every injection point, so each of those would keep its
         * own empty memo and the caching would do nothing.
         *
         * Not a singleton: reach is derived from assignments, which change, and
         * a queue worker lives for days. `scoped` is reset between requests AND
         * between jobs, which is exactly as long as the answer stays true.
         */
        $this->app->scoped(\App\Support\Access\ScopeReach::class);

        /*
         * Twilio HTTP seam for Phone. Tests replace this with FakeTwilioGateway
         * so nothing dials the live API from the suite.
         */
        $this->app->singleton(
            \App\Support\Phone\TwilioGateway::class,
            \App\Support\Phone\HttpTwilioGateway::class,
        );
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

        /*
         * `attachments.attachable_type` holds `jobs`, not `App\Models\Job`.
         *
         * Without a map, Eloquent writes the fully-qualified class name into
         * every polymorphic row — which makes moving or renaming a class a data
         * migration across a table nobody thinks about, and puts the shape of
         * the PHP namespace into the database and onto the wire.
         *
         * `morphMap` and not `enforceMorphMap`: enforcing would throw for any
         * morph relation whose class is not listed, and ApiToken's `tokenable`
         * is one — it stores FQCNs today, written by Sanctum, and rewriting
         * those is not this change's business.
         *
         * The map comes from Attachables so the wire name, the stored value and
         * the validation rule are one list rather than three.
         */
        \Illuminate\Database\Eloquent\Relations\Relation::morphMap(
            [
                ...\App\Support\Files\Attachables::all(),
                // Internal-only identities. They are intentionally absent from
                // Attachables::all(), so the generic public upload/list API can
                // never target Tasks or rebind an update attachment.
                'tasks' => \App\Models\Task::class,
                'task_updates' => \App\Models\TaskUpdate::class,
                // A submitted inspection report, for the same reason: its
                // photographs arrive by being re-pointed from the run at submit,
                // never by an upload naming the report. See Attachables.
                'inspection_reports' => \App\Models\InspectionReport::class,
            ],
        );

        $this->defineRateLimiters();

        /*
         * `/up` is already registered in bootstrap/app.php. Laravel's default
         * probe is 200 once the framework boots, including with MySQL stopped.
         * A peer watchdog built on that would prove nginx and PHP-FPM, not that
         * EzBizi can read its database — the failure the watchdog exists to catch.
         *
         * select 1 is cheap and fails closed. Backups, mail and TLS stay on the
         * staff Health screen; this probe does not claim them.
         */
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Foundation\Events\DiagnosingHealth::class,
            function (): void {
                \Illuminate\Support\Facades\DB::select('select 1');
            },
        );
    }

    /**
     * Named rate limiters, applied with `->middleware('throttle:<name>')`.
     *
     * ── This is the seam, and it was missing ─────────────────────
     *
     * Until signup there was no route-level rate limiting in this application at
     * all: no `throttle` middleware anywhere in routes/api.php. Four endpoints
     * are limited, and every one of them counts attempts by hand inside its
     * controller — login and forgot-password here in the tenant API, staff login
     * and staff enrolment in the control plane. They all answer a 429 with the
     * same message/code/retry_after body, which is the shape this reproduces.
     *
     * Counting by hand is the right call for three of those four, because they
     * only want to penalise FAILURES — a correct password should not spend
     * anyone's budget. (forgot-password is the exception: it charges every
     * request, since it deliberately cannot tell success from failure.)
     *
     * Signup inverts it: the expensive outcome is the SUCCESS. So it wants the
     * framework's middleware, which counts every request reaching the route, and
     * this method is where such routes declare their numbers. The seam other
     * unthrottled endpoints should use is this registration site, not this
     * limiter — each gets its own named entry beside this one, because one
     * number shared across unrelated routes is the wrong number for most of them.
     */
    private function defineRateLimiters(): void
    {
        /*
         * Signup: 10 per hour per IP.
         *
         * WHAT IT PROTECTS AGAINST: one host minting tenants in a loop. Each
         * success writes an organization, a role, a user, a company, a franchise
         * and an assignment, and there is no email verification to slow it down
         * (TODO #22, blocked by #1), so an unlimited public endpoint here is a
         * way to fill the database from a shell script.
         *
         * WHY TEN AND NOT FIVE: the middleware counts every request, including
         * the 422s. A number tuned to how often a business legitimately signs up
         * — once — would lock somebody out of the product for an hour for
         * mistyping their password twice. Ten leaves room for a bad form-filling
         * day and still caps tenant creation from one address at ten an hour.
         *
         * WHAT IT DOES NOT PROTECT AGAINST: a distributed flood. Ten per IP is
         * ten thousand tenants from a thousand hosts, and this limiter cannot see
         * that they are related. Nor does it protect a shared office NAT from its
         * own noisiest user — there is no prior account to key on at signup, so
         * IP is the only key available. The answers to both are email
         * verification or a challenge; neither exists yet, and neither is
         * pretended at here.
         */
        RateLimiter::for('signup', fn (Request $request) => Limit::perHour(10)
            ->by($request->ip())
            ->response($this->throttledWith('Too many signups from this address.')));

        /*
         * Invitation links: 30 an hour per IP, across BOTH public endpoints.
         *
         * WHAT IT PROTECTS AGAINST: the two things an unauthenticated endpoint
         * that takes a credential is for. Guessing — 48 random characters make
         * that hopeless arithmetically, but "hopeless" assumes somebody is
         * paying for the attempts, and an unlimited endpoint is free. And load:
         * every request here is a hash lookup, and the POST then hashes a
         * password, which is deliberately slow and therefore deliberately
         * expensive to ask for over and over.
         *
         * WHY THIRTY: a real person opening a link spends a handful — the page,
         * a reload or two, the submit, perhaps a password the organization's
         * policy refuses. A whole visit is under ten. Thirty leaves room for
         * several people joining on the same morning from one office NAT, which
         * is the normal case this must not break, and still caps a script hard.
         *
         * KEYED BY IP, NOT BY TOKEN. Keying on the token would let an attacker
         * rotate guesses freely and never spend more than one attempt on any of
         * them, which is precisely backwards — the budget belongs to whoever is
         * asking, and the token is the thing being asked about.
         *
         * WHAT IT DOES NOT PROTECT AGAINST: a distributed attempt, for the same
         * reason signup's does not. Against a random 48-character token that is
         * an acceptable gap; against a guessable one it would not be, which is
         * why the token is not guessable.
         */
        RateLimiter::for('invitation', fn (Request $request) => Limit::perHour(30)
            ->by($request->ip())
            ->response($this->throttledWith('Too many attempts from this address.')));
    }

    /**
     * The 429 body, written once.
     *
     * The same shape login's hand-rolled throttle returns, because the client
     * parses ONE shape for a 429 and there is no reason for a route to be the
     * exception. Laravel's default is a bare "Too Many Attempts." with no code
     * and no retry_after.
     *
     * The wait is IN the sentence, not only in the field beside it. The client
     * renders `message` verbatim on a 429, on the stated reasoning that the
     * response "carries the only number worth reading — how long until they can
     * try again". It said "Try again later", and nothing read `retry_after`, so
     * the number the argument rests on never reached anybody. Either the
     * sentence carries it or the claim was false.
     *
     * Shared rather than copied into each limiter: two limiters wording the same
     * 429 two different ways is how a client ends up with two parsers.
     */
    private function throttledWith(string $reason): callable
    {
        return function (Request $request, array $headers) use ($reason) {
            $seconds = (int) ($headers['Retry-After'] ?? 0);

            $wait = match (true) {
                $seconds <= 0    => 'shortly',
                $seconds < 60    => "in {$seconds} seconds",
                $seconds < 3600  => 'in '.max(1, (int) round($seconds / 60)).' minutes',
                default          => 'in '.max(1, (int) round($seconds / 3600)).' hours',
            };

            return response()->json([
                'message'     => "{$reason} Try again {$wait}.",
                'code'        => 'throttled',
                'retry_after' => $seconds ?: null,
            ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
        };
    }
}
