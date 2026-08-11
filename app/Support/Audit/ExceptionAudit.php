<?php

namespace App\Support\Audit;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The second and third streams §2.2 names: errors and crashes.
 *
 * Registered as a reportable callback in bootstrap/app.php, so every exception
 * the framework reports — from an HTTP request, a queued job or a console
 * command — leaves a row. `AuditLogger::logError` already existed and was
 * documented as the carve-out that survives Lead Developer suppression; until
 * now the only things calling it were five hand-written refusals. An unhandled
 * exception produced nothing at all, which is the gap the compliance audit
 * found: of R2's three streams, one was implemented.
 *
 * ── What is recorded, and why the message is not ─────────────────
 *
 * `AuditLogger::REDACT` cannot protect this payload, and it is worth being
 * exact about why rather than pointing it at the problem and hoping.
 *
 * REDACT matches KEYS. It walks an array, lowercases each key and looks for
 * `password`, `token`, `ssn` and the rest as substrings. That works for
 * `['password' => 'hunter2']` and does nothing whatsoever for a STRING: an
 * exception message and a stack trace are free text, so there are no keys to
 * match and the whole list is inert against them. Two concrete leaks it would
 * wave straight through:
 *
 *   · `Throwable::getTraceAsString()` prints arguments. A TypeError raised
 *     inside `Hash::check($plaintext, $hash)` renders as
 *     `#3 ...: Hash::check('hunter2', '$2y$12$...')`.
 *   · A `QueryException` message carries the SQL and its bindings, so an insert
 *     that fails against `onboarding_answers` puts the answer in the message —
 *     and those fields are named by the TENANT, which REDACT's hardcoded
 *     English list could never have recognised even if they were keys. This is
 *     the same argument `AuditLogger::logChange` makes about encrypted columns,
 *     and the answer is the same: do not filter, do not carry it.
 *
 * So the payload is composed only of values whose provenance is this codebase:
 *
 *   exception  the class name
 *   file:line  where it was raised, relative to the deploy root
 *   trace      frames as `Class::method (file:line)`, arguments DROPPED
 *   method     the HTTP verb, when there was a request
 *   route      the route's URI PATTERN — `hr/onboardings/{onboarding}` — never
 *              the filled-in path and never the query string, which is where a
 *              reset token or an invitation token would be
 *
 * The message is deliberately absent. Laravel's own reporting still writes it,
 * with the full trace, to the log channel — this callback returns null, which
 * leaves the default handler to run — and `storage/logs` is rotatable,
 * purgeable and not tenant-visible. `audit_events` is none of those things: it
 * is append-only by construction, erasure cannot reach it (see AuditEvent), and
 * a tenant admin reads their own organization's rows. A value that lands here
 * cannot be taken back, so the one place a secret must never be written is the
 * one place free text must not go.
 *
 * What is left still answers the question a fix starts from — which exception,
 * where, reached how — and the `correlation_id` on the row is the join back to
 * the log line that has the message. That join is a real one: AuditLogger puts
 * the id on the log channel (`Log::shareContext`) at the moment it decides it,
 * so the framework's own report — which runs after this callback returns —
 * carries it. It used to be a claim and nothing more: the id went on the row and
 * on an X-Correlation-Id response header, and no log line had ever heard of it,
 * which made "the message is over there" advice nobody could follow.
 */
class ExceptionAudit
{
    /**
     * `error.` on purpose: ExpireAuditEvents retains that prefix for one month,
     * which is §10's stated retention for crash logs. A different verb here
     * would silently fall through to the three-month business bucket.
     */
    public const ACTION = 'error.unhandled';

    /**
     * Enough to see how the code got there, not so much that one crash loop
     * fills the table. The frames past this point are almost always the
     * framework's own dispatch stack, which is identical for every request.
     */
    private const FRAMES = 20;

