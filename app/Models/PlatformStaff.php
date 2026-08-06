<?php

namespace App\Models;

use App\Models\Concerns\HasTotp;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An EzBizi staff member (plan §4.2).
 *
 * Deliberately NOT App\Models\User and deliberately NOT tenant-scoped. Staff
 * are a separate identity domain that works across every organization, which is
 * exactly why they must not live in a table the tenant scope applies to.
 */
class PlatformStaff extends Authenticatable
{
    use HasFactory;
    use HasUuids;
    use HasApiTokens;
    use HasTotp;
    use SoftDeletes;

    protected $table = 'platform_staff';

    public const TIER_LEAD = 'lead_developer';
    public const TIER_DEV  = 'developer';

    protected $fillable = ['name', 'email', 'password', 'tier', 'is_active'];

    protected $hidden = [
        'password', 'totp_secret', 'pending_totp_secret', 'recovery_codes', 'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            // Read back from MySQL as a string otherwise, and it is compared
            // numerically against the next time-slice.
            'last_totp_timestamp' => 'integer',
            'totp_confirmed_at' => 'datetime',
            'last_login_at'     => 'datetime',
            // Encrypted at rest: a database leak that exposes TOTP secrets makes
            // the second factor worthless, and recovery codes are a full bypass.
            'totp_secret'         => 'encrypted',
            'pending_totp_secret' => 'encrypted',
            'recovery_codes'      => 'encrypted:array',
        ];
    }

    public function impersonations(): HasMany
    {
        return $this->hasMany(StaffImpersonation::class, 'staff_id');
    }

    // ── tiers ────────────────────────────────────────────────────

    public function isLeadDeveloper(): bool
    {
        return $this->tier === self::TIER_LEAD;
    }

    /**
     * May this staff member enter a tenant without the client approving first?
     *
     * Only the Lead Developer, and it is still recorded — the impersonation row
     * simply has no approver. "Unapproved" and "unrecorded" are different
     * things, and only the first is permitted.
     */
    public function mayEnterWithoutApproval(): bool
    {
        return $this->isLeadDeveloper();
    }

    /**
     * May this staff member create, disable or re-enrol other staff?
     *
     * Lead Developer only. A Developer who could mint another Developer could
     * mint one for anybody, and the tier ceiling would mean nothing.
     */
    public function mayManageStaff(): bool
    {
        return $this->isLeadDeveloper();
    }

    /**
     * Whether ordinary business auditing is suppressed for this account.
     *
     * The owner's decision (§4.2): the Lead Developer's development and testing
     * activity is not written to the business audit trail, so it doesn't drown
     * the log. Errors and crashes are still recorded.
     *
     * The cost, stated here rather than left in someone's memory: the audit log
     * cannot prove every access or change made by this account. Impersonation
     * rows are the exception — those are always written, whatever the tier,
     * because they evidence staff access to client data.
     */
    public function suppressesBusinessAudit(): bool
    {
        return $this->isLeadDeveloper();
    }

    // ── TOTP ─────────────────────────────────────────────────────

    /**
     * TOTP is mandatory for staff, so an account without it confirmed cannot be
     * used for anything except completing enrolment. Enforced in the guard, not
     * merely encouraged in a policy document.
     */
    public function canAuthenticate(): bool
    {
        return $this->is_active && $this->hasTotp();
    }

}
