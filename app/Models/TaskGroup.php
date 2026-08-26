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

class TaskGroup extends Model implements HoldsPersonalData
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['board_id', 'name', 'color', 'archived_at'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'order_version' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Groups, including archived groups, remain task-board structure and are retained until the organization removes its board history.');
    }

    public static function personalDataPurpose(): string
    {
        return 'Tenant-written group names used to organize task rows on a board.';
    }

    public static function personalDataHeld(): array
    {
        return [new PersonalDataEntry('name', PersonalDataEntry::CONTENT, 'The group label, which is free text and can include a person name or work detail.', Erasure::keep('It remains task-board structure and cannot be reliably matched to one erasure subject.'))];
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
        return $this->hasMany(Task::class, 'group_id');
    }
}
