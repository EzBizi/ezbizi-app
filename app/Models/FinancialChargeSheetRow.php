<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One branch's charges for the sheet's period: column id => cents. */
#[Fillable(['sheet_id', 'company_id', 'franchise_id', 'label', 'jobs', 'cells', 'invoice_id', 'position'])]
class FinancialChargeSheetRow extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['cells' => 'array', 'jobs' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Part of its charge sheet; goes when the sheet goes.');
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(FinancialChargeSheet::class, 'sheet_id');
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function totalCents(): int
    {
        return (int) array_sum(array_map('intval', (array) $this->cells));
    }
}
