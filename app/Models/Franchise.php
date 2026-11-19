<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The operating unit. Business data lives here (plan §5) — customers, jobs,
 * invoices, inventory all hang off a franchise.
 *
 * "Franchise" is the default label; organizations may rename it to Location,
 * Branch or Region (§8), so never hardcode the word in user-facing output —
 * read Organization::$franchise_label.
 */
class Franchise extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'company_id', 'name', 'address',
        'timezone', 'currency', 'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * Switched off: its own switch is off, or its company is switched off
     * (Company::scopeSwitchedOff, which carries the rule).
     */
    public function scopeSwitchedOff(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where($q->qualifyColumn('is_active'), false)
            ->orWhereHas('company', fn (Builder $c) => $c->withoutGlobalScope(TenantScope::class)->switchedOff()));
    }

    public function scopeSwitchedOn(Builder $query): void
    {
        $query->whereNot(fn (Builder $q) => $q->switchedOff());
    }

    public static function isSwitchedOff(?string $id): bool
    {
        return $id !== null && static::query()->whereKey($id)->switchedOff()->exists();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The company this franchise reports up through, and that company's parent
     * if it is a Sub Company. This is the settings-resolution chain (§6):
     *
     *   Franchise → owning Company/Sub Company → parent Company → Organization
     *
     * The parent step is the one my first draft of the plan omitted, which would
     * have made a franchise under a Sub Company inherit from the grandparent and
     * silently ignore every setting the Sub Company defined.
     */
    public function settingsChain(): array
    {
        $chain = [$this];

        if ($company = $this->company) {
            $chain[] = $company;

            if ($parent = $company->parent) {
                $chain[] = $parent;
            }
        }

        $chain[] = $this->organization;

        return array_filter($chain);
    }
}
