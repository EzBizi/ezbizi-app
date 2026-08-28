<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\WrittenOnce;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The idempotency marker for one occurrence date of one recurring task series
 * (design §11), the same shape as TaskOverdueOccurrence. Unique
 * (task_id, occurrence_date) is what stops a retried or overlapping worker run
 * creating duplicates. created_task_id is nullable on purpose: the row is
 * written in the same transaction as the created task, but a series that ends
 * (until/count exhausted) records a final occurrence with no task.
 */
class TaskRecurrenceOccurrence extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids, WrittenOnce;

    public const UPDATED_AT = null;

    protected $fillable = ['task_id', 'occurrence_date', 'created_task_id', 'created_at'];

    protected function casts(): array
    {
        return ['occurrence_date' => 'date', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The occurrence key remains as durable idempotency evidence for the task history.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Which occurrence date of a recurring task series already produced a task, and which task that was.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('task_id, occurrence_date, created_task_id', PersonalDataEntry::ACTIVITY, 'The series task, the occurrence date expanded, and the task it produced, if any — each can be traced back to the people who created or are assigned that task.', Erasure::keep('The referenced tasks and their accounts are anonymised at their source while the occurrence identity remains as idempotency evidence.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Task recurrence occurrences are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Task recurrence occurrences are immutable.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function createdTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'created_task_id');
    }
}
