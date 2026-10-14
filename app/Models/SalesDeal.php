<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A salesperson's follow-up on one quoted job (Sales tab). One row per job,
 * created the first time somebody sets a stage, urgency, date or note.
 */
class SalesDeal extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STAGES = ['follow_up', 'call_back', 'sold', 'ghosted', 'disqualified'];

    public const URGENCIES = ['asap', 'next_week', 'next_month', 'later'];

    protected $fillable = ['job_id', 'stage', 'urgency', 'next_follow_up', 'notes', 'sold_at', 'updated_by_user_id'];

    protected $casts = ['next_follow_up' => 'date:Y-m-d', 'sold_at' => 'datetime'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('Kept as long as the job it follows up on, and removed with it.');
    }
}
