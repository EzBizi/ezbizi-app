<?php

namespace App\Support\Audit;

use App\Models\PlatformStaff;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
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
 * the log line that has the message.
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

        /*
         * The tenant has already been forgotten by the time we get here.
         *
         * SetTenantContext clears the context in a `finally`, and that block
         * runs as the exception unwinds — long before the framework reports it.
         * So `log()` would write organization_id null, and the crash would be
         * missing from the history of the customer it happened to.
         *
         * Recovered from the actor, which is the same source SetTenantContext
         * itself uses ("the tenant comes from the AUTHENTICATED USER, never
         * from the request") — not an inference, the same rule applied later.
         */
        $organization = $actor instanceof User ? $actor->organization : null;

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

    private function describe(Throwable $e): array
    {
        $context = [
            'exception' => $e::class,
            'file'      => $this->relative($e->getFile()),
            'line'      => $e->getLine(),
            'trace'     => $this->frames($e),
        ];

        // The class only. A wrapped exception's message is free text for exactly
        // the same reasons the outer one's is.
        if ($previous = $e->getPrevious()) {
            $context['previous'] = $previous::class;
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
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');

            $where = isset($frame['file'])
                ? $this->relative($frame['file']).':'.($frame['line'] ?? '?')
                : '[internal]';

            $frames[] = $call === '' ? $where : "{$call} ({$where})";
        }

        return $frames;
    }

    /**
     * Paths relative to the deploy root.
     *
     * `/var/www/ezbizi-releases/20260811182941/app/...` says nothing a reader
     * needs and puts the release layout of the box into a table clients can
     * read.
     */
    private function relative(string $path): string
    {
        $base = base_path().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
