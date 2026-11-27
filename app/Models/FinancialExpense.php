<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One expense line read from QuickBooks Online (QuickBooksSync). Keyed by
 * realm, transaction type, transaction id and line id; the market comes from
 * FinancialMarketMap at read time.
 */
class FinancialExpense extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $guarded = ['id', 'organization_id'];

    protected function casts(): array
    {
        return ['txn_date' => 'date:Y-m-d', 'amount_cents' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'txn_date',
            'A business expense record copied from the books, kept seven years as tax records are.'
        );
    }
}
