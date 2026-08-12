<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Where a user may act (plan §4).
 *
 * A user may hold MANY assignments — that was the requirement cPanel couldn't
 * meet: "give this employee access to these three brands, not the other ten".
 * Each row targets exactly ONE of organization / company / franchise, enforced
 * by a database CHECK constraint (assignments_exactly_one_scope), not just here.
 *
 * Assignment does not cascade — being on Serenisima doesn't make you a member of
 * each franchise. But AUTHORITY does: a user assigned at company level may act on
 * everything beneath it. Assignment defines where you stand; the subtree below is
 * what you can reach.
 */
class Assignment extends Model implements StatesRetention
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

    /**
     * Kept, because nothing here goes stale with age.
     *
     * The reason this replaces said the row is "deleted when the access is taken
     * away, so there is no accumulation to sweep". Nothing takes it away.
     * `Assignment::create` in InvitationAcceptanceController and in
     * ProvisionOrganization are the only writes in the application and there is
     * no delete anywhere — no revoke endpoint, no service, nothing. The foreign
     * keys do cascade, but every parent (the user, the organization, the company,
     * the franchise) is deactivated or soft-deleted rather than removed, so in
     * practice the cascade does not fire either. A policy resting on a revoke
     * path describes a feature that does not exist.
     *
     * The conclusion it was drawn for survives on its own: what bounds this table
     * is the PEOPLE an organization has, not what they do. A row appears when
     * somebody is given a place to stand and never when they use it, so it grows
     * with headcount and stops when hiring does — which is why there is nothing
     * for a nightly sweep to find here, and why an age-based window would be
     * deleting live access rather than clutter.
     *
     * `forever()` rather than `withParent()` deliberately: the parent is a user,
     * User states `forever()` too, and pointing at a row that never goes would
     * dress up the same answer as a bound this table does not have.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'One row for each place a person may act — the organization, a company or a '
            .'franchise — written when they are given that access (§4). It says where '
            .'somebody stands rather than keeping a history of what they were granted, so '
            .'it grows with the people an organization has and not with anything they do. '
            .'Somebody who leaves is deactivated rather than deleted, and the row saying '
            .'where they worked is kept with their account. Who reached what, and when, is '
            .'the audit log, which does expire.'
        );
    }

    protected $fillable = [
        'organization_id', 'user_id',
        'assigned_organization_id', 'assigned_company_id', 'assigned_franchise_id',
    ];

    public static function booted(): void
    {
        // Mirror the database CHECK in the application, so the failure is a clear
        // exception at the point of the mistake rather than a raw SQL error 3819.
        // The database remains the real guarantee — this is just a better message.
        static::saving(function (self $assignment) {
            $set = collect([
                $assignment->assigned_organization_id,
                $assignment->assigned_company_id,
                $assignment->assigned_franchise_id,
            ])->filter()->count();

            if ($set !== 1) {
                throw new InvalidArgumentException(
                    "An assignment must target exactly one scope; {$set} were set. "
                    .'Use assignTo* rather than setting the columns directly.'
                );
            }
        });
    }

    // ── relations ────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'assigned_organization_id');
    }

    public function assignedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'assigned_company_id');
    }

    public function assignedFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'assigned_franchise_id');
    }

    // ── shape ────────────────────────────────────────────────────

    public function scopeType(): string
    {
        return match (true) {
            $this->assigned_franchise_id !== null    => self::SCOPE_FRANCHISE,
            $this->assigned_company_id !== null      => self::SCOPE_COMPANY,
            $this->assigned_organization_id !== null => self::SCOPE_ORGANIZATION,
            // Unreachable: both the CHECK constraint and the saving hook prevent it.
            default => throw new InvalidArgumentException('Assignment has no scope.'),
        };
    }

    public function scopeId(): string
    {
        return $this->assigned_franchise_id
            ?? $this->assigned_company_id
            ?? $this->assigned_organization_id;
    }
}
