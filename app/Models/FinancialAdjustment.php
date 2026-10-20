<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A refund, dispute, returned check or repair-split move recorded in EzBizi. */
#[Fillable(['company_id', 'franchise_id', 'kind', 'date', 'technician', 'from_technician', 'team', 'cents', 'job_number', 'reason', 'created_by_user_id'])]
class FinancialAdjustment extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const KINDS = ['refund', 'dispute', 'check_return', 'repair_move'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'cents' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(84, 'date',
            'A business money record (refund, dispute, returned check, or a repair cost split), kept seven years as tax records are.'
        );
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }
}
