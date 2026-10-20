<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One of Google's daily counts for one listing (2026-10-20). */
#[Fillable(['organization_id', 'business_location_id', 'date', 'metric', 'value'])]
class BusinessLocationMetric extends Model implements StatesRetention
{
    use BelongsToTenant;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['date' => 'date', 'value' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'Daily counts Google reports for a business listing - calls, clicks, directions, views. '
            .'No personal data; deleted with the listing.'
        );
    }
}
