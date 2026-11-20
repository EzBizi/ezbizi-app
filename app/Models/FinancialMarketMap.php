<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Which branch (market) a QuickBooks Class or Location ("department") stands
 * for. Made by name on sync; a person's choice (mapped_by person|none) is
 * never overwritten by the name match.
 */
class FinancialMarketMap extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const DIMENSIONS = ['class', 'department'];

    protected $guarded = ['id', 'organization_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('Configuration: which branch a QuickBooks class or location means, kept as long as the organization. No personal data.');
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }
}
