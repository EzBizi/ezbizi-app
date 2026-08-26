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

class TaskColumnFile extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['task_id', 'column_definition_id', 'attachment_id'];

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('The committed file relationship remains with the task; Attachment separately inventories filename, metadata, and bytes.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Which committed attachment was selected for a custom File column on a task.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('attachment_id', PersonalDataEntry::CONTENT, 'The identifier linking this task column to attachment metadata and its governed file contents.', Erasure::keep('Attachment owns file erasure and metadata; this restrictive reference remains until that governed relationship is deliberately removed.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TaskColumnDefinition::class, 'column_definition_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
