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

class TaskCollaborator extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'task_id', 'user_id', 'added_by'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Current and historical task participation remains with the archived work record.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Records who participates in a task and who added them.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id, added_by', PersonalDataEntry::ACTIVITY, 'The collaborator and the person who added them.', Erasure::keep('Referenced accounts are anonymised at their source while participation history remains explainable.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where(fn (Builder $query) => $query->where('user_id', $subject->getKey())->orWhere('added_by', $subject->getKey())) : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'added_by');
    }
}
