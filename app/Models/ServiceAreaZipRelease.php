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
#[Fillable(['organization_id', 'zip', 'released_by'])]
class ServiceAreaZipRelease extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A person\'s decision that a zip has no area; dropping it would let the sweep reverse it.');
    }

    /** @param iterable<string> $zips */
    public static function release(iterable $zips): void
    {
        foreach ($zips as $zip) {
            static::firstOrCreate(['zip' => (string) $zip], ['released_by' => auth()->id()]);
        }
    }

    /** @param iterable<string> $zips */
    public static function claim(iterable $zips): void
    {
        $zips = collect($zips)->map(fn ($z) => (string) $z)->all();
        if ($zips !== []) {
            static::whereIn('zip', $zips)->delete();
        }
    }
}
