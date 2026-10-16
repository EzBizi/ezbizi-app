<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Financials (the Airtable replacement) — see the create_financials_tables migration. */
#[Fillable(['table_id', 'name', 'source_name', 'type', 'options', 'is_primary', 'position'])]
class FinancialField extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $table = 'financial_fields';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A spreadsheet the organization keeps for itself. It stays until somebody deletes it '
            .'or the organization is removed.'
        );
    }

    protected function casts(): array
    {
        return ['options' => 'array', 'is_primary' => 'boolean'];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(FinancialTable::class, 'table_id');
    }
}
