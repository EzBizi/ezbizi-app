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

class TaskColumnValue extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['task_id', 'column_definition_id', 'text_value', 'number_value', 'boolean_value', 'date_value', 'url_value', 'link_label_value'];

    protected function casts(): array
    {
        return ['number_value' => 'decimal:6', 'boolean_value' => 'boolean', 'date_value' => 'date'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Typed custom values remain with current and archived tasks so saved work and historical filters stay complete.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Tenant-entered custom text, numbers, dates, links, and link labels attached to a task.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('text_value, number_value, boolean_value, date_value, url_value, link_label_value', PersonalDataEntry::CONTENT, 'Custom field values whose meaning and possible personal content are chosen by the tenant.', Erasure::keep('Their meaning is tenant-defined and cannot be matched reliably to one erasure subject without corrupting the task record.'), tenantDefined: true)];
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
}
