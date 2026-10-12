<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A Facebook ad campaign, with EzBizi's own service and state for it (Reports' Meta tab). */
class MetaAdCampaign extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'campaign_id', 'name', 'objective',
        'service_group_id', 'state', 'franchise_id',
    ];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            because: 'A campaign\'s name and the service and state staff gave it. Goes with the '
                .'Facebook Ads connection it was read through.',
        );
    }
}
