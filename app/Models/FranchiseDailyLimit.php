<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The most jobs a franchise takes on a weekday across all its areas. No row
 * is no limit. See the migration `create_franchise_daily_limits`, and
 * Openings for how it stops a day being offered.
 */
#[Fillable(['organization_id', 'franchise_id', 'weekday', 'max_jobs'])]
class FranchiseDailyLimit extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How many jobs a franchise takes in a day. Configuration of the '
            .'business, not a record of a day, so it does not accumulate.'
        );
    }

    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'max_jobs' => 'integer',
        ];
    }
}
