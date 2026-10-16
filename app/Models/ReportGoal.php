<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One report goal (ReportGoals::KEYS) for the organization or one company. */
class ReportGoal extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'company', 'key', 'value'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('A setting: replaced when it changes, holding no person.');
    }
}
