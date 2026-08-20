<?php

namespace App\Services\Staff;

use App\Models\Organization;
use App\Models\PlatformStaff;
use App\Models\StaffImpersonation;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use InvalidArgumentException;

/**
 * Staff entering a customer's data (plan §4.2).
 *
 * The rule the plan settles on: staff get metadata, logs and settings freely;
 * reaching actual customer records requires an impersonation session with a
 * stated reason and an expiry.
 *
 * The staff member REMAINS THE ACTOR throughout. This is not "become the
 * customer" — silently adopting a tenant user's identity destroys the trail of
 * who actually did what, which is the one thing the session exists to preserve.
 */
class ImpersonationService
{
    /** Longest a single session may run. */
    private const MAX_MINUTES = 120;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Open a session.
     *
     * @param  int  $minutes  capped at MAX_MINUTES — a session that never
     *                        expires is standing access wearing a timer.
     */
    public function begin(
        PlatformStaff $staff,
        Organization $organization,
        string $reason,
        int $minutes = 30,
        bool $clientApproved = false,
        ?string $approvedByUserId = null,
    ): StaffImpersonation {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'An impersonation requires a stated reason. Without one it is '
                .'indistinguishable from unexplained access when a client asks.'
            );
        }

        if (! $staff->mayEnterWithoutApproval()) {
            // Client approval used to be a boolean on the request. A Developer
            // posted `client_approved: true` and walked in. Approval has to be
            // a grant the client records, which does not exist yet, so this
            // tier cannot open a tenant from here.
            throw new InvalidArgumentException(
                'This staff tier cannot open a tenant from here. Client approval '
                .'has to be a grant the client records, not a box the caller ticks.'
            );
        }

        $minutes = min(max($minutes, 1), self::MAX_MINUTES);

        $session = StaffImpersonation::create([
            'staff_id'            => $staff->getKey(),
            'organization_id'     => $organization->getKey(),
            'reason'              => trim($reason),
            'started_at'          => now(),
            'expires_at'          => now()->addMinutes($minutes),
            'approved_by_user_id' => $approvedByUserId,
            'approved_at'         => $clientApproved ? now() : null,
        ]);

        // ALWAYS audited, whatever the tier. The Lead Developer's ordinary
        // activity is exempt from the business trail (§4.2) — looking at a
        // customer's records is not ordinary activity, and the exemption does
        // not reach here.
        $this->audit->logStaff(
            action: 'staff.impersonation_started',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            target: $organization,
            metadata: [
                'reason'          => $session->reason,
                'expires_at'      => $session->expires_at->toIso8601String(),
                'client_approved' => $session->wasApproved(),
                'tier'            => $staff->tier,
            ],
            // Staff run in platform scope, so nothing would otherwise stamp the
            // organization — and "show me everything that happened to this
            // client" is precisely the query this row has to answer.
            organizationId: $organization->getKey(),
        );

        return $session;
    }

    /**
     * Run a callback inside the tenant, if the session is still valid.
     *
     * Expiry is re-checked here rather than only at creation. A session left
     * open in a browser tab has to stop working by itself, or "time-limited"
     * describes the paperwork rather than the access.
     */
    public function enter(StaffImpersonation $session, callable $callback): mixed
    {
        if (! $session->isActive()) {
            throw new InvalidArgumentException(
                'This impersonation session has ended or expired. Start a new one.'
            );
        }

        return $this->tenant->run($session->organization, $callback);
    }

    public function end(StaffImpersonation $session, PlatformStaff $staff): void
    {
        // Already closed — say nothing. A second "ended" row in an append-only
        // log reads as a second session, and the log cannot be corrected.
        if ($session->ended_at !== null) {
            return;
        }

        $session->end();

        $this->audit->logStaff(
            action: 'staff.impersonation_ended',
            staffId: $staff->getKey(),
            staffLabel: "{$staff->name} <{$staff->email}>",
            target: $session->organization,
            metadata: [
                'duration_seconds' => $session->started_at->diffInSeconds($session->ended_at),
            ],
            organizationId: $session->organization_id,
        );
    }

    /**
     * Close sessions that ran past their expiry.
     *
     * Scheduled, so the record shows an end time rather than trailing off. An
     * audit row with no end is ambiguous between "still open" and "nobody
     * closed it", and those are very different answers to give a client.
     */
    public function expireStale(): int
    {
        $stale = StaffImpersonation::whereNull('ended_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($stale as $session) {
            $session->end();

            $this->audit->logStaff(
                action: 'staff.impersonation_expired',
                staffId: $session->staff_id,
                // Same "Name <email>" shape as every other actor label, so the
                // log can be read and grouped without special-casing this row.
                staffLabel: $session->staff
                    ? "{$session->staff->name} <{$session->staff->email}>"
                    : 'unknown staff',
                target: $session->organization,
                metadata: ['expired_at' => $session->expires_at->toIso8601String()],
                organizationId: $session->organization_id,
            );
        }

        return $stale->count();
    }
}