    /**
     * ── The valve, and why an append-only crash table needs one ──
     *
     * Every reported exception writes a row, the table is append-only by
     * construction, erasure cannot reach it and retention only sweeps it once a
     * month. So the write rate is the only thing standing between "we record
     * crashes" and "anyone who can reliably provoke a 500 can fill the disk" —
     * and that is not a quiet failure mode, because it fires hardest exactly
     * when something is already wrong. A crash loop in a queue worker retrying
     * the same job, or an unauthenticated caller hitting one bad route in a
     * loop, is thousands of identical twenty-frame rows an hour.
     *
     * Capping FRAMES bounded the size of one row and did nothing about the
     * number of them, which is the axis that runs away.
     *
     * So: one row per DISTINCT crash per window, and a ceiling — PER
     * ORGANIZATION — on how many distinct crashes a window may record at all.
     * The second is what stops a fault that varies its own fingerprint — one bad
     * deploy breaking a hundred routes at once — walking straight past the
     * first.
     *
     * WHAT IS LOST, stated rather than glossed. Two different things, and the
     * second is the expensive one:
     *
     *   · Repeats. The second and subsequent occurrences of the SAME crash
     *     inside a window leave no audit row. What the audit row is for is "this
     *     happened, to this customer, at this time", and one row per five
     *     minutes answers that.
     *
     *   · A burnt budget. Once an organization has recorded
     *     DISTINCT_PER_WINDOW distinct crashes in a window, its next crash
     *     leaves no row EITHER — not a repeat, a first of its kind, simply
     *     dropped until the window rolls. That is a real hole and not a thinning
     *     one: for the rest of those five minutes that organization's crash
     *     trail is blank, and the table cannot tell you so, because a missing
     *     row and a quiet five minutes look identical from the inside. The
     *     budget is per organization precisely so that hole can only ever be dug
     *     by the customer standing in it — it used to be one counter for the
     *     platform, which let anyone who could provoke forty distinct 500s turn
     *     crash auditing off for everybody.
     *
     * Both are still fully reported to the log channel, with the message and the
     * whole trace, and the correlation id joins the two — so a dropped row is
     * never the only record, and `storage/logs` is where you go when the table
     * looks too quiet. R2 asks for every crash to be audited, not for the audit
     * trail to be a denial-of-service surface.
     */
    private const WINDOW = 300;

    /**
     * Distinct crashes ONE ORGANIZATION may record in a window before the rest
     * of its crashes wait for the next one.
     */
    public const DISTINCT_PER_WINDOW = 40;

    /**
     * The platform-wide backstop, and only that.
     *
     * Deliberately two orders of magnitude above the per-organization ceiling so
     * that it is never the constraint that actually binds. Its job is the
     * original one — the table cannot grow without bound — for the case no
     * per-organization ceiling covers: a great many organizations all crashing
     * at once. Reaching this number means something is wrong with the platform,
     * not with a tenant, and by then the log channel is the record that matters.
     */
    public const DISTINCT_PER_WINDOW_PLATFORM = 4_000;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * The entry point the framework calls, and the only guard.
     *
     * Recording an error must never replace it. If the database is what broke,
     * the audit write is going to fail too — and an exception raised from inside
     * the exception handler buries the original: the caller is told about OUR
     * failure instead of theirs, and the log line naming the real fault never
     * gets written either.
     *
     * Static, and resolving from the container itself, so that the resolution is
     * inside the guard rather than beside it. Constructor injection performed by
     * the caller would be one container lookup that this class cannot protect,
     * which is the same hole in a less obvious place.
     */
    public static function record(Throwable $e): void
    {
        try {
            app(self::class)->write($e);
        } catch (Throwable) {
            // Deliberately silent. See above. The framework's own reporting runs
            // after this callback returns and still writes the full exception to
            // the log channel.
        }
    }

