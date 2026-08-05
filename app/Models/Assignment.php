<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
class Assignment extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

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
