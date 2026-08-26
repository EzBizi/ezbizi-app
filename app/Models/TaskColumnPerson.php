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

class TaskColumnPerson extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['task_id', 'column_definition_id', 'user_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('People-column assignments remain with task history; the referenced account is anonymised at its source.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Which user was selected in a tenant-defined People column on a task.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('user_id', PersonalDataEntry::ACTIVITY, 'The person selected as one value of this task custom People column.', Erasure::keep('The account is anonymised at its source while the historical task assignment remains meaningful.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where('user_id', $subject->getKey()) : null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TaskColumnDefinition::class, 'column_definition_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
