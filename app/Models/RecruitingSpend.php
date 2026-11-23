<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * What recruiting cost in a month, for one position and source (Shoval,
 * 2026-11-20: cost per hire). Entered by HR; nobody's personal data.
 */
class RecruitingSpend extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'recruiting_spend';

    protected $fillable = [
        'organization_id', 'franchise_id', 'month', 'position', 'source', 'amount', 'note',
    ];

    protected function casts(): array
    {
        return ['month' => 'date', 'amount' => 'decimal:2'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Monthly spend figures, one row per month, position and source; kept as the '
            .'organization\'s accounts are, and deleted with the organization.'
        );
    }
}
