<?php

namespace App\Providers;

use App\Models\ApiToken;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use App\Observers\JobThumbtackObserver;
use App\Services\Leads\LeadInsights;
use App\Services\Leads\LocalServicesLeads;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Files\Attachables;
use App\Support\Leads\LeadReplies;
use App\Support\Leads\LeadThread;
use App\Support\Modules\ModuleAvailability;
use App\Support\Phone\HttpTwilioGateway;
use App\Support\Phone\TwilioGateway;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\MetaMessageReplies;
use App\Support\Social\WhatsAppReplies;
use App\Support\Social\MetaMessages;
use App\Support\Subscriptions\SubscriptionGuard;
use App\Support\Tasks\TaskBoards;
use App\Support\Tenancy\TenantContext;
use App\Support\Web\WebsiteChatReplies;
use Aws\BedrockRuntime\BedrockRuntimeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per process — see plan §5.1
        $this->app->singleton(TenantContext::class);

        // Bedrock, for what a model can say about a lead. No keys, no client:
        // LeadInsights::enabled() is false and every caller shows nothing.
        $this->app->bind(LeadInsights::class, function () {
            $cfg = (array) config('services.bedrock');
            $on = ($cfg['key'] ?? '') !== '' && ($cfg['secret'] ?? '') !== '' && ($cfg['model'] ?? '') !== '';

            return new LeadInsights(
                $on ? new BedrockRuntimeClient([
                    'version' => 'latest',
                    'region' => (string) ($cfg['region'] ?? 'us-east-1'),
                    'credentials' => ['key' => $cfg['key'], 'secret' => $cfg['secret']],
                ]) : null,
                (string) ($cfg['model'] ?? ''),
            );
        });

        /*
         * Where a reply to a lead goes, per source.
         *
         * Both are here rather than one of them in a provider of its own. A
         * whole ServiceProvider for a single tag line is a file to register,
         * a file to find and a file to forget, and the two channels are
         * easier to compare one above the other. The tag is what the
         * controller reads, so neither module learns the other's name.
         */
        /*
         * Meta's two are bound by NAME rather than tagged by class, because
         * `MetaMessageReplies` takes its source as a constructor argument -
         * one class answering for Messenger and Instagram so the 24-hour rule
         * cannot drift between them. The container cannot build that from a
         * bare class name, so each source gets a binding and both are tagged.
         */
        $this->app->bind(
            'lead-reply-channel.facebook',
            fn ($app) => new MetaMessageReplies(
                Lead::SOURCE_FACEBOOK,
                $app->make(MetaMessages::class),
                $app->make(LeadThread::class),
            ),
        );

        $this->app->bind(
            'lead-reply-channel.instagram',
            fn ($app) => new MetaMessageReplies(
                Lead::SOURCE_INSTAGRAM,
                $app->make(MetaMessages::class),
                $app->make(LeadThread::class),
            ),
        );

        $this->app->bind('lead-reply-channel.whatsapp', WhatsAppReplies::class);

        $this->app->tag(
            [
                LocalServicesLeads::class,
                ThumbtackLeads::class,
                WebsiteChatReplies::class,
                'lead-reply-channel.facebook',
                'lead-reply-channel.instagram',
                'lead-reply-channel.whatsapp',
            ],
            LeadReplies::TAG,
        );

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
        $this->app->singleton(AuditLogger::class);

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
        $this->app->singleton(SettingsResolver::class);

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
        $this->app->scoped(SubscriptionGuard::class);

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
        $this->app->scoped(ModuleAvailability::class);

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
        $this->app->scoped(ScopeReach::class);

        /*
         * Twilio HTTP seam for Phone. Tests replace this with FakeTwilioGateway
         * so nothing dials the live API from the suite.
         */
        $this->app->singleton(
            TwilioGateway::class,
            HttpTwilioGateway::class,
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
        Sanctum::usePersonalAccessTokenModel(ApiToken::class);

        /*
         * The only observer in this application, and it earns that.
         *
         * A job status changes down two paths — JobsController::update() and an
         * automation through ActionRunner — and Thumbtack has to be told on
         * both. ActionRunner already carries a comment about being forced to
         * duplicate the controller emits for exactly this reason; a third copy
         * would drift, and a drift here is a Pro whose completion record is
         * right when a person clicks and wrong when a rule does.
         */
        Job::observe(JobThumbtackObserver::class);

        // Task Board: every company, franchise and person gets their own board
        // the moment they exist (ezbizi:backfill-task-boards covers older rows).
        foreach ([Company::class, Franchise::class, User::class] as $owner) {
            $owner::created(fn ($model) => app(TaskBoards::class)->ensureFor($model));
        }

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
        Relation::morphMap(
            [
                ...Attachables::all(),
                // A submitted inspection report is intentionally absent from
                // Attachables::all() too: its photographs arrive by being
                // re-pointed from the run at submit, never by an upload naming
                // the report directly. See Attachables.
                'inspection_reports' => InspectionReport::class,
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
        Event::listen(
            DiagnosingHealth::class,
            function (): void {
                DB::select('select 1');
            },
        );

        /*
         * A mail send that failed in the queue worker throws its connection away.
         *
         * The worker keeps one SMTP connection for its whole life, and when SES
         * times that connection out the next send gets 451 on MAIL FROM.
         * Symfony answers a failure with RSET and keeps the connection, so the
         * sends after it kept failing until the worker restarted. That happened
         * on 2026-09-28 (three notices, a minute apart) and 2026-10-05 (two
         * password resets). Forgetting the mailer makes the next send build a fresh
         * transport. Only on a MAIL failure: any other job failing leaves a
         * healthy connection alone. See MailWorkerReconnectTest.
         */
        Event::listen(
            JobExceptionOccurred::class,
            function (JobExceptionOccurred $event): void {
                $mail = $this->app->make('mail.manager');

                if ($event->exception instanceof TransportExceptionInterface && $mail instanceof MailManager) {
                    $mail->forgetMailers();
                }
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

        /*
         * WordPress plugin reads: keyed on the TOKEN, with an IP ceiling behind it.
         *
         * WHY NOT PER IP ALONE, which is what every other public limiter here
         * does: these callers are shared web hosts. One IP is a hosting company
         * serving hundreds of unrelated businesses, so an IP bucket makes each
         * customer's website a rate-limiting neighbour of every other customer
         * on that box — and the site that gets throttled is not the site that
         * caused it. A token is one website, which is the unit that should be
         * answerable for its own traffic.
         *
         * SIXTY AN HOUR PER TOKEN: the plugin caches for five minutes, so a
         * healthy site asks twelve times an hour. Sixty is five times that,
         * which absorbs a cache flush, a settings save and somebody clicking
         * "Refresh now" repeatedly, and still caps one token.
         *
         * THE IP CEILING IS NOT THE SAME QUESTION. It is there for the case the
         * token key cannot see: somebody generating tokens, or hammering with
         * none at all, where every request lands in a different bucket or the
         * same empty one. Six hundred an hour is far above any real host's
         * legitimate total and far below a useful flood.
         *
         * WHAT NEITHER PROTECTS AGAINST: a distributed read with a VALID token.
         * That is a customer's own token being abused, and the answer to it is
         * rotation in the SEO tab rather than a limiter.
         */
        /*
         * A stranger's browser creating a Lead. Per KEY so one site cannot be
         * used to fill a company's list, and per ADDRESS so one machine cannot
         * walk many sites' keys -- either alone is trivially stepped around.
         *
         * ponytail: 20/hour is generous for a human and useless against a
         * botnet. The fix when that day comes is a challenge on the widget, not
         * a smaller number: a limit low enough to stop a bot would also lose a
         * busy afternoon's real enquiries.
         */
        RateLimiter::for('web-enquiry', fn (Request $request) => [
            Limit::perHour(20)
                ->by('key:'.hash('sha256', (string) $request->input('key')))
                ->response($this->throttledWith('Too many messages from this website just now. Try again shortly.')),
            Limit::perHour(40)
                ->by('addr:'.$request->ip())
                ->response($this->throttledWith('Too many messages from this address just now.')),
        ]);

        /*
         * Reading your own chat back is not the same act as creating one, and
         * must not share its budget: a visitor waiting for an answer polls
         * every few seconds, which is exactly the behaviour the limit above
         * exists to stop.
         */
        RateLimiter::for('web-chat-poll', fn (Request $request) => Limit::perMinute(60)
            ->by('chat:'.hash('sha256', (string) $request->bearerToken()))
            ->response($this->throttledWith('Asking a little too often. It will catch up shortly.')));

        RateLimiter::for('web-plugin', fn (Request $request) => [
            Limit::perHour(60)
                ->by('token:'.hash('sha256', (string) $request->bearerToken()))
                ->response($this->throttledWith('This website is asking too often. It will catch up shortly.')),
            /*
             * Every customer site lives on one box, so they share one address:
             * 420-560 reads an hour on a normal day of 2026-10-08, against 600.
             * Eight sites paired that day and a burst of maintenance passes
             * put all of them into 429 (Web, 2026-10-08). The per-token limit
             * above is what holds a single site; this only has to stop one
             * address walking many tokens.
             */
            Limit::perHour(5000)
                ->by('host:'.$request->ip())
                ->response($this->throttledWith('Too many requests from this address.')),
        ]);
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
                $seconds <= 0 => 'shortly',
                $seconds < 60 => "in {$seconds} seconds",
                $seconds < 3600 => 'in '.max(1, (int) round($seconds / 60)).' minutes',
                default => 'in '.max(1, (int) round($seconds / 3600)).' hours',
            };

            return response()->json([
                'message' => "{$reason} Try again {$wait}.",
                'code' => 'throttled',
                'retry_after' => $seconds ?: null,
            ], Response::HTTP_TOO_MANY_REQUESTS, $headers);
        };
    }
}
