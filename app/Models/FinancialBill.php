<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A place's bills for a period, read from a photo and checked to the cent. */
#[Fillable(['company_id', 'franchise_id', 'period_from', 'period_to', 'lines', 'total_cents', 'image_disk', 'image_path', 'image_sha256', 'source', 'created_by_user_id'])]
class FinancialBill extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['lines' => 'array', 'period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'total_cents' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'period_to',
            'A business expense record with its photo, kept seven years as tax records are.'
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }
}
