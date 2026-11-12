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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One task on a Task Board. company_id / franchise_id are copied from the
 * board so EventScope::capture and the overdue index read them directly.
 * Written only through App\Support\Tasks\TaskWriter.
 */
class Task extends Model implements HoldsPersonalData
{
    use BelongsToTenant;
    use HasUuids;
    use SoftDeletes;

    public const STATUS_NOT_STARTED = 'not_started';

    public const STATUS_WORKING_ON_IT = 'working_on_it';

    public const STATUS_STUCK = 'stuck';

    public const STATUS_DONE = 'done';

    /** Wire value => label. */
    public const STATUSES = [
        self::STATUS_NOT_STARTED => 'Not started',
        self::STATUS_WORKING_ON_IT => 'Working on it',
        self::STATUS_STUCK => 'Stuck',
        self::STATUS_DONE => 'Done',
    ];

    /** What a task may be about: wire name => model. */
    public const ABOUT = [
        'job' => Job::class,
        'lead' => Lead::class,
        'customer' => Customer::class,
        'vehicle' => Vehicle::class,
    ];

    protected $fillable = [
        'organization_id', 'board_id', 'parent_task_id', 'group_id', 'position', 'company_id', 'franchise_id', 'title',
        'status', 'status_id', 'assignee_user_id', 'due_on', 'completed_at', 'about_type', 'about_id',
        'created_by_user_id', 'created_by_automation_id', 'priority', 'estimate_minutes', 'sensitive',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'overdue_announced_at' => 'datetime',
            // Kept in step with Monday (TasksImportMonday / MondaySync).
            'external_updated_at' => 'datetime',
            'external_synced_at' => 'datetime',
            'estimate_minutes' => 'integer',
            // Its content is sealed and shown only after an authenticator code (SensitiveTasks).
            'sensitive' => 'boolean',
        ];
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(TaskBoard::class, 'board_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(TaskGroup::class, 'group_id');
    }

    public function statusRow(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_task_id');
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(self::class, 'parent_task_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(TaskColumnValue::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    /** Every owner, primary first (position 0 = assignee_user_id). */
    public function owners(): HasMany
    {
        return $this->hasMany(TaskAssignee::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TaskUpdate::class)->latest();
    }

    /** The record this task is about, or null. */
    public function aboutRecord(): ?Model
    {
        $class = self::ABOUT[$this->about_type] ?? null;

        return $class && $this->about_id ? $class::query()->find($this->about_id) : null;
    }

    public static function personalDataPurpose(): string
    {
        return 'The tasks on a team\'s or a person\'s board: who owns each one, who added it, and when it is due.';
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept as the business\'s record of who was asked to do what; a person\'s link to it '
            .'is erased with them.'
        );
    }

    public static function personalDataHeld(): array
    {
        return [
            new PersonalDataEntry('assignee_user_id, created_by_user_id', PersonalDataEntry::IDENTITY,
                'Who owns the task and who added it.',
                Erasure::erase()),
            new PersonalDataEntry('title', PersonalDataEntry::CONTENT,
                'What the task asks for, in the words of whoever wrote it.',
                Erasure::keep('It describes the business\'s work, not the person; the link to the '
                    .'person is the column erased above.')),
        ];
    }

    public static function personalDataAbout(Model $subject): ?Builder
    {
        return $subject instanceof User
            ? static::query()->withTrashed()->where(fn (Builder $q) => $q
                ->where('assignee_user_id', $subject->getKey())
                ->orWhere('created_by_user_id', $subject->getKey()))
            : null;
    }
}
