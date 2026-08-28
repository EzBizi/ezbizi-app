<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Privacy\Erasure;
use App\Support\Privacy\HoldsPersonalData;
use App\Support\Privacy\PersonalDataEntry;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Scoping\ViewScope;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Task extends Model implements CarriesAutomationScope, HoldsPersonalData
{
    /** @use HasFactory<TaskFactory> */
    use BelongsToTenant, HasAttachments, HasFactory, HasUuids;

    protected $fillable = [
        'title', 'description', 'description_plain', 'owner_user_id',
        'status_option_id', 'priority_option_id', 'start_on', 'due_on',
        'recurrence',
        'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by',
        'created_by', 'updated_by', 'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'start_on' => 'date', 'due_on' => 'date', 'due_revision' => 'integer',
            'recurrence' => 'array',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
            'position' => 'integer', 'version' => 'integer', 'detail_version' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever('Tasks and archived task history remain the organization record of planned, completed, and cancelled work until deliberate privacy removal.');
    }

    public static function personalDataPurpose(): string
    {
        return 'The task brief, schedule, accountable people, and completion or cancellation history used to coordinate work.';
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('title, description, description_plain', PersonalDataEntry::CONTENT, 'The tenant-written task title and formatted and plain-text descriptions, which can contain personal details.', Erasure::keep('They are the durable business brief and free text cannot be reliably attributed to one erasure subject.')),
            new PersonalDataEntry('owner_user_id, completed_by, cancelled_by, created_by, updated_by', PersonalDataEntry::ACTIVITY, 'The accountable owner and the people who created, changed, completed, or cancelled the task.', Erasure::keep('The referenced accounts are anonymised at their source while task accountability and history remain intact.')),
            new PersonalDataEntry('start_on, due_on, completed_at, cancelled_at', PersonalDataEntry::ACTIVITY, 'The planned and actual dates that describe when the assigned work was expected and resolved.', Erasure::keep('These dates are part of the work record and remain tied to already-anonymised account references.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User ? static::query()->where(fn (Builder $q) => $q
            ->where('owner_user_id', $subject->getKey())->orWhere('completed_by', $subject->getKey())
            ->orWhere('cancelled_by', $subject->getKey())->orWhere('created_by', $subject->getKey())
            ->orWhere('updated_by', $subject->getKey())) : null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $task): void {
            if ($task->parent_task_id === null) {
                return;
            }

            if ($task->exists && $task->getKey() === $task->parent_task_id) {
                throw new RuntimeException('A task cannot be its own parent.');
            }

            $parent = static::query()->select(['id', 'parent_task_id'])->find($task->parent_task_id);

            if ($parent !== null && $parent->parent_task_id !== null) {
                throw new RuntimeException('A subtask cannot itself be a parent.');
            }

            if ($task->exists && $task->isDirty('parent_task_id') && $task->subtasks()->exists()) {
                throw new RuntimeException('A task with subtasks cannot become a subtask.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function automationScope(): array
    {
        $board = $this->board()->first();
        if ($board?->franchise_id !== null) {
            return [ViewScope::FRANCHISE, $board->franchise_id];
        }
        if ($board?->company_id !== null) {
            return [ViewScope::COMPANY, $board->company_id];
        }

        return [null, null];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TaskGroup::class, 'group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function statusOption(): BelongsTo
    {
        return $this->belongsTo(TaskStatusOption::class, 'status_option_id');
    }

    public function priorityOption(): BelongsTo
    {
        return $this->belongsTo(TaskPriorityOption::class, 'priority_option_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function columnValues(): HasMany
    {
        return $this->hasMany(TaskColumnValue::class);
    }

    public function columnPeople(): HasMany
    {
        return $this->hasMany(TaskColumnPerson::class);
    }

    public function columnLabels(): HasMany
    {
        return $this->hasMany(TaskColumnLabel::class);
    }

    public function columnFiles(): HasMany
    {
        return $this->hasMany(TaskColumnFile::class);
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(TaskCollaborator::class);
    }

    public function watchPreferences(): HasMany
    {
        return $this->hasMany(TaskWatchPreference::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TaskUpdate::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class);
    }

    public function recordLinks(): HasMany
    {
        return $this->hasMany(TaskRecordLink::class);
    }
}
