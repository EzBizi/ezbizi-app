<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Tasks\PriorityRank;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskPriorityOption extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'name', 'color', 'archived_at'];

    protected function casts(): array
    {
        return ['rank' => PriorityRank::class, 'position' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Priority labels remain with current and historical tasks so their urgency stays readable.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Tenant-written priority labels used to describe task urgency.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('name', PersonalDataEntry::CONTENT, 'The tenant-defined priority label, which is free text and may include personal details.', Erasure::keep('Historical tasks continue to reference this label and free text cannot be matched reliably to one person.'))];
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

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'priority_option_id');
    }
}
