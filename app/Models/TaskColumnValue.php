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

/** One task's value in one custom column. Written only through TaskWriter. */
class TaskColumnValue extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;

    protected $fillable = [
        'organization_id', 'task_id', 'column_id', 'value_text', 'value_number', 'value_date', 'value_user_id',
    ];

    protected function casts(): array
    {
        return ['value_date' => 'date', 'value_number' => 'float'];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(TaskColumn::class, 'column_id');
    }

    public static function personalDataPurpose(): string
    {
        return 'What people fill into the custom columns of a task: text, numbers, dates, and who a person column names.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A column value hangs off its task and goes with it.');
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('value_user_id', PersonalDataEntry::IDENTITY,
                'Which person a person column on the task names.',
                Erasure::erase()),
            new PersonalDataEntry('value_text', PersonalDataEntry::CONTENT,
                'What somebody typed into a text column on the task.',
                Erasure::keep('It describes the business\'s work, not the person, like the task\'s title; '
                    .'the link to the person is the column erased above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->where('value_user_id', $subject->getKey())
                // A People column lists them in value_text (["id", ...]).
                ->orWhere('value_text', 'like', '%"'.$subject->getKey().'"%')
            : null;
    }
}
