<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One period's charges: a row per branch and a column per charge line. */
#[Fillable(['company_id', 'period_from', 'period_to', 'title', 'columns', 'status', 'created_by_user_id'])]
class FinancialChargeSheet extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'columns' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'period_to', 'The working behind invoices, kept as long as they are.');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(FinancialChargeSheetRow::class, 'sheet_id')->orderBy('position');
    }
}