    public function write(Throwable $e): void
    {
        $context = $this->describe($e);
        $actor   = $this->actor();

        /*
         * Which customer's history the crash belongs in, asked three ways in
         * the order they are trustworthy.
         *
         * This used to say the tenant had already been forgotten by now,
         * because SetTenantContext clears it in a `finally` — and that is
         * wrong. Illuminate\Routing\Pipeline::carry() wraps EVERY pipe in its
         * own try/catch and reports from the innermost one, so on the HTTP path
         * report() runs while SetTenantContext is still inside its own `try`
         * and the tenant is very much still set. The recovery below was solving
         * a problem the request path does not have, and worse: a crash inside a
         * nested `run()` for a DIFFERENT organization would have been filed
         * under the actor's own.
         *
         *   1. the organization that was current when the throwable escaped a
         *      `TenantContext::run()`. This is the queue and console answer, and
         *      the only one for them — a worker has no `auth()->user()` to fall
         *      back on, and `run()` restores before Worker::process() reports.
         *      It is also the RIGHT answer for a nested run inside a request.
         *   2. whatever is still in context — the ordinary HTTP case.
         *   3. the actor's own organization, for an exception reported from
         *      outside any of that. Same source SetTenantContext itself uses
         *      ("the tenant comes from the AUTHENTICATED USER, never from the
         *      request") — not an inference, the same rule applied later.
         *
         * Resolved before the staff branch because the valve below is scoped by
         * it, and a valve scoped differently from the row it is guarding would
         * let one organization's crash loop hide another organization's crash.
         */
        $organization = $this->tenant->organizationWhenThrown($e)
            ?? $this->tenant->get()
            ?? ($actor instanceof User ? $actor->organization : null);

        // Ahead of either branch, so a staff crash loop is bounded the same way.
        if (! $this->firstOfItsKindThisWindow($context, $organization?->getKey())) {
            return;
        }

        /*
         * Staff are a separate actor type and a separate stream (§4.2, §10).
         *
         * `logError()` would take this path through `log()`, whose `$actor`
         * parameter is typed `?User` — and whose `$actor ??= auth()->user()`
         * happily assigns a PlatformStaff to it, because PHP re-checks a
         * parameter's type at call time only. The row would come out as
         * actor_type `user` carrying a staff id: wrong on its face, and wrong
         * for retention, which sorts staff rows by actor_type.
         */
        if ($actor instanceof PlatformStaff) {
            $this->audit->logStaff(
                action: self::ACTION,
                staffId: $actor->getKey(),
                staffLabel: "{$actor->name} <{$actor->email}>",
                metadata: $context,
            );

            return;
        }

        $write = fn () => $this->audit->logError(self::ACTION, $context, $actor);

        $organization === null
            ? $write()
            : $this->tenant->run($organization, $write);
    }

    /** The signed-in principal, or null — including in console and queue runs. */
    private function actor(): ?object
    {
        return auth()->user();
    }

    /**
     * The valve. See the WINDOW constant for what it is protecting against.
     *
     * `add()` rather than `has()` then `put()`: it is the atomic one, so two
     * workers crashing on the same thing in the same millisecond write one row
     * between them rather than one each.
     *
     * The fingerprint includes the organization the row would be filed under —
     * the one resolved by write(), not whatever happens to be in context here —
     * so one organization's crash loop cannot hide an identical crash happening
     * to somebody else, which would be the worst possible way to lose a row.
     *
     * FAILS OPEN, deliberately. This is a safety valve, not an access control:
     * if the cache store is the thing that is broken, the right answer is a
     * crash row too many, not a crash nobody recorded. The write itself is
     * already wrapped by record().
     */
    private function firstOfItsKindThisWindow(array $context, ?string $organizationId): bool
    {
        try {
            $cache = Cache::store();

            $fingerprint = 'audit:crash:'.hash('xxh128', implode('|', [
                $context['exception'],
                $context['file'],
                (string) $context['line'],
                $context['route'] ?? '',
                (string) $organizationId,
            ]));

            if (! $cache->add($fingerprint, true, self::WINDOW)) {
                return false;
            }

            /*
             * A separate ceiling on DISTINCT crashes, because a fault that
             * varies its own fingerprint walks past the check above.
             *
             * SCOPED BY ORGANIZATION, and that is the whole point of it. This
             * was one key for the entire platform, which made it a switch for
             * turning crash auditing off for every customer at once: forty
             * distinct fingerprints inside a five-minute window and nothing was
             * recorded for anybody until it rolled. Reachable without
             * authenticating — forty routes that 500 is forty distinct
             * fingerprints — so it was also a way to blind the crash trail
             * deliberately before doing something else. A bound that an attacker
             * can spend on your behalf is worse than the unbounded growth it was
             * added to prevent.
             *
             * The platform key survives underneath as a genuine backstop, at a
             * ceiling far enough above this one that it cannot be what binds.
             *
             * The unattributed bucket is shared: console crashes, queue crashes
             * outside a `run()`, and every unauthenticated 500 count against
             * each other, because there is no organization to tell them apart.
             * A flood there can still crowd out another unattributed crash.
             * What it can no longer do is reach a tenant's rows, which is the
             * part that had to stop.
             */
            $scope = $organizationId ?? 'unattributed';

            if (! $this->withinBudget($cache, 'audit:crash:distinct:'.$scope, self::DISTINCT_PER_WINDOW)) {
                return false;
            }

            return $this->withinBudget($cache, 'audit:crash:distinct', self::DISTINCT_PER_WINDOW_PLATFORM);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Spend one unit of a windowed budget, and say whether there was one to
     * spend.
     *
     * `add()` before `increment()`: add() writes only when the key is absent, so
     * the window is measured from the first crash that fell into it and expires
     * WINDOW seconds later however many follow. Once it has expired, the next
     * call recreates the counter at zero with a fresh window — a spent budget is
     * never resurrected, and never extended by the crashes that hit it.
     */
    private function withinBudget(Repository $cache, string $key, int $ceiling): bool
    {
        $cache->add($key, 0, self::WINDOW);

        return $cache->increment($key) <= $ceiling;
    }

    private function describe(Throwable $e): array
    {
        $context = [
            // Through identifier(), not raw. A class name is not automatically a
            // safe string: an anonymous exception class is named
            // `RuntimeException@anonymous`, a NUL byte, then the ABSOLUTE file
            // it was declared in. The trace was scrubbed and these two were not,
            // which put the deploy root into the row through the door next to
            // the one that had just been shut.
            'exception' => $this->identifier($e::class),
            'file'      => $this->withoutDeployRoot($e->getFile()),
            'line'      => $e->getLine(),
            'trace'     => $this->frames($e),
        ];

        // The class only. A wrapped exception's message is free text for exactly
        // the same reasons the outer one's is.
        if ($previous = $e->getPrevious()) {
            $context['previous'] = $this->identifier($previous::class);
        }

        $request = app()->bound('request') ? app('request') : null;

        // `route()` is null in console, in queue workers, and for a request that
        // never matched — which is precisely when there is nothing to say.
        if ($request instanceof Request && ($route = $request->route()) !== null) {
            $context['method'] = $request->method();
            $context['route']  = $route->uri();
        }

        return $context;
    }

    /**
     * Frames without arguments.
     *
     * `getTrace()` returns an `args` key per frame holding the actual values
     * passed. They are never read here, and that omission is the whole safety
     * property — `getTraceAsString()` is unusable for this table because it
     * renders them.
     */
    private function frames(Throwable $e): array
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, self::FRAMES) as $frame) {
            $call = $this->callName($frame);

            $where = isset($frame['file'])
                ? $this->withoutDeployRoot($frame['file']).':'.($frame['line'] ?? '?')
                : '[internal]';

            $frames[] = $call === '' ? $where : "{$call} ({$where})";
        }

