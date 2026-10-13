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

/** A comment on a task. @mentions are plain text for now. */
class TaskUpdate extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = ['organization_id', 'task_id', 'user_id', 'body'];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function personalDataPurpose(): string
    {
        return 'Comments people leave on a task.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A comment hangs off its task and goes with it.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('user_id', PersonalDataEntry::IDENTITY,
                'Which person wrote this comment on the task.',
                Erasure::erase()),
            new PersonalDataEntry('body', PersonalDataEntry::CONTENT,
                'What the person wrote in the comment.',
                Erasure::erase('[removed]')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('user_id', $subject->getKey())
            : null;
    }
}
