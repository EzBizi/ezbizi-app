<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "blocks" edge between two tasks on the same board (design §9). Display
 * only: it draws a line on the Timeline and a chip on task detail, refuses no
 * write, and blocks no completion. Removed by cascade when either task goes.
 */
class TaskDependency extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'blocker_task_id', 'blocked_task_id', 'created_by'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The edge is task-board structure and is retained until the organization removes its board history.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Which task blocks which other task on a board, and who drew the edge.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('created_by', PersonalDataEntry::ACTIVITY, 'The person who created the dependency edge.', Erasure::keep('The referenced account is anonymised at its source while the board structure and its history remain intact.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('created_by', $subject->getKey()) : null;
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'blocker_task_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'blocked_task_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
