<?php

namespace App\Support\Tasks;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnFile;
use App\Models\TaskColumnLabel;
use App\Models\TaskColumnPerson;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskDuplicator
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
        private readonly TaskWriter $writer,
        private readonly AuditLogger $audit,
    ) {}

    public function duplicateTask(
        User $actor,
        Task $source,
        TaskBoard $destination,
        ViewScope $scope,
        int $expectedVersion,
        string $destinationGroupId,
        array $mappings = [],
        ?string $title = null,
    ): Task {
        return DB::transaction(function () use ($actor, $source, $destination, $scope, $expectedVersion, $destinationGroupId, $mappings, $title): Task {
            [$sourceBoard, $destinationBoard] = $this->lockBoards($source->board_id, $destination->id);
            $this->authorize($actor, $sourceBoard, $destinationBoard, $scope);
            $locked = Task::query()->where('board_id', $sourceBoard->id)->active()
                ->lockForUpdate()->findOrFail($source->id);
            if ((int) $locked->version !== $expectedVersion) {
                throw new TaskConflict((int) $locked->version, [
                    'id' => $locked->id, 'version' => (int) $locked->version,
                    'title' => $locked->title,
                ], ['title']);
            }
            TaskGroup::query()->where('board_id', $destinationBoard->id)->active()
                ->lockForUpdate()->findOrFail($destinationGroupId);
            $locked->load($this->copyRelations());
            $this->preflightTrees(collect([$locked]), $sourceBoard, $destinationBoard, $mappings);

            return $this->copyTree(
                $actor, $locked, $destinationBoard, $scope, $destinationGroupId,
                $mappings, $title,
            );
        });
    }

    public function duplicateBoardTasks(
        User $actor,
        TaskBoard $source,
        TaskBoard $destination,
        ViewScope $scope,
        array $mappings,
    ): void {
        $this->authorize($actor, $source, $destination, $scope);
        $tasks = Task::query()->where('board_id', $source->id)->active()
            ->whereNull('parent_task_id')->orderBy('group_id')->orderBy('position')
            ->lockForUpdate()->with($this->copyRelations())->get();
        $this->preflightTrees($tasks, $source, $destination, $mappings, includeGroups: true);
        foreach ($tasks as $task) {
            $groupId = $this->mappedId('groups', $task->group_id, $source, $destination, $mappings);
            $this->copyTree($actor, $task, $destination, $scope, $groupId, $mappings);
        }
    }

    private function copyTree(
        User $actor,
        Task $source,
        TaskBoard $destination,
        ViewScope $scope,
        string $groupId,
        array $mappings,
        ?string $title = null,
    ): Task {
        $copy = $this->createCopy($actor, $source, $destination, $scope, [
            'group_id' => $groupId,
            'title' => $title ?? $source->title,
        ], $mappings);
        $values = $this->copyValues($source, $copy, $destination, $mappings);
        $this->recordCopy($actor, $source, $copy, $destination, $values);

        if ($source->parent_task_id === null) {
            foreach ($source->subtasks->whereNull('archived_at')->sortBy('position') as $subtask) {
                $child = $this->createCopy($actor, $subtask, $destination, $scope, [
                    'parent_task_id' => $copy->id,
                    'title' => $subtask->title,
                ], $mappings);
                $values = $this->copyValues($subtask, $child, $destination, $mappings);
                $this->recordCopy($actor, $subtask, $child, $destination, $values);
            }
        }

        return $copy->fresh([
            'board', 'group', 'parent.group', 'owner', 'statusOption', 'priorityOption',
            'collaborators.user', 'subtasks' => fn ($query) => $query->active()->orderBy('position'),
        ]);
    }

    private function createCopy(
        User $actor,
        Task $source,
        TaskBoard $destination,
        ViewScope $scope,
        array $place,
        array $mappings,
    ): Task {
        $statusId = $this->mappedId('status_options', $source->status_option_id, $source->board, $destination, $mappings);
        $status = TaskStatusOption::query()->where('board_id', $destination->id)->active()->findOrFail($statusId);
        if ($status->category !== $source->statusOption->category) {
            throw ValidationException::withMessages(['mappings.status_options' => 'Mapped statuses must use the same structural category.']);
        }
        $priorityId = null;
        if ($source->priority_option_id !== null) {
            $priorityId = $this->mappedId('priority_options', $source->priority_option_id, $source->board, $destination, $mappings);
            $priority = TaskPriorityOption::query()->where('board_id', $destination->id)->active()->findOrFail($priorityId);
            if ($priority->rank !== $source->priorityOption->rank) {
                throw ValidationException::withMessages(['mappings.priority_options' => 'Mapped priorities must use the same structural rank.']);
            }
        }

        $ownerId = $this->eligibleUserId($source->owner_user_id, $destination);
        $collaborators = $source->collaborators->pluck('user_id')
            ->filter(fn (string $id) => $this->eligibleUserId($id, $destination) !== null)
            ->values()->all();

        return $this->writer->create($actor, $destination, $scope, [
            ...$place,
            'title' => $place['title'],
            'description' => $source->description,
            'owner_user_id' => $ownerId,
            'collaborator_user_ids' => $collaborators,
            'status_option_id' => $statusId,
            'priority_option_id' => $priorityId,
            'start_on' => $source->start_on?->format('Y-m-d'),
            'due_on' => $source->due_on?->format('Y-m-d'),
        ]);
    }

    /** @return list<array{column_definition_id: string, type: string, value: mixed}> */
    private function copyValues(Task $source, Task $copy, TaskBoard $destination, array $mappings): array
    {
        $copied = [];
        foreach ($source->columnValues as $value) {
            $definitionId = $this->compatibleDefinition($value->column_definition_id, $source->board, $destination, $mappings);
            $definition = TaskColumnDefinition::query()->where('board_id', $destination->id)->findOrFail($definitionId);
            $row = new TaskColumnValue;
            $row->forceFill([
                'board_id' => $destination->id,
                'task_id' => $copy->id,
                'column_definition_id' => $definitionId,
                ...$value->only(['text_value', 'number_value', 'boolean_value', 'date_value', 'url_value', 'link_label_value']),
            ])->save();
            $copied[] = [
                'column_definition_id' => $definitionId,
                'type' => $definition->type->value,
                'value' => match ($definition->type) {
                    ColumnType::Text => $row->text_value,
                    ColumnType::Number => $row->number_value,
                    ColumnType::Checkbox => $row->boolean_value,
                    ColumnType::Date => $row->date_value?->format('Y-m-d'),
                    ColumnType::Link => ['url' => $row->url_value, 'label' => $row->link_label_value],
                    default => null,
                },
            ];
        }
        $people = [];
        foreach ($source->columnPeople as $person) {
            if ($this->eligibleUserId($person->user_id, $destination) === null) {
                continue;
            }
            $definitionId = $this->compatibleDefinition($person->column_definition_id, $source->board, $destination, $mappings);
            $row = new TaskColumnPerson;
            $row->forceFill([
                'board_id' => $destination->id,
                'task_id' => $copy->id,
                'column_definition_id' => $definitionId,
                'user_id' => $person->user_id,
            ])->save();
            $people[$definitionId][] = $person->user_id;
        }
        foreach ($people as $definitionId => $ids) {
            sort($ids);
            $copied[] = ['column_definition_id' => $definitionId, 'type' => ColumnType::People->value, 'value' => $ids];
        }
        $labels = [];
        foreach ($source->columnLabels as $label) {
            $definitionId = $this->compatibleDefinition($label->column_definition_id, $source->board, $destination, $mappings);
            $optionId = $this->mappedId('column_options', $label->column_option_id, $source->board, $destination, $mappings);
            $valid = DB::table('task_column_options')->where('board_id', $destination->id)
                ->where('column_definition_id', $definitionId)->where('id', $optionId)
                ->exists();
            if (! $valid) {
                throw ValidationException::withMessages(['mappings.column_options' => 'A mapped Label option is incompatible with its destination column.']);
            }
            $row = new TaskColumnLabel;
            $row->forceFill([
                'board_id' => $destination->id,
                'task_id' => $copy->id,
                'column_definition_id' => $definitionId,
                'column_option_id' => $optionId,
            ])->save();
            $labels[$definitionId][] = $optionId;
        }
        foreach ($labels as $definitionId => $ids) {
            sort($ids);
            $copied[] = ['column_definition_id' => $definitionId, 'type' => ColumnType::Label->value, 'value' => $ids];
        }

        usort($copied, fn (array $left, array $right): int => $left['column_definition_id'] <=> $right['column_definition_id']);

        return $copied;
    }

    private function compatibleDefinition(string $sourceId, TaskBoard $source, TaskBoard $destination, array $mappings): string
    {
        $destinationId = $this->mappedId('column_definitions', $sourceId, $source, $destination, $mappings);
        $sourceDefinition = TaskColumnDefinition::query()->where('board_id', $source->id)->findOrFail($sourceId);
        $destinationDefinition = TaskColumnDefinition::query()->where('board_id', $destination->id)->findOrFail($destinationId);
        if ($sourceDefinition->type !== $destinationDefinition->type) {
            throw ValidationException::withMessages(['mappings.column_definitions' => 'Mapped custom columns must have the same type.']);
        }

        return $destinationId;
    }

    private function mappedId(string $kind, string $sourceId, TaskBoard $source, TaskBoard $destination, array $mappings): string
    {
        if ($source->id === $destination->id) {
            return $sourceId;
        }
        $mapped = $mappings[$kind][$sourceId] ?? null;
        if (! is_string($mapped)) {
            throw ValidationException::withMessages(["mappings.{$kind}" => "Map every used {$kind} value explicitly."]);
        }

        return $mapped;
    }

    /** @param Collection<int, Task> $roots */
    private function preflightTrees(
        Collection $roots,
        TaskBoard $source,
        TaskBoard $destination,
        array $mappings,
        bool $includeGroups = false,
    ): void {
        $tasks = $roots->flatMap(fn (Task $root) => collect([$root])->concat(
            $root->subtasks->whereNull('archived_at'),
        ));
        if ($tasks->contains(fn (Task $task): bool => $task->columnFiles->isNotEmpty())) {
            throw ValidationException::withMessages([
                'include_tasks' => 'Tasks with File-column values cannot be duplicated until the off-instance restore gate passes.',
            ]);
        }
        if ($source->id === $destination->id) {
            return;
        }

        $required = [
            'status_options' => $tasks->pluck('status_option_id'),
            'priority_options' => $tasks->pluck('priority_option_id')->filter(),
            'column_definitions' => $tasks->flatMap(fn (Task $task) => collect()
                ->concat($task->columnValues->pluck('column_definition_id'))
                ->concat($task->columnPeople->pluck('column_definition_id'))
                ->concat($task->columnLabels->pluck('column_definition_id'))),
            'column_options' => $tasks->flatMap(fn (Task $task) => $task->columnLabels->pluck('column_option_id')),
        ];
        if ($includeGroups) {
            $required['groups'] = $roots->pluck('group_id')->filter();
        }

        $errors = [];
        foreach ($required as $kind => $ids) {
            foreach ($ids->filter()->unique() as $sourceId) {
                if (! is_string($mappings[$kind][$sourceId] ?? null)) {
                    $errors["mappings.{$kind}.{$sourceId}"] = "Map this used {$kind} value explicitly.";
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public function assertBoardFileValuesAvailable(TaskBoard $source): void
    {
        $hasFiles = TaskColumnFile::query()->where('board_id', $source->id)
            ->whereHas('task', fn ($query) => $query->active())
            ->exists();
        if ($hasFiles) {
            throw ValidationException::withMessages([
                'include_tasks' => 'Tasks with File-column values cannot be duplicated until the off-instance restore gate passes.',
            ]);
        }
    }

    private function eligibleUserId(?string $id, TaskBoard $destination): ?string
    {
        if ($id === null) {
            return null;
        }
        $user = User::query()->find($id);

        return $user !== null && $this->participants->forTaskParticipation($user, $destination)->allowed()
            ? $id
            : null;
    }

    private function authorize(User $actor, TaskBoard $source, TaskBoard $destination, ViewScope $scope): void
    {
        if ($source->visibility === BoardVisibility::Private || $destination->visibility === BoardVisibility::Private) {
            TaskBoardMember::query()->whereIn('board_id', array_unique([$source->id, $destination->id]))
                ->where('user_id', $actor->id)->orderBy('board_id')->lockForUpdate()->get();
        }
        $this->access->viewDecision($actor, $source, $scope)->authorize();
        $this->access->viewDecision($actor, $destination, $scope)->authorize();
        if (! $actor->hasPermission('tasks.create')) {
            Refusal::permission('duplicate tasks')->authorize();
        }
        if ($source->organization_id !== $destination->organization_id
            || $source->company_id !== $destination->company_id
            || $source->franchise_id !== $destination->franchise_id) {
            throw ValidationException::withMessages([
                'destination_board_id' => 'Tasks can be duplicated only between boards filed at the identical place.',
            ]);
        }
    }

    /** @return array{TaskBoard,TaskBoard} */
    private function lockBoards(string $sourceId, string $destinationId): array
    {
        $boards = TaskBoard::query()->active()->whereIn('id', array_unique([$sourceId, $destinationId]))
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if (! $boards->has($sourceId) || ! $boards->has($destinationId)) {
            abort(404);
        }

        return [$boards[$sourceId], $boards[$destinationId]];
    }

    private function copyRelations(): array
    {
        return [
            'board', 'statusOption', 'priorityOption', 'collaborators',
            'columnValues', 'columnPeople', 'columnLabels', 'columnFiles',
            'subtasks' => fn ($query) => $query->active()->with([
                'board', 'statusOption', 'priorityOption', 'collaborators',
                'columnValues', 'columnPeople', 'columnLabels', 'columnFiles',
            ]),
        ];
    }

    private function recordCopy(
        User $actor,
        Task $source,
        Task $copy,
        TaskBoard $destination,
        array $customValues,
    ): void
    {
        $scope = TaskPlace::auditScope($destination);
        $audit = $this->audit->log('task.duplicated', target: $copy, changes: [
            'before' => [],
            'after' => ['task_id' => $copy->id, 'source_task_id' => $source->id, 'custom_values' => $customValues],
        ], metadata: [
            'board_id' => $destination->id,
            'task_id' => $copy->id,
            'source_board_id' => $source->board_id,
            'source_task_id' => $source->id,
        ], actor: $actor, scopeType: $scope['type'], scopeId: $scope['id']);
        TaskActivity::create([
            'board_id' => $destination->id,
            'task_id' => $copy->id,
            'actor_user_id' => $actor->id,
            'kind' => 'task.duplicated',
            'summary' => 'Task duplicated',
            'data' => [
                'audit_event_id' => $audit?->id,
            ],
        ]);
    }
}
