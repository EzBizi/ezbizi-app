<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One ad set's spend and results on one day. `region` '' is the total; any
 * other value is where the people who saw the ad were (Meta's breakdown).
 */
class MetaAdInsight extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'date', 'campaign_id', 'adset_id', 'adset_name',
        'region', 'spend', 'impressions', 'clicks', 'leads', 'forms', 'currency',
    ];

    protected function casts(): array
    {
        return ['date' => 'date', 'spend' => 'decimal:2'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            because: 'Aggregate ad spend and results, no person in them. Goes with the Facebook '
                .'Ads connection; Meta keeps the same figures for 37 months.',
        );
    }
}
