<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A charge line the organization bills its branches for (Settings › Invoicing). */
#[Fillable(['name', 'source_column', 'position', 'is_active'])]
class FinancialChargeColumn extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Configuration; stays until somebody removes it.');
    }
}
