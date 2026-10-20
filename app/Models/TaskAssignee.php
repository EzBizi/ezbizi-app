<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner of a task (Jose, 2026-10-20). Position 0 is the primary owner,
 * the same person as tasks.assignee_user_id. No free text: two ids.
 */
class TaskAssignee extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'task_id', 'user_id', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('An owner row is part of its task and goes with it, or with the person.');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
