<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One place's week of the Repair Team report: who approved it, and values typed in. */
#[Fillable(['franchise_id', 'week_start', 'overrides', 'approved_by_user_id', 'approved_at'])]
class FinancialRepairWeek extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['week_start' => 'date:Y-m-d', 'overrides' => 'array', 'approved_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'week_start',
            'A business money record (a repair team\'s weekly costs and its approval), kept seven years as tax records are.'
        );
    }
}
