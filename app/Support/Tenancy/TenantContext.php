<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * The single source of truth for "which organization are we acting as".
 *
 * Plan §5.1. This exists because a Laravel global scope is NOT a tenant boundary —
 * it covers Eloquent reads in an HTTP request and nothing else. Raw queries, queue
 * workers, scheduled commands, exports, broadcasts and console commands all run
 * outside it. Every one of those is a real path to a cross-tenant leak, which the
 * plan names the highest-severity bug class in the system.
 *
 * So tenancy is explicit and process-wide: something must *set* the tenant, and
 * anything tenant-aware asks this class who that is. A job that forgets to restore
 * its tenant gets an exception, not somebody else's data.
 */
class TenantContext
{
    private ?Organization $organization = null;

    /** True when running as platform staff, deliberately outside any tenant (§4.2). */
    private bool $platformScope = false;

    /**
     * Which organization was current when a throwable escaped `run()`.
     *
     * The restore in `run()`'s `finally` is not optional — it is what stops one
     * job's tenant leaking into the next — but it runs as the exception unwinds,
     * so by the time anything REPORTS that exception the tenant is gone. On the
     * HTTP path that does not matter (see organizationWhenThrown), and in a
     * queue worker it is the whole problem: every crash inside
     * `TenantContext::run($org, ...)` was recorded against no organization at
     * all, which is exactly the customer whose history needs it.
     *
     * A WeakMap keyed by the throwable, so an entry lives precisely as long as
     * the exception does and nothing accumulates in a long-lived process.
     *
     * @var WeakMap<Throwable, Organization>
     */
    private WeakMap $blame;

    public function __construct()
    {
        $this->blame = new WeakMap();
    }

    public function set(Organization $organization): void
    {
        $this->organization = $organization;
        $this->platformScope = false;
    }

    public function forget(): void
    {
        $this->organization = null;
        $this->platformScope = false;
    }

    public function has(): bool
    {
        return $this->organization !== null;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?string
    {
        return $this->organization?->getKey();
    }

    /**
     * The id, or blow up.
     *
     * Used where proceeding without a tenant would be a data leak. Failing loudly
     * is the entire point: a silent null here becomes an unscoped query, which
     * returns every organization's rows.
     */
    public function idOrFail(): string
    {
        if ($this->platformScope) {
            throw new RuntimeException(
                'Tenant-scoped operation attempted while in platform scope. '
                .'Platform staff must enter a tenant explicitly (see plan §4.2).'
            );
        }

        return $this->id() ?? throw new RuntimeException(
            'No tenant in context. Anything touching tenant data must run inside '
            .'TenantContext::run(), or after a request has resolved its organization.'
        );
    }

    /**
     * Enter platform scope — for EzBizi staff working across organizations (§4.2).
     * Deliberately distinct from "no tenant set", so an unscoped query caused by a
     * bug can never be mistaken for legitimate platform access.
     */
    public function enterPlatformScope(): void
    {
        $this->organization = null;
        $this->platformScope = true;
    }

    public function inPlatformScope(): bool
    {
        return $this->platformScope;
    }

    /**
     * Run a callback as a given organization, then restore whatever was set before.
     *
     * This is how queued jobs, console commands and schedulers get a tenant. The
     * restore is in a finally block so an exception mid-callback cannot strand the
     * process in the wrong tenant — in a queue worker, which handles many jobs in
     * one long-lived process, that would leak the next job's data into this one's.
     */
    public function run(Organization $organization, callable $callback): mixed
    {
        $previousOrg = $this->organization;
        $previousPlatform = $this->platformScope;

        $this->set($organization);

        try {
            return $callback();
        } catch (Throwable $e) {
            // Noted before the `finally` below erases the answer, and only if
            // nothing deeper has claimed it already: while one throwable travels
            // outward through nested runs, the INNERMOST tenant is the one it
            // happened to, and the check below is what keeps the outer ones from
            // overwriting that.
            //
            // The exception, said plainly: this is about THIS throwable, not
            // about the fault. If a callback catches an inner run's failure and
            // rethrows it WRAPPED, the wrapper is a throwable no inner run has
            // ever seen, so this run claims it — and the row is filed where the
            // wrapping happened, not where the fault did. Nothing in the
            // codebase catches and wraps inside a `run()`; if something starts
            // to, that is the case to think about. See
            // organizationWhenThrown(), which is the other half of the same
            // caveat.
            if (! isset($this->blame[$e])) {
                $this->blame[$e] = $organization;
            }

            throw $e;
        } finally {
            $this->organization = $previousOrg;
            $this->platformScope = $previousPlatform;
        }
    }

    /**
     * The organization that was current when this throwable escaped a `run()`.
     *
     * Only an answer for exceptions that came out of a `run()`. An HTTP request
     * does not need one: SetTenantContext's `finally` looks like it erases the
     * tenant before anything can report the exception, and it does not —
     * Illuminate\Routing\Pipeline::carry() wraps every pipe in its own
     * try/catch and reports from the INNERMOST one, so report() happens while
     * the middleware is still inside its own `try`. A queue worker is the
     * opposite: Worker::process() catches after the job's stack has fully
     * unwound, which is after `run()` restored.
     *
     * Follows `getPrevious()`, so wrapping the fault in another exception AFTER
     * it has left every `run()` does not lose the attribution — the wrapper is
     * unclaimed, the walk reaches the original, and the original remembers.
     *
     * It is not unconditional, and the condition is where the wrapping happens.
     * Wrap INSIDE an outer `run()` — catch an inner run's failure in the outer
     * callback and rethrow it in a new exception — and that outer run claims the
     * wrapper on its way past. The walk below then stops at the first claim it
     * finds, which is the outer tenant, and the inner one it would have reached
     * next is never consulted. So the row is filed where the wrapping happened,
     * not where the fault did. No such catch-and-wrap exists in the codebase
     * today; TenantIsolationTest pins the behaviour so it is a known edge rather
     * than a surprise.
     */
    public function organizationWhenThrown(Throwable $e): ?Organization
    {
        for ($throwable = $e; $throwable !== null; $throwable = $throwable->getPrevious()) {
            if (isset($this->blame[$throwable])) {
                return $this->blame[$throwable];
            }
        }

        return null;
    }

    /**
     * Run a callback with no tenant at all — for genuinely global work such as
     * platform tables or a staff console command. Named explicitly so it shows up
     * in review; nothing should reach for it casually.
     */
    public function runWithoutTenant(callable $callback): mixed
    {
        $previousOrg = $this->organization;
        $previousPlatform = $this->platformScope;

        $this->enterPlatformScope();

        try {
            return $callback();
        } finally {
            $this->organization = $previousOrg;
            $this->platformScope = $previousPlatform;
        }
    }
}
