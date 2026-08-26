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

class TaskUpdateMention extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['board_id', 'task_id', 'update_id', 'user_id', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Mention identity remains with the update so its audience and notification history stay explainable.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Records which person was mentioned in one task update.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY, 'The person mentioned in the update.', Erasure::keep('The referenced account is anonymised at its source while the update audience remains part of history.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function taskUpdate(): BelongsTo
    {
        return $this->belongsTo(TaskUpdate::class, 'update_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
