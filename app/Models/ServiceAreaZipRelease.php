<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A ZIP a person removed from its area, so the derived sweep
 * (`scheduling:build-zip-map`) leaves it unassigned. Assigning it anywhere by
 * hand clears it.
 */
#[Fillable(['organization_id', 'company_id', 'zip', 'released_by'])]
class ServiceAreaZipRelease extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A person\'s decision that a zip has no area; dropping it would let the sweep reverse it.');
    }

    /** One company's decision; another company's map is its own (2026-10-28). */
    public static function release(iterable $zips, ?string $companyId): void
    {
        foreach ($zips as $zip) {
            static::firstOrCreate(['company_id' => $companyId, 'zip' => (string) $zip], ['released_by' => auth()->id()]);
        }
    }

    /** @param iterable<string> $zips */
    public static function claim(iterable $zips, ?string $companyId): void
    {
        $zips = collect($zips)->map(fn ($z) => (string) $z)->all();
        if ($zips !== []) {
            static::where('company_id', $companyId)->whereIn('zip', $zips)->delete();
        }
    }
}
