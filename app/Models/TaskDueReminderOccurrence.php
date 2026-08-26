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

class TaskDueReminderOccurrence extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids, WrittenOnce;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'recipient_user_id', 'due_revision', 'lead_hours', 'due_on', 'created_at'];

    protected function casts(): array
    {
        return ['due_revision' => 'integer', 'lead_hours' => 'integer', 'due_on' => 'date', 'created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Reminder occurrence identity remains as durable proof that one person was considered for one due revision.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Records which person received a due-reminder occurrence for a task due revision.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('recipient_user_id, due_on, lead_hours', PersonalDataEntry::ACTIVITY, 'The reminder recipient and the due-date snapshot and lead time used.', Erasure::keep('The referenced account is anonymised at its source while delivery idempotency remains.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('recipient_user_id', $subject->getKey()) : null;
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('Task due-reminder occurrences are immutable.'));
        static::deleting(fn () => throw new RuntimeException('Task due-reminder occurrences are immutable.'));
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
