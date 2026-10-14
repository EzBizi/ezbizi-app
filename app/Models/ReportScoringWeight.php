<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One organization's Scoring weights; see ScoringWeights. */
class ReportScoringWeight extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'weights'];

    protected function casts(): array
    {
        return ['weights' => 'array'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A setting: one row per organization, replaced when it changes, holding no person.'
        );
    }
}
