<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Two tasks that are the same work: one row per pair, read both ways. */
class TaskLink extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'task_id', 'linked_task_id', 'created_by_user_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A link is part of the two tasks it joins and goes with either.');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function linkedTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'linked_task_id');
    }
}
