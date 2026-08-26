<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Tasks\BoardVisibility;
use Database\Factories\TaskBoardFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskBoard extends Model implements HoldsPersonalData
{
    /** @use HasFactory<TaskBoardFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = ['company_id', 'franchise_id', 'folder_id', 'name', 'description', 'visibility', 'created_by', 'updated_by', 'archived_at'];

    protected function casts(): array
    {
        return ['visibility' => BoardVisibility::class, 'structure_version' => 'integer', 'archived_at' => 'datetime'];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Boards and their archived history remain the organization record of planned and completed work; restrictive foreign keys remove no child independently.');
    }

    public static function personalDataPurpose(): string
    {
        return 'A task workspace, its tenant-written description, and the people who created or last changed it.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('name, description', PersonalDataEntry::CONTENT, 'The board title and description written by the tenant and able to contain names or other personal details.', Erasure::keep('Free text cannot be reliably attributed to one subject and remains business task structure.')),
            new PersonalDataEntry('created_by, updated_by', PersonalDataEntry::ACTIVITY, 'The users who created and most recently changed this board.', Erasure::keep('The referenced accounts are anonymised at their source; these identifiers preserve the board change trail.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where(fn (Builder $q) => $q->where('created_by', $subject->getKey())->orWhere('updated_by', $subject->getKey())) : null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(TaskFolder::class, 'folder_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function members(): HasMany
    {
        return $this->hasMany(TaskBoardMember::class, 'board_id');
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(TaskBoardPreference::class, 'board_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(TaskGroup::class, 'board_id');
    }

    public function coreColumnSettings(): HasMany
    {
        return $this->hasMany(TaskCoreColumnSetting::class, 'board_id');
    }

    public function statusOptions(): HasMany
    {
        return $this->hasMany(TaskStatusOption::class, 'board_id');
    }

    public function priorityOptions(): HasMany
    {
        return $this->hasMany(TaskPriorityOption::class, 'board_id');
    }

    public function columnDefinitions(): HasMany
    {
        return $this->hasMany(TaskColumnDefinition::class, 'board_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'board_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(TaskView::class, 'board_id');
    }
}
