<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One date and area of an outside lead source's results (ACE). Counts once approved. */
#[Fillable(['company_id', 'franchise_id', 'source', 'date', 'area', 'calls', 'jobs', 'cost_cents', 'sales_cents', 'profit_cents', 'image_disk', 'image_path', 'image_sha256', 'channel', 'status', 'approved_by_user_id', 'approved_at', 'created_by_user_id'])]
class FinancialResult extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d', 'approved_at' => 'datetime',
            'calls' => 'integer', 'jobs' => 'integer',
            'cost_cents' => 'integer', 'sales_cents' => 'integer', 'profit_cents' => 'integer',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'date',
            'A business money record (a lead source\'s calls, jobs, cost and sales) with its photo, kept seven years as tax records are.'
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
