<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use RuntimeException;

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
        } finally {
            $this->organization = $previousOrg;
            $this->platformScope = $previousPlatform;
        }
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
