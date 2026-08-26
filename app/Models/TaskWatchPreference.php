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

class TaskWatchPreference extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'task_id', 'user_id', 'preference'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The explicit Watch or Mute choice remains until the person changes it or privacy erasure removes it.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Stores one person explicit Watch or Mute choice for a task.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id, preference', PersonalDataEntry::ACTIVITY, 'Who chose whether to watch or mute this task.', Erasure::drop('The preference belongs only to that person and has no purpose after erasure.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
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