        return $frames;
    }

    /**
     * A frame's callable, named in a way that is safe to store.
     *
     * The obvious version of this — concatenate class, type and function — is
     * what was here, and on PHP 8.4 it leaks the deploy root into the very
     * column the scrubbing below exists to keep it out of. Two identifiers carry
     * an ABSOLUTE PATH inside the name itself:
     *
     *   · a closure is `{closure:/var/www/ezbizi-releases/…/routes/api.php:407}`
     *   · an anonymous class is `class@anonymous`, a NUL byte, then
     *     `<absolute file>:<line>$<counter>`
     *
     * Neither is hypothetical here: routes/api.php and routes/web.php both
     * declare file-scope route closures, and every file in database/migrations/
     * is an anonymous class — so any migration that failed wrote the release
     * directory, NUL byte and all, into a table tenant admins read.
     */
    private function callName(array $frame): string
    {
        return $this->identifier(
            ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '')
        );
    }

    /**
     * A name PHP generated, made safe to store.
     *
     * Every generated identifier that reaches the row goes through here — the
     * frames from callName() above, and `exception` and `previous` from
     * describe(). It was only the frames, which left the two class names one
     * method away from the scrubber and carrying exactly what it exists to
     * remove.
     *
     * The NUL becomes a colon rather than being dropped with what follows: the
     * defining file is the only thing that tells one anonymous class from
     * another, and once the deploy root is off it is exactly as safe as any
     * other path on the row.
     */
    private function identifier(string $name): string
    {
        return $this->withoutDeployRoot(str_replace("\0", ':', $name));
    }

    /**
     * The deploy root, out of anything on its way into the row.
     *
     * `/var/www/ezbizi-releases/20260811182941/app/...` says nothing a reader
     * needs and puts the release layout of the box into a table clients can
     * read.
     *
     * A replacement rather than a prefix strip. Anchoring at the start is right
     * for `getFile()` and blind to callName()'s two cases above, where the path
     * sits in the middle of an identifier — which is how the thing this method
     * exists to prevent was happening through the other field on the same row.
     */
    private function withoutDeployRoot(string $text): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $text);
    }
}
