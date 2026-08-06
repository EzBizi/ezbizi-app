<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * A customer (plan §5.2).
 *
 * SHARED across the organization — readable everywhere, editable from any
 * franchise — because a customer served by two franchises is one person, and
 * duplicating them creates two half-histories of one relationship.
 *
 * Sharing is why the owning-franchise tag exists. Somebody has to be accountable
 * for the relationship and countable in a roll-up, and "whoever touched them
 * last" is not an answer.
 */
#[Fillable([
    'owning_franchise_id', 'number', 'name', 'email', 'phone',
    'address_line1', 'address_line2', 'city', 'state', 'postal_code',
    'notes', 'is_active',
])]
class Customer extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    // ── relations ────────────────────────────────────────────────

    /** The ONE franchise accountable for this relationship (§5.2). */
    public function owningFranchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class, 'owning_franchise_id');
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    // ── the "served by" list (§5.2) ──────────────────────────────

    /**
     * Every franchise that has actually done work for this customer.
     *
     * DERIVED, never stored. A hand-maintained list goes stale the first time
     * somebody forgets to update it, and a stale accountability tag is worse than
     * no tag at all — it asserts something false rather than saying nothing.
     *
     * Includes the owner even with no jobs yet, because "accountable but has not
     * worked for them" is still a relationship somebody is answering for.
     *
     * @return Collection<int, string> franchise ids
     */
    public function servedByFranchiseIds(): Collection
    {
        return $this->jobs()
            ->distinct()
            ->pluck('franchise_id')
            ->push($this->owning_franchise_id)
            ->unique()
            ->values();
    }

    /**
     * Has a franchise other than the owner worked for this customer?
     *
     * What the UI needs in order to warn before somebody edits another
     * franchise's customer without noticing (§5.2 — the tag has to be visible
     * BEFORE they act, not after).
     */
    public function isSharedInPractice(): bool
    {
        return $this->servedByFranchiseIds()->count() > 1;
    }
}
