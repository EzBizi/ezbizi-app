<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnOption;
use App\Models\TaskCoreColumnSetting;
use App\Models\TaskFolder;
use App\Models\TaskGroup;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\TaskView;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskBoardWriter
{
    private const CORE_COLUMNS = [
        'task' => 'Task',
        'owner' => 'Owner',
        'status' => 'Status',
        'priority' => 'Priority',
        'start_on' => 'Start date',
        'due_on' => 'Due date',
    ];

    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly AuditLogger $audit,
    ) {}

    public function create(User $actor, ViewScope $scope, array $attributes): TaskBoard
    {
        $this->rejectPlaceInput($attributes);
        $this->access->createDecision($actor, $scope)->authorize();
        $place = TaskPlace::fromView($scope);
        $folder = $this->folderAtPlace($attributes['folder_id'] ?? null, $place);

        return DB::transaction(function () use ($actor, $attributes, $place, $folder): TaskBoard {
            $board = $this->createRecord($actor, $attributes, $place, $folder, defaults: true);
            $this->audit->log('task_board.created', metadata: ['board_id' => $board->id], actor: $actor);

            return $board->fresh($this->configurationRelations());
        });
    }

    public function updateBoard(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $attributes): TaskBoard
    {
        $this->rejectPlaceInput($attributes);

        return $this->structure($actor, $board, $scope, $expected, 'task_board.updated', function (TaskBoard $locked) use ($attributes): TaskBoard {
            $folder = array_key_exists('folder_id', $attributes)
                ? $this->folderAtPlace($attributes['folder_id'], [
                    'organization_id' => $locked->organization_id,
                    'company_id' => $locked->company_id,
                    'franchise_id' => $locked->franchise_id,
                ])
                : null;

            $locked->fill(array_filter([
                'name' => $attributes['name'] ?? null,
                'description' => array_key_exists('description', $attributes) ? $attributes['description'] : null,
                'visibility' => $attributes['visibility'] ?? null,
                'folder_id' => array_key_exists('folder_id', $attributes) ? $folder?->id : null,
            ], fn ($value, $key) => array_key_exists($key, $attributes), ARRAY_FILTER_USE_BOTH));

            return $locked;
        });
    }

    public function setArchived(User $actor, TaskBoard $board, ViewScope $scope, int $expected, bool $archived): TaskBoard
    {
        return $this->structure(
            $actor,
            $board,
            $scope,
            $expected,
            $archived ? 'task_board.archived' : 'task_board.restored',
            function (TaskBoard $locked) use ($archived): TaskBoard {
                if (! $archived && $this->positionTaken($locked)) {
                    $locked->forceFill(['position' => $this->nextBoardPosition(
                        $locked->company_id,
                        $locked->franchise_id,
                        $locked->folder_id,
                    )]);
                }
                $locked->archived_at = $archived ? now() : null;

                return $locked;
            },
        );
    }

    public function duplicate(
        User $actor,
        TaskBoard $source,
        ViewScope $scope,
        int $expected,
        string $name,
        bool $includeTasks = false,
    ): TaskBoard {
        if ($includeTasks) {
            throw ValidationException::withMessages([
                'include_tasks' => 'Task-inclusive duplication is unavailable until typed task values can be copied atomically.',
            ]);
        }

        $this->access->viewDecision($actor, $source, $scope)->authorize();
        $this->access->manageDecision($actor, $source, $scope)->authorize();
        $this->access->createDecision($actor, $scope)->authorize();

        return DB::transaction(function () use ($actor, $source, $scope, $expected, $name): TaskBoard {
            $locked = TaskBoard::query()->lockForUpdate()->findOrFail($source->id);
            if ((int) $locked->structure_version !== $expected) {
                throw new TaskStructureConflict((int) $locked->structure_version);
            }

            $locked->load($this->configurationRelations());
            $place = TaskPlace::fromView($scope);
            $copy = $this->createRecord($actor, [
                'name' => $name,
                'description' => $locked->description,
                'visibility' => $locked->visibility->value,
            ], $place, null, defaults: false);
            $idMap = [];

            foreach ($locked->groups as $row) {
                $new = $this->copyRow(TaskGroup::class, $copy, $row, ['name', 'color', 'position', 'archived_at']);
                $idMap[$row->id] = $new->id;
            }
            foreach ($locked->statusOptions as $row) {
                $new = $this->copyRow(TaskStatusOption::class, $copy, $row, ['name', 'color', 'category', 'position', 'archived_at']);
                $idMap[$row->id] = $new->id;
            }
            foreach ($locked->priorityOptions as $row) {
                $new = $this->copyRow(TaskPriorityOption::class, $copy, $row, ['name', 'color', 'rank', 'position', 'archived_at']);
                $idMap[$row->id] = $new->id;
            }
            foreach ($locked->coreColumnSettings as $row) {
                $this->copyRow(TaskCoreColumnSetting::class, $copy, $row, ['core_key', 'display_label']);
            }
            foreach ($locked->columnDefinitions as $row) {
                $definition = $this->copyRow(TaskColumnDefinition::class, $copy, $row, ['name', 'type', 'number_suffix', 'position', 'archived_at']);
                $idMap[$row->id] = $definition->id;
                foreach ($row->options as $option) {
                    $newOption = new TaskColumnOption;
                    $newOption->forceFill([
                        'board_id' => $copy->id,
                        'column_definition_id' => $definition->id,
                        ...$option->only(['name', 'color', 'position', 'archived_at']),
                    ])->save();
                    $idMap[$option->id] = $newOption->id;
                }
            }
            foreach ($locked->views->where('visibility', 'shared') as $view) {
                $newView = new TaskView;
                $newView->forceFill([
                    'board_id' => $copy->id,
                    'owner_user_id' => null,
                    'visibility' => 'shared',
                    'name' => $view->name,
                    'is_locked' => $view->is_locked,
                    'is_permanent_main' => $view->is_permanent_main,
                    'configuration' => $this->replaceIds($view->configuration, $idMap),
                    'position' => $view->position,
                ])->save();
            }

            $this->audit->log('task_board.duplicated', metadata: [
                'board_id' => $copy->id,
                'source_board_id' => $locked->id,
            ], actor: $actor);

            return $copy->fresh($this->configurationRelations());
        });
    }

    public function createGroup(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $attributes): TaskGroup
    {
        $attributes = $this->only($attributes, ['name', 'color']);

        return $this->structure($actor, $board, $scope, $expected, 'task_group.created', function (TaskBoard $locked) use ($attributes): TaskGroup {
            return $this->newRow(TaskGroup::class, $locked, [
                ...$attributes,
                'position' => $locked->groups()->active()->max('position') + 1,
            ]);
        });
    }

    public function updateGroup(User $actor, TaskBoard $board, TaskGroup $group, ViewScope $scope, int $expected, array $attributes): TaskGroup
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_group.updated', function (TaskBoard $locked) use ($group, $attributes): TaskGroup {
            $row = $this->owned(TaskGroup::class, $locked, $group);
            $row->fill($this->only($attributes, ['name', 'color']));

            return $row;
        });
    }

    public function setGroupArchived(User $actor, TaskBoard $board, TaskGroup $group, ViewScope $scope, int $expected, bool $archived): TaskGroup
    {
        return $this->structure($actor, $board, $scope, $expected, $archived ? 'task_group.archived' : 'task_group.restored', function (TaskBoard $locked) use ($group, $archived): TaskGroup {
            $row = $this->owned(TaskGroup::class, $locked, $group);
            if ($archived && Task::query()->active()->where('group_id', $row->id)->whereNull('parent_task_id')->exists()) {
                throw ValidationException::withMessages(['group' => 'Archive or move the active tasks in this group first.']);
            }
            if (! $archived && $locked->groups()->active()->where('position', $row->position)->exists()) {
                $row->forceFill(['position' => $locked->groups()->active()->max('position') + 1]);
            }
            $row->archived_at = $archived ? now() : null;

            return $row;
        });
    }

    public function reorderGroups(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $ids): TaskBoard
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_groups.reordered', function (TaskBoard $locked) use ($ids): TaskBoard {
            $this->reorder(TaskGroup::class, $locked, $ids);

            return $locked;
        });
    }

    public function createStatus(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $attributes): TaskStatusOption
    {
        $attributes = $this->only($attributes, ['name', 'color', 'category']);

        return $this->structure($actor, $board, $scope, $expected, 'task_status.created', fn (TaskBoard $locked) => $this->newRow(
            TaskStatusOption::class,
            $locked,
            [...$attributes, 'position' => $locked->statusOptions()->active()->max('position') + 1],
        ));
    }

    public function updateStatus(User $actor, TaskBoard $board, TaskStatusOption $status, ViewScope $scope, int $expected, array $attributes): TaskStatusOption
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_status.updated', function (TaskBoard $locked) use ($status, $attributes): TaskStatusOption {
            $row = $this->owned(TaskStatusOption::class, $locked, $status);
            if (isset($attributes['category']) && $attributes['category'] !== $row->category->value) {
                if ($row->tasks()->exists()) {
                    throw ValidationException::withMessages(['category' => 'A status already used by a task cannot change category.']);
                }
                $this->assertStatusCategories($locked, $row, $attributes['category']);
                $row->forceFill(['category' => $attributes['category']]);
            }
            $row->fill(collect($attributes)->only(['name', 'color'])->all());

            return $row;
        });
    }

    public function setStatusArchived(User $actor, TaskBoard $board, TaskStatusOption $status, ViewScope $scope, int $expected, bool $archived): TaskStatusOption
    {
        return $this->structure($actor, $board, $scope, $expected, $archived ? 'task_status.archived' : 'task_status.restored', function (TaskBoard $locked) use ($status, $archived): TaskStatusOption {
            $row = $this->owned(TaskStatusOption::class, $locked, $status);
            if ($archived) {
                $this->assertStatusCategories($locked, $row, null);
            } elseif ($locked->statusOptions()->active()->where('position', $row->position)->exists()) {
                $row->forceFill(['position' => $locked->statusOptions()->active()->max('position') + 1]);
            }
            $row->archived_at = $archived ? now() : null;

            return $row;
        });
    }

    public function reorderStatuses(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $ids): TaskBoard
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_statuses.reordered', function (TaskBoard $locked) use ($ids): TaskBoard {
            $this->reorder(TaskStatusOption::class, $locked, $ids);

            return $locked;
        });
    }

    public function createPriority(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $attributes): TaskPriorityOption
    {
        $attributes = $this->only($attributes, ['name', 'color', 'rank']);

        return $this->structure($actor, $board, $scope, $expected, 'task_priority.created', function (TaskBoard $locked) use ($attributes): TaskPriorityOption {
            if ($locked->priorityOptions()->active()->where('rank', $attributes['rank'])->exists()) {
                throw ValidationException::withMessages(['rank' => 'That priority rank already has an active option.']);
            }

            return $this->newRow(TaskPriorityOption::class, $locked, [
                ...$attributes,
                'position' => $locked->priorityOptions()->active()->max('position') + 1,
            ]);
        });
    }

    public function updatePriority(User $actor, TaskBoard $board, TaskPriorityOption $priority, ViewScope $scope, int $expected, array $attributes): TaskPriorityOption
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_priority.updated', function (TaskBoard $locked) use ($priority, $attributes): TaskPriorityOption {
            $row = $this->owned(TaskPriorityOption::class, $locked, $priority);
            if (isset($attributes['rank']) && $attributes['rank'] !== $row->rank->value) {
                if ($row->tasks()->exists()) {
                    throw ValidationException::withMessages(['rank' => 'A priority already used by a task cannot change rank.']);
                }
                if ($locked->priorityOptions()->active()->whereKeyNot($row->id)->where('rank', $attributes['rank'])->exists()) {
                    throw ValidationException::withMessages(['rank' => 'That priority rank already has an active option.']);
                }
                $row->forceFill(['rank' => $attributes['rank']]);
            }
            $row->fill(collect($attributes)->only(['name', 'color'])->all());

            return $row;
        });
    }

    public function setPriorityArchived(User $actor, TaskBoard $board, TaskPriorityOption $priority, ViewScope $scope, int $expected, bool $archived): TaskPriorityOption
    {
        return $this->structure($actor, $board, $scope, $expected, $archived ? 'task_priority.archived' : 'task_priority.restored', function (TaskBoard $locked) use ($priority, $archived): TaskPriorityOption {
            $row = $this->owned(TaskPriorityOption::class, $locked, $priority);
            if (! $archived && $locked->priorityOptions()->active()->where('rank', $row->rank->value)->exists()) {
                throw ValidationException::withMessages(['rank' => 'That priority rank already has an active option.']);
            }
            if (! $archived && $locked->priorityOptions()->active()->where('position', $row->position)->exists()) {
                $row->forceFill(['position' => $locked->priorityOptions()->active()->max('position') + 1]);
            }
            $row->archived_at = $archived ? now() : null;

            return $row;
        });
    }

    public function reorderPriorities(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $ids): TaskBoard
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_priorities.reordered', function (TaskBoard $locked) use ($ids): TaskBoard {
            $this->reorder(TaskPriorityOption::class, $locked, $ids);

            return $locked;
        });
    }

    public function createColumn(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $attributes): TaskColumnDefinition
    {
        $attributes = $this->only($attributes, ['name', 'type', 'number_suffix']);

        return $this->structure($actor, $board, $scope, $expected, 'task_column.created', fn (TaskBoard $locked) => $this->newRow(
            TaskColumnDefinition::class,
            $locked,
            [...$attributes, 'number_suffix' => $attributes['type'] === ColumnType::Number->value ? ($attributes['number_suffix'] ?? null) : null,
                'position' => $locked->columnDefinitions()->active()->max('position') + 1],
        ));
    }

    public function updateColumn(User $actor, TaskBoard $board, TaskColumnDefinition $column, ViewScope $scope, int $expected, array $attributes): TaskColumnDefinition
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_column.updated', function (TaskBoard $locked) use ($column, $attributes): TaskColumnDefinition {
            $row = $this->owned(TaskColumnDefinition::class, $locked, $column);
            if (isset($attributes['type']) && $attributes['type'] !== $row->type->value) {
                if ($this->columnUsed($row)) {
                    throw ValidationException::withMessages(['type' => 'A populated custom column cannot change type.']);
                }
                $row->forceFill(['type' => $attributes['type']]);
            }
            $type = $attributes['type'] ?? $row->type->value;
            $row->fill([
                ...collect($attributes)->only(['name'])->all(),
                'number_suffix' => $type === ColumnType::Number->value
                    ? ($attributes['number_suffix'] ?? $row->number_suffix)
                    : null,
            ]);

            return $row;
        });
    }

    public function setColumnArchived(User $actor, TaskBoard $board, TaskColumnDefinition $column, ViewScope $scope, int $expected, bool $archived): TaskColumnDefinition
    {
        return $this->structure($actor, $board, $scope, $expected, $archived ? 'task_column.archived' : 'task_column.restored', function (TaskBoard $locked) use ($column, $archived): TaskColumnDefinition {
            $row = $this->owned(TaskColumnDefinition::class, $locked, $column);
            if (! $archived && $locked->columnDefinitions()->active()->where('position', $row->position)->exists()) {
                $row->forceFill(['position' => $locked->columnDefinitions()->active()->max('position') + 1]);
            }
            $row->archived_at = $archived ? now() : null;

            return $row;
        });
    }

    public function reorderColumns(User $actor, TaskBoard $board, ViewScope $scope, int $expected, array $ids): TaskBoard
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_columns.reordered', function (TaskBoard $locked) use ($ids): TaskBoard {
            $this->reorder(TaskColumnDefinition::class, $locked, $ids);

            return $locked;
        });
    }

    public function updateCoreLabel(User $actor, TaskBoard $board, ViewScope $scope, int $expected, string $key, string $label): TaskCoreColumnSetting
    {
        if (! array_key_exists($key, self::CORE_COLUMNS)) {
            throw ValidationException::withMessages(['core_key' => 'That core task column does not exist.']);
        }

        return $this->structure($actor, $board, $scope, $expected, 'task_core_column.updated', function (TaskBoard $locked) use ($key, $label): TaskCoreColumnSetting {
            $row = $locked->coreColumnSettings()->where('core_key', $key)->firstOrFail();
            $row->display_label = $label;

            return $row;
        });
    }

    public function createColumnOption(User $actor, TaskBoard $board, TaskColumnDefinition $column, ViewScope $scope, int $expected, array $attributes): TaskColumnOption
    {
        $attributes = $this->only($attributes, ['name', 'color']);

        return $this->structure($actor, $board, $scope, $expected, 'task_column_option.created', function (TaskBoard $locked) use ($column, $attributes): TaskColumnOption {
            $definition = $this->owned(TaskColumnDefinition::class, $locked, $column);
            $this->assertLabelColumn($definition);
            $option = new TaskColumnOption;
            $option->forceFill([
                'board_id' => $locked->id,
                'column_definition_id' => $definition->id,
                ...$attributes,
                'position' => $definition->options()->active()->max('position') + 1,
            ]);

            return $option;
        });
    }

    public function updateColumnOption(User $actor, TaskBoard $board, TaskColumnDefinition $column, TaskColumnOption $option, ViewScope $scope, int $expected, array $attributes): TaskColumnOption
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_column_option.updated', function (TaskBoard $locked) use ($column, $option, $attributes): TaskColumnOption {
            $definition = $this->owned(TaskColumnDefinition::class, $locked, $column);
            $this->assertLabelColumn($definition);
            $row = TaskColumnOption::query()->where('board_id', $locked->id)
                ->where('column_definition_id', $definition->id)->findOrFail($option->id);
            $row->fill(collect($attributes)->only(['name', 'color'])->all());

            return $row;
        });
    }

    public function setColumnOptionArchived(User $actor, TaskBoard $board, TaskColumnDefinition $column, TaskColumnOption $option, ViewScope $scope, int $expected, bool $archived): TaskColumnOption
    {
        return $this->structure($actor, $board, $scope, $expected, $archived ? 'task_column_option.archived' : 'task_column_option.restored', function (TaskBoard $locked) use ($column, $option, $archived): TaskColumnOption {
            $definition = $this->owned(TaskColumnDefinition::class, $locked, $column);
            $this->assertLabelColumn($definition);
            $row = TaskColumnOption::query()->where('board_id', $locked->id)
                ->where('column_definition_id', $definition->id)->findOrFail($option->id);
            if (! $archived && $definition->options()->active()->where('position', $row->position)->exists()) {
                $row->forceFill(['position' => $definition->options()->active()->max('position') + 1]);
            }
            $row->archived_at = $archived ? now() : null;

            return $row;
        });
    }

    public function reorderColumnOptions(User $actor, TaskBoard $board, TaskColumnDefinition $column, ViewScope $scope, int $expected, array $ids): TaskColumnDefinition
    {
        return $this->structure($actor, $board, $scope, $expected, 'task_column_options.reordered', function (TaskBoard $locked) use ($column, $ids): TaskColumnDefinition {
            $definition = $this->owned(TaskColumnDefinition::class, $locked, $column);
            $this->assertLabelColumn($definition);
            $this->reorder(TaskColumnOption::class, $locked, $ids, ['column_definition_id' => $definition->id]);

            return $definition;
        });
    }

    private function structure(User $actor, TaskBoard $board, ViewScope $scope, int $expected, string $action, callable $change): mixed
    {
        return DB::transaction(function () use ($actor, $board, $scope, $expected, $action, $change): mixed {
            $locked = TaskBoard::query()->lockForUpdate()->findOrFail($board->id);
            $this->access->manageDecision($actor, $locked, $scope)->authorize();
            if ((int) $locked->structure_version !== $expected) {
                throw new TaskStructureConflict((int) $locked->structure_version);
            }

            $result = $change($locked);
            if ($result instanceof Model && $result !== $locked) {
                $result->save();
            }
            $locked->forceFill([
                'structure_version' => $expected + 1,
                'updated_by' => $actor->id,
            ])->save();
            $record = $result instanceof Model ? $result : $locked;
            $this->audit->log($action, metadata: [
                'board_id' => $locked->id,
                'record_type' => $record->getMorphClass(),
                'record_id' => $record->getKey(),
            ], actor: $actor);

            return $result instanceof Model ? $result->fresh() : $result;
        });
    }

    private function createRecord(User $actor, array $attributes, array $place, ?TaskFolder $folder, bool $defaults): TaskBoard
    {
        $board = new TaskBoard;
        $board->forceFill([
            ...$place,
            'folder_id' => $folder?->id,
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'visibility' => $attributes['visibility'],
            'position' => $this->nextBoardPosition($place['company_id'], $place['franchise_id'], $folder?->id),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();
        TaskBoardMember::create([
            'board_id' => $board->id,
            'user_id' => $actor->id,
            'role' => BoardMemberRole::Owner,
            'is_active' => true,
        ]);

        if ($defaults) {
            $this->installDefaults($board);
        }

        return $board;
    }

    private function installDefaults(TaskBoard $board): void
    {
        foreach ([['To do', '#00AEEF'], ['Completed', '#33B47A']] as $position => [$name, $color]) {
            $this->newRow(TaskGroup::class, $board, compact('name', 'color', 'position'));
        }
        $statuses = [
            ['Not started', '#7D8893', StatusCategory::NotStarted],
            ['Working on it', '#B05F00', StatusCategory::Active],
            ['Blocked', '#B42318', StatusCategory::Blocked],
            ['Done', '#188038', StatusCategory::Completed],
            ['Cancelled', '#5B6672', StatusCategory::Cancelled],
        ];
        foreach ($statuses as $position => [$name, $color, $category]) {
            $this->newRow(TaskStatusOption::class, $board, compact('name', 'color', 'position') + ['category' => $category->value]);
        }
        foreach (PriorityRank::cases() as $position => $rank) {
            $this->newRow(TaskPriorityOption::class, $board, [
                'name' => ucfirst($rank->value),
                'color' => match ($rank) {
                    PriorityRank::Low => '#188038',
                    PriorityRank::Normal => '#1769AA',
                    PriorityRank::High => '#B05F00',
                    PriorityRank::Urgent => '#B42318',
                },
                'rank' => $rank->value,
                'position' => $position,
            ]);
        }
        foreach (self::CORE_COLUMNS as $key => $label) {
            $this->newRow(TaskCoreColumnSetting::class, $board, ['core_key' => $key, 'display_label' => $label]);
        }
        $view = new TaskView;
        $view->forceFill([
            'board_id' => $board->id,
            'owner_user_id' => null,
            'visibility' => 'shared',
            'name' => 'Main',
            'is_locked' => false,
            'is_permanent_main' => true,
            'configuration' => [
                'schema' => 1,
                'presentation' => 'table',
                'filters' => [],
                'sorts' => [],
                'hidden_columns' => [],
                'column_order' => array_keys(self::CORE_COLUMNS),
                'column_widths' => (object) [],
                'group_by' => null,
                'search' => '',
            ],
            'position' => 0,
        ])->save();
    }

    private function newRow(string $class, TaskBoard $board, array $attributes): Model
    {
        $row = new $class;
        $row->forceFill(['board_id' => $board->id, ...$attributes])->save();

        return $row;
    }

    private function copyRow(string $class, TaskBoard $board, Model $source, array $fields): Model
    {
        return $this->newRow($class, $board, $source->only($fields));
    }

    private function owned(string $class, TaskBoard $board, Model $candidate): Model
    {
        return $class::query()->where('board_id', $board->id)->findOrFail($candidate->id);
    }

    private function reorder(string $class, TaskBoard $board, array $ids, array $where = []): void
    {
        $query = $class::query()->where('board_id', $board->id)->active();
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }
        $rows = $query->lockForUpdate()->get();
        $actual = $rows->modelKeys();
        sort($actual);
        $submitted = array_values(array_unique($ids));
        $comparison = $submitted;
        sort($comparison);
        if ($actual !== $comparison || count($submitted) !== count($ids)) {
            throw ValidationException::withMessages(['ordered_ids' => 'Submit every active row exactly once.']);
        }

        $temporary = ((int) $rows->min('position')) - count($rows) - 1;
        foreach ($submitted as $offset => $id) {
            $rows->firstWhere('id', $id)->forceFill(['position' => $temporary + $offset])->save();
        }
        foreach ($submitted as $position => $id) {
            $rows->firstWhere('id', $id)->forceFill(['position' => $position])->save();
        }
    }

    private function assertStatusCategories(TaskBoard $board, TaskStatusOption $changing, ?string $replacement): void
    {
        $categories = $board->statusOptions()->active()->whereKeyNot($changing->id)->get(['category'])
            ->map(fn (TaskStatusOption $status) => $status->category->value)
            ->all();
        if ($replacement !== null) {
            $categories[] = $replacement;
        }
        if (! in_array(StatusCategory::Completed->value, $categories, true)) {
            throw ValidationException::withMessages(['category' => 'Every board must retain an active Completed status.']);
        }
        if (array_intersect($categories, [
            StatusCategory::NotStarted->value,
            StatusCategory::Active->value,
            StatusCategory::Blocked->value,
        ]) === []) {
            throw ValidationException::withMessages(['category' => 'Every board must retain an active non-terminal status.']);
        }
    }

    private function columnUsed(TaskColumnDefinition $column): bool
    {
        return $column->values()->exists() || $column->people()->exists()
            || $column->labels()->exists() || $column->files()->exists();
    }

    private function assertLabelColumn(TaskColumnDefinition $column): void
    {
        if ($column->type !== ColumnType::Label) {
            throw ValidationException::withMessages(['column' => 'Options belong only to Label columns.']);
        }
    }

    private function folderAtPlace(?string $id, array $place): ?TaskFolder
    {
        if ($id === null) {
            return null;
        }
        $folder = TaskFolder::query()->active()->findOrFail($id);
        if ($folder->company_id !== $place['company_id'] || $folder->franchise_id !== $place['franchise_id']) {
            throw ValidationException::withMessages(['folder_id' => 'The folder must be filed at the board\'s exact place.']);
        }

        return $folder;
    }

    private function rejectPlaceInput(array $attributes): void
    {
        foreach (['organization_id', 'company_id', 'franchise_id'] as $key) {
            if (array_key_exists($key, $attributes)) {
                throw ValidationException::withMessages([$key => 'A board is filed from the active view, not request data.']);
            }
        }
    }

    private function nextBoardPosition(?string $companyId, ?string $franchiseId, ?string $folderId): int
    {
        return (int) TaskBoard::query()->active()
            ->where('company_id', $companyId)->where('franchise_id', $franchiseId)
            ->where('folder_id', $folderId)->lockForUpdate()->max('position') + 1;
    }

    private function positionTaken(TaskBoard $board): bool
    {
        return TaskBoard::query()->active()->whereKeyNot($board->id)
            ->where('company_id', $board->company_id)->where('franchise_id', $board->franchise_id)
            ->where('folder_id', $board->folder_id)->where('position', $board->position)->exists();
    }

    private function replaceIds(mixed $value, array $ids): mixed
    {
        if (is_string($value)) {
            return $ids[$value] ?? $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn ($item) => $this->replaceIds($item, $ids), $value);
    }

    private function only(array $attributes, array $keys): array
    {
        return collect($attributes)->only($keys)->all();
    }

    private function configurationRelations(): array
    {
        return [
            'groups', 'statusOptions', 'priorityOptions', 'coreColumnSettings',
            'columnDefinitions.options', 'views', 'members',
        ];
    }
}
