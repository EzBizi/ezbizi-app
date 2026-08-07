<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A Company — or a Sub Company, which is the same thing with a parent (plan §3).
 *
 * There is no SubCompany class on purpose. The owner was explicit that a Sub
 * Company "works identically, a company with a parent". A second class would
 * mean duplicating every permission check, settings lookup and roll-up, and the
 * two would drift.
 *
 * Depth is fixed: a Sub Company cannot itself hold Sub Companies (§3 answer 1-1),
 * so a plain parent_company_id is enough — no nested sets, no recursive CTEs.
 */
class Company extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'parent_company_id', 'name', 'is_active', 'logo_path', 'logo_aspect',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    // ── relations ────────────────────────────────────────────────

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_company_id');
    }

    /** Sub Companies belonging to this company. */
    public function subCompanies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_company_id');
    }

    /** Franchises directly under this company — not those under its Sub Companies. */
    public function franchises(): HasMany
    {
        return $this->hasMany(Franchise::class);
    }

    // ── shape ────────────────────────────────────────────────────

    public function isSubCompany(): bool
    {
        return $this->parent_company_id !== null;
    }

    /**
     * Every franchise beneath this company, including those under its Sub Companies.
     *
     * This is what a company-level roll-up shows (§4): a user assigned here sees —
     * and may act on — everything below.
     */
    public function allFranchises(): Collection
    {
        $direct = $this->franchises()->get();

        foreach ($this->subCompanies()->get() as $sub) {
            $direct = $direct->merge($sub->franchises()->get());
        }

        return $direct;
    }

    /**
     * Company ids in this subtree, including itself.
     * Used by authorization to answer "may this user act here?" (§4).
     */
    public function subtreeCompanyIds(): array
    {
        return array_merge(
            [$this->getKey()],
            $this->subCompanies()->pluck('id')->all()
        );
    }
}
