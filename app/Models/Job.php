<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Settings\SettingsResolver;
use App\Support\Time\FranchiseWallTime;
use Database\Factories\JobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A job (plan §5.2).
 *
 * NEVER shared. A job happens at one place, on one date, with one crew — it
 * belongs to exactly one franchise and shows up in that franchise's roll-ups.
 * Doing a job for another franchise's customer is normal and adds you to their
 * "served by" list; it does not make the job co-owned, and it does not move the
 * customer.
 */
#[Fillable([
    'franchise_id', 'customer_id', 'number', 'title', 'description',
    'status', 'scheduled_for', 'duration_minutes', 'amount_minor', 'currency',
])]
class Job extends Model
{
    /** @use HasFactory<JobFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;
    /*
     * Photographs, invoices from a supplier, a signed worksheet.
     *
     * The first attachable record in the app, and the one a review named: work
     * gets disputed over the photograph of what was found on arrival, and until
     * now that photograph lived in somebody's phone. Soft deletes and files
     * interact — see HasAttachments, which keeps them through a soft delete and
     * takes them on a force delete.
     */
    use HasAttachments;

    protected function casts(): array
    {
        return [
            'scheduled_for'    => FranchiseWallTime::class,
            'duration_minutes' => 'integer',
            // Integer minor units (§7). Casting it keeps a string '1999' from a
            // JSON body out of arithmetic that would silently concatenate.
            'amount_minor'     => 'integer',
        ];
    }

    // ── relations ────────────────────────────────────────────────

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // ── status (§2.1 category 5) ─────────────────────────────────

    /**
     * The statuses this job's organization allows.
     *
     * Tenant configuration, not an enum in code: an organization wanting its own
     * word for "on hold" should not need a migration. The SET is data; the fact
     * that a job HAS a status is a platform invariant and stays here.
     */
    public static function allowedStatuses(Model $scope): array
    {
        $statuses = app(SettingsResolver::class)->get('jobs.statuses', $scope);

        return is_array($statuses) ? array_values($statuses) : [];
    }

    // ── money (§7) ───────────────────────────────────────────────

    /**
     * For display only. Never feed this back into a calculation — the integer is
     * the value, and a float that has been through division is not it.
     */
    public function amountForDisplay(): ?string
    {
        if ($this->amount_minor === null) {
            return null;
        }

        return number_format($this->amount_minor / 100, 2);
    }
}
