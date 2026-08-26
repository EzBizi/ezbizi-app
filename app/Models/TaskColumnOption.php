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
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskColumnOption extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'column_definition_id', 'name', 'color', 'archived_at'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Label options remain with historical task labels so stored custom values continue to have meaning.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Tenant-written labels offered as values for a custom task column.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('name', PersonalDataEntry::CONTENT, 'The custom label text displayed on tasks and available for filtering.', Erasure::keep('Historical tasks continue to reference it and free text cannot be matched reliably to one erasure subject.'))];
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

    public function definition(): BelongsTo
    {
        return $this->belongsTo(TaskColumnDefinition::class, 'column_definition_id');
    }

    public function labels(): HasMany
    {
        return $this->hasMany(TaskColumnLabel::class, 'column_option_id');
    }
}
