<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Tasks\ColumnType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskColumnDefinition extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'name', 'number_suffix', 'archived_at'];

    protected function casts(): array
    {
        return ['type' => ColumnType::class, 'position' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Column definitions remain with current and historical typed task values so those values stay interpretable.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Tenant-written names and suffixes describing custom fields on task boards.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('name, number_suffix', PersonalDataEntry::CONTENT, 'The custom-column name and optional display suffix, both tenant-authored text.', Erasure::keep('They are shared schema for historical task values and cannot be attributed reliably to one erasure subject.'))];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(TaskColumnOption::class, 'column_definition_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(TaskColumnValue::class, 'column_definition_id');
    }

    public function people(): HasMany
    {
        return $this->hasMany(TaskColumnPerson::class, 'column_definition_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(TaskColumnLabel::class, 'column_definition_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(TaskColumnFile::class, 'column_definition_id');
    }
}
