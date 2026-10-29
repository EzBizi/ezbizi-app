<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One zip code inside one service area
 * (docs/superpowers/specs/2026-08-31-scheduling-availability-design.md §2).
 *
 * A zip is not personal data here. It says where a franchise works, not where
 * anybody lives — the customer's own zip lives on Customer.
 *
 * `source` says who decided this mapping. NULL is a person, and a person wins:
 * `scheduling:build-zip-map` rewrites only rows it derived
 * (`source = 'workiz_jobs'`) so a rebuild cannot eat a typed one.
 *
 * THIS IS A HOUSE RULE, NOT THIS MODULE'S PREFERENCE — `docs/DEVELOPMENT-RULES.md`,
 * "a recurring sweep never overwrites a field a person has touched". Four
 * sessions derived it separately on 2026-09-23 (Customers, Jobs, Scheduling,
 * Workiz) before anybody wrote it down, which is why it now lives above any one
 * module. Read it before simplifying this column away: the failure it prevents
 * is silent in one direction only — a reverted correction looks like the person
 * never made it.
 */
#[Fillable(['organization_id', 'company_id', 'franchise_id', 'service_area_id', 'zip', 'city', 'source'])]
class ServiceAreaZip extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Which zip codes a service area covers. Goes with the area, which goes '
            .'with the franchise.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    /** Ownership is per company (2026-10-28): the row carries its branch's company. */
    protected static function booted(): void
    {
        static::saving(function (self $z) {
            if ($z->isDirty('franchise_id') || $z->company_id === null) {
                $z->company_id = Franchise::query()->whereKey($z->franchise_id)->value('company_id');
            }
        });
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
