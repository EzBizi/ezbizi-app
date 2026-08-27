<?php

namespace App\Support\Tasks;

use App\Models\AuditEvent;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskCollaborator;
use App\Models\TaskGroup;
use App\Models\TaskNotificationIntent;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\TaskWatchPreference;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Numbering\RecordNumberGenerator;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TaskWriter
{
    private const CORE_FIELDS = [
        'title', 'description', 'owner_user_id', 'status_option_id', 'priority_option_id',
        'start_on', 'due_on', 'collaborator_user_ids',
    ];

    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
        private readonly TaskRichText $richText,
        private readonly RecordNumberGenerator $numbers,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    public function create(User $actor, TaskBoard $board, ViewScope $scope, array $attributes): Task
    {
        return DB::transaction(function () use ($actor, $board, $scope, $attributes): Task {
            $lockedBoard = $this->lockBoardFor($actor, $board, $scope, 'tasks.create', 'create tasks');
            $parent = $this->parent($lockedBoard, $attributes['parent_task_id'] ?? null);
            $group = $parent === null
                ? $this->group($lockedBoard, $attributes['group_id'] ?? null)
                : null;
            if ($parent !== null && array_key_exists('group_id', $attributes) && $attributes['group_id'] !== null) {
                throw ValidationException::withMessages(['group_id' => 'A subtask inherits its parent group.']);
            }

            $status = $this->status($lockedBoard, $attributes['status_option_id']);
            $priority = $this->priority($lockedBoard, $attributes['priority_option_id'] ?? null);
            $owner = $this->participant($lockedBoard, $attributes['owner_user_id'] ?? null, 'owner_user_id');
            $collaborators = $this->collaborators($lockedBoard, $attributes['collaborator_user_ids'] ?? []);
            $description = $this->richText->normalize($attributes['description'] ?? null);
            $dueOn = $attributes['due_on'] ?? null;

            $task = new Task;
            $task->forceFill([
                'organization_id' => $lockedBoard->organization_id,
                'board_id' => $lockedBoard->id,
                'group_id' => $group?->id,
                'parent_task_id' => $parent?->id,
                'number' => $this->numbers->next(RecordNumberGenerator::TASKS, $this->numberingPlace($lockedBoard)),
                'title' => $attributes['title'],
                'description' => $description['html'],
                'description_plain' => $description['plain'],
                'owner_user_id' => $owner?->id,
                'status_option_id' => $status->id,
                'priority_option_id' => $priority?->id,
                'start_on' => $attributes['start_on'] ?? null,
                'due_on' => $dueOn,
                'due_revision' => $dueOn === null ? 0 : 1,
                'position' => $this->nextPosition($lockedBoard, $group?->id, $parent?->id),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->applyResolutionState($task, null, $status->category, $actor);
            $task->save();
            if ($group !== null) {
                $group->forceFill(['order_version' => (int) $group->order_version + 1])->save();
            }
            $this->replaceCollaborators($task, $actor, $collaborators);

            $eventKeys = ['task.created'];
            if ($owner !== null) {
                $eventKeys[] = 'task.owner_changed';
            }
            if ($dueOn !== null) {
                $eventKeys[] = 'task.due_date_changed';
            }
            if ($status->category === StatusCategory::Completed) {
                $eventKeys[] = 'task.completed';
            }
            $audit = $this->recordMutation(
                $task,
                $actor,
                'task.created',
                [],
                $this->auditFields($task),
                $eventKeys,
            );
            $this->notify($task, $audit, $actor, $owner === null ? [] : [$owner->id => 'task.assigned']);
            $this->notifyCollaborators($task, $audit, $actor, $collaborators->modelKeys());

            return $task->fresh($this->relations());
        });
    }

    public function update(
        User $actor,
        Task $task,
        ViewScope $scope,
        int $expectedVersion,
        array $attributes,
        ?string $causationId = null,
    ): Task {
        return DB::transaction(function () use ($actor, $task, $scope, $expectedVersion, $attributes, $causationId): Task {
            $lockedBoard = $this->lockBoardFor($actor, $task->board, $scope, 'tasks.edit', 'change this task');
            $locked = Task::query()->where('board_id', $lockedBoard->id)->lockForUpdate()->findOrFail($task->id);
            abort_if($locked->archived_at !== null, 404);
            $this->assertVersion($locked, $expectedVersion, array_keys($attributes));

            return $this->applyUpdate($actor, $lockedBoard, $locked, $attributes, $causationId);
        });
    }

    public function setArchived(
        User $actor,
        Task $task,
        ViewScope $scope,
        int $expectedVersion,
        bool $archived,
        ?string $causationId = null,
    ): Task {
        return DB::transaction(function () use ($actor, $task, $scope, $expectedVersion, $archived, $causationId): Task {
            $lockedBoard = $this->lockBoardFor($actor, $task->board, $scope, 'tasks.delete', 'archive tasks');
            $locked = Task::query()->where('board_id', $lockedBoard->id)->lockForUpdate()->findOrFail($task->id);
            if ($archived) {
                abort_if($locked->archived_at !== null, 404);
            } else {
                abort_if($locked->archived_at === null, 404);
            }
            $this->assertVersion($locked, $expectedVersion, ['archived_at']);

            if (! $archived && $locked->parent_task_id !== null) {
                $parent = Task::query()->lockForUpdate()->findOrFail($locked->parent_task_id);
                if ($parent->archived_at !== null) {
                    throw ValidationException::withMessages(['task' => 'Restore the parent task before restoring this subtask.']);
                }
            }

            $affected = collect([$locked]);
            if ($locked->parent_task_id === null) {
                $children = Task::query()->where('parent_task_id', $locked->id)
                    ->when($archived, fn ($query) => $query->active(), fn ($query) => $query
                        ->whereNotNull('archived_at')->where('archived_with_parent_id', $locked->id))
                    ->orderBy('id')->lockForUpdate()->get();
                $affected = $affected->concat($children);
            }

            $causationId ??= (string) Str::uuid7();
            foreach ($affected as $affectedTask) {
                $before = $this->auditFields($affectedTask);
                $values = [
                    'archived_at' => $archived ? now() : null,
                    'archived_with_parent_id' => $affectedTask->id === $locked->id
                        ? null
                        : ($archived ? $locked->id : null),
                    'version' => (int) $affectedTask->version + 1,
                    'detail_version' => (int) $affectedTask->detail_version + 1,
                    'updated_by' => $actor->id,
                ];
                if (! $archived) {
                    $values['position'] = $this->nextPosition(
                        $lockedBoard,
                        $affectedTask->group_id,
                        $affectedTask->parent_task_id,
                    );
                }
                $affectedTask->forceFill($values)->save();
                $this->recordMutation(
                    $affectedTask,
                    $actor,
                    $archived ? 'task.archived' : 'task.restored',
                    $before,
                    $this->auditFields($affectedTask),
                    $archived ? ['task.archived'] : [],
                    $causationId,
                );
            }
            if ($archived) {
                $this->compactOrderingSet($lockedBoard, $locked->group_id, $locked->parent_task_id);
            }
            if ($locked->parent_task_id === null) {
                $group = TaskGroup::query()->lockForUpdate()->findOrFail($locked->group_id);
                $group->forceFill(['order_version' => (int) $group->order_version + 1])->save();
            }

            return $locked->fresh($this->relations());
        });
    }

    /** @param list<array{id:string,expected_version:int}> $targets */
    public function bulk(
        User $actor,
        TaskBoard $board,
        ViewScope $scope,
        array $targets,
        array $changes,
    ): array {
        return DB::transaction(function () use ($actor, $board, $scope, $targets, $changes): array {
            $permission = array_key_exists('archived', $changes) ? 'tasks.delete' : 'tasks.edit';
            $act = array_key_exists('archived', $changes) ? 'archive tasks' : 'change tasks';
            $lockedBoard = $this->lockBoardFor($actor, $board, $scope, $permission, $act);
            $byId = collect($targets)->keyBy('id');
            $tasks = Task::query()->where('board_id', $lockedBoard->id)
                ->whereIn('id', $byId->keys())->orderBy('id')->lockForUpdate()->get();
            if ($tasks->count() !== $byId->count()) {
                throw ValidationException::withMessages(['tasks' => 'One or more selected tasks are unavailable.']);
            }

            $errors = [];
            foreach ($tasks as $locked) {
                $target = $byId[$locked->id];
                if ((int) $locked->version !== (int) $target['expected_version']) {
                    $errors[$locked->id] = ['version' => 'This task changed after it was selected.'];
                }
                if (! array_key_exists('archived', $changes) && $locked->archived_at !== null) {
                    $errors[$locked->id] = ['task' => 'This task is unavailable.'];
                }
                if (array_key_exists('archived', $changes)
                    && ((bool) $changes['archived']) === ($locked->archived_at !== null)) {
                    $errors[$locked->id] = ['archived' => 'This task is already in the requested archive state.'];
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['tasks' => $errors]);
            }

            $causationId = (string) Str::uuid7();
            $selectedIds = $byId->keys();
            $executionTasks = $tasks->sortBy(fn (Task $candidate): int => $candidate->parent_task_id === null ? 0 : 1)
                ->reject(function (Task $candidate) use ($changes, $selectedIds): bool {
                    if (! array_key_exists('archived', $changes)
                        || $candidate->parent_task_id === null
                        || ! $selectedIds->contains($candidate->parent_task_id)) {
                        return false;
                    }

                    return (bool) $changes['archived']
                        || $candidate->archived_with_parent_id === $candidate->parent_task_id;
                });
            foreach ($executionTasks as $locked) {
                if (array_key_exists('archived', $changes)) {
                    $this->setArchived(
                        $actor,
                        $locked,
                        $scope,
                        (int) $locked->version,
                        (bool) $changes['archived'],
                        $causationId,
                    );
                } else {
                    $this->applyUpdate($actor, $lockedBoard, $locked, $changes, $causationId);
                }
            }

            $fresh = Task::query()->whereIn('id', $selectedIds)->with($this->relations())->get()->keyBy('id');

            return collect($targets)->map(fn (array $target) => $fresh->get($target['id']))->all();
        });
    }

    public function reorder(
        User $actor,
        TaskBoard $board,
        ViewScope $scope,
        array $input,
    ): Task {
        return DB::transaction(function () use ($actor, $board, $scope, $input): Task {
            $lockedBoard = $this->lockBoardFor($actor, $board, $scope, 'tasks.edit', 'reorder tasks');
            $moved = Task::query()->where('board_id', $lockedBoard->id)->active()
                ->lockForUpdate()->findOrFail($input['task_id']);
            if ($moved->parent_task_id !== null) {
                throw ValidationException::withMessages(['task_id' => 'Subtasks are reordered inside their parent, not between groups.']);
            }
            $sourceGroupId = $moved->group_id;
            $groupIds = array_values(array_unique([$sourceGroupId, $input['destination_group_id']]));
            $groups = TaskGroup::query()->where('board_id', $lockedBoard->id)->active()
                ->whereIn('id', $groupIds)->orderBy('id')->lockForUpdate()->get();
            if ($groups->count() !== count($groupIds)) {
                throw ValidationException::withMessages(['destination_group_id' => 'The destination group is unavailable.']);
            }
            $sourceGroup = $groups->firstWhere('id', $sourceGroupId);
            $destinationGroup = $groups->firstWhere('id', $input['destination_group_id']);
            if ($sourceGroupId === $destinationGroup->id
                && (int) $input['expected_source_order_version'] !== (int) $input['expected_destination_order_version']) {
                throw new TaskOrderConflict([$sourceGroup->id => (int) $sourceGroup->order_version]);
            }
            $expected = [
                $sourceGroup->id => (int) $input['expected_source_order_version'],
                $destinationGroup->id => (int) $input['expected_destination_order_version'],
            ];
            $current = $groups->mapWithKeys(fn (TaskGroup $group) => [$group->id => (int) $group->order_version])->all();
            foreach ($expected as $id => $version) {
                if ($current[$id] !== $version) {
                    throw new TaskOrderConflict($current);
                }
            }
            if ($sourceGroupId !== $destinationGroup->id) {
                $this->assertVersion($moved, (int) $input['expected_task_version'], ['group_id']);
            }
            $before = $this->auditFields($moved);

            $siblings = Task::query()->where('board_id', $lockedBoard->id)->active()
                ->whereNull('parent_task_id')->whereIn('group_id', $groupIds)
                ->orderBy('id')->lockForUpdate()->get();
            $source = $siblings->where('group_id', $sourceGroupId)->sortBy('position')->values()
                ->reject(fn (Task $task) => $task->id === $moved->id)->values();
            $destination = $sourceGroupId === $destinationGroup->id
                ? $source
                : $siblings->where('group_id', $destinationGroup->id)->sortBy('position')->values();
            $insertAt = $this->insertionIndex(
                $destination,
                $input['before_task_id'] ?? null,
                $input['after_task_id'] ?? null,
            );
            $destination->splice($insertAt, 0, [$moved]);

            $sourceFinal = $sourceGroupId === $destinationGroup->id ? collect() : $source;
            $this->temporarilyPosition($sourceFinal, -100000);
            $this->temporarilyPosition($destination, -200000);
            if ($sourceGroupId !== $destinationGroup->id) {
                $moved->forceFill([
                    'group_id' => $destinationGroup->id,
                    'position' => -300000,
                    'version' => (int) $moved->version + 1,
                    'detail_version' => (int) $moved->detail_version + 1,
                    'updated_by' => $actor->id,
                ])->save();
            }
            $this->denselyPosition($sourceFinal);
            $this->denselyPosition($destination);
            if ($sourceGroupId === $destinationGroup->id) {
                $moved->forceFill([
                    'detail_version' => (int) $moved->detail_version + 1,
                    'updated_by' => $actor->id,
                ])->save();
            }
            foreach ($groups as $group) {
                $group->forceFill(['order_version' => (int) $group->order_version + 1])->save();
            }

            $this->recordMutation(
                $moved,
                $actor,
                $sourceGroupId === $destinationGroup->id ? 'task.reordered' : 'task.moved',
                $before,
                $this->auditFields($moved),
                [],
                changedFields: $sourceGroupId === $destinationGroup->id
                    ? ['position']
                    : ['group_id', 'position'],
            );

            return $moved->fresh($this->relations());
        });
    }

    private function applyUpdate(User $actor, TaskBoard $board, Task $task, array $attributes, ?string $causationId): Task
    {
        $before = $this->auditFields($task);
        $changedFields = [];
        $eventKeys = [];
        $notifications = [];
        $collaboratorsBefore = $task->collaborators()->pluck('user_id')->all();

        if (array_key_exists('title', $attributes) && $attributes['title'] !== $task->title) {
            $task->title = $attributes['title'];
            $changedFields[] = 'title';
        }
        if (array_key_exists('description', $attributes)) {
            $description = $this->richText->normalize($attributes['description']);
            if ($description['html'] !== $task->description) {
                $task->description = $description['html'];
                $task->description_plain = $description['plain'];
                $changedFields[] = 'description';
            }
        }
        if (array_key_exists('owner_user_id', $attributes)) {
            $owner = $this->participant($board, $attributes['owner_user_id'], 'owner_user_id');
            if ($owner?->id !== $task->owner_user_id) {
                $task->owner_user_id = $owner?->id;
                $changedFields[] = 'owner_user_id';
                $eventKeys[] = 'task.owner_changed';
                if ($owner !== null) {
                    $notifications[$owner->id] = 'task.assigned';
                }
            }
        }
        if (array_key_exists('status_option_id', $attributes) && $attributes['status_option_id'] !== $task->status_option_id) {
            $beforeStatus = TaskStatusOption::query()->findOrFail($task->status_option_id);
            $status = $this->status($board, $attributes['status_option_id']);
            $task->status_option_id = $status->id;
            $changedFields[] = 'status_option_id';
            $eventKeys[] = 'task.status_changed';
            $eventKeys = [...$eventKeys, ...$this->applyResolutionState($task, $beforeStatus->category, $status->category, $actor)];
        }
        if (array_key_exists('priority_option_id', $attributes)) {
            $priority = $this->priority($board, $attributes['priority_option_id']);
            if ($priority?->id !== $task->priority_option_id) {
                $task->priority_option_id = $priority?->id;
                $changedFields[] = 'priority_option_id';
            }
        }
        if (array_key_exists('start_on', $attributes)) {
            $beforeStart = $task->start_on?->format('Y-m-d');
            if ($beforeStart !== $attributes['start_on']) {
                $task->start_on = $attributes['start_on'];
                $changedFields[] = 'start_on';
            }
        }
        if (array_key_exists('due_on', $attributes)) {
            $beforeDue = $task->due_on?->format('Y-m-d');
            if ($beforeDue !== $attributes['due_on']) {
                $task->due_on = $attributes['due_on'];
                $task->due_revision = (int) $task->due_revision + 1;
                $changedFields[] = 'due_on';
                $eventKeys[] = 'task.due_date_changed';
            }
        }
        if (array_key_exists('group_id', $attributes)) {
            if ($task->parent_task_id !== null) {
                throw ValidationException::withMessages(['group_id' => 'A subtask inherits its parent group.']);
            }
            $group = $this->group($board, $attributes['group_id']);
            if ($group->id !== $task->group_id) {
                throw ValidationException::withMessages(['group_id' => 'Use the task order endpoint to move a task between groups.']);
            }
        }
        if (array_key_exists('parent_task_id', $attributes)) {
            throw ValidationException::withMessages(['parent_task_id' => 'Tasks cannot be reparented.']);
        }

        $collaborators = null;
        if (array_key_exists('collaborator_user_ids', $attributes)) {
            $collaborators = $this->collaborators($board, $attributes['collaborator_user_ids']);
            $afterIds = $collaborators->modelKeys();
            $beforeIds = $collaboratorsBefore;
            sort($afterIds);
            sort($beforeIds);
            if ($afterIds !== $beforeIds) {
                $changedFields[] = 'collaborator_user_ids';
            }
        }

        if ($changedFields === []) {
            return $task->fresh($this->relations());
        }

        $task->forceFill([
            'version' => (int) $task->version + 1,
            'detail_version' => (int) $task->detail_version + 1,
            'updated_by' => $actor->id,
        ])->save();
        if ($collaborators !== null) {
            $this->replaceCollaborators($task, $actor, $collaborators);
        }
        $after = $this->auditFields($task);
        $audit = $this->recordMutation(
            $task,
            $actor,
            'task.updated',
            $before,
            $after,
            array_values(array_unique($eventKeys)),
            $causationId,
            $changedFields,
        );
        $this->notify($task, $audit, $actor, $notifications);
        if (array_intersect($changedFields, ['status_option_id', 'due_on']) !== []) {
            $this->notifyWatchers($task, $audit, $actor);
        }
        if ($collaborators !== null) {
            $this->notifyCollaborators($task, $audit, $actor, array_values(array_diff($collaborators->modelKeys(), $collaboratorsBefore)));
        }

        return $task->fresh($this->relations());
    }

    private function lockBoardFor(User $actor, TaskBoard $board, ViewScope $scope, string $permission, string $act): TaskBoard
    {
        $locked = TaskBoard::query()->lockForUpdate()->findOrFail($board->id);
        if ($locked->visibility === BoardVisibility::Private) {
            TaskBoardMember::query()->where('board_id', $locked->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
        }
        $this->access->viewDecision($actor, $locked, $scope)->authorize();
        if (! $actor->hasPermission($permission)) {
            Refusal::permission($act)->authorize();
        }
        if ($actor->organization?->isReadOnly()) {
            throw ValidationException::withMessages(['organization' => 'This organization is read-only because its subscription has ended.']);
        }

        return $locked;
    }

    private function assertVersion(Task $task, int $expected, array $fields): void
    {
        if ((int) $task->version === $expected) {
            return;
        }

        $fields = array_values(array_intersect($fields, [...self::CORE_FIELDS, 'group_id', 'archived_at']));
        $current = ['id' => $task->id, 'version' => (int) $task->version];
        foreach ($fields as $field) {
            $current[$field] = match ($field) {
                'start_on', 'due_on' => $task->{$field}?->format('Y-m-d'),
                'archived_at' => $task->archived_at?->toIso8601String(),
                'collaborator_user_ids' => $task->collaborators()->orderBy('user_id')->pluck('user_id')->all(),
                default => $task->{$field},
            };
        }

        throw new TaskConflict((int) $task->version, $current, $fields);
    }

    private function group(TaskBoard $board, ?string $id): TaskGroup
    {
        if ($id === null) {
            throw ValidationException::withMessages(['group_id' => 'Choose a group for this task.']);
        }

        return TaskGroup::query()->where('board_id', $board->id)->active()->lockForUpdate()->findOrFail($id);
    }

    private function parent(TaskBoard $board, ?string $id): ?Task
    {
        if ($id === null) {
            return null;
        }
        $parent = Task::query()->where('board_id', $board->id)->active()->lockForUpdate()->findOrFail($id);
        if ($parent->parent_task_id !== null) {
            throw ValidationException::withMessages(['parent_task_id' => 'A subtask cannot contain another level.']);
        }

        return $parent;
    }

    private function status(TaskBoard $board, string $id): TaskStatusOption
    {
        return TaskStatusOption::query()->where('board_id', $board->id)->active()->findOrFail($id);
    }

    private function priority(TaskBoard $board, ?string $id): ?TaskPriorityOption
    {
        return $id === null ? null : TaskPriorityOption::query()
            ->where('board_id', $board->id)->active()->findOrFail($id);
    }

    private function participant(TaskBoard $board, ?string $id, string $field): ?User
    {
        if ($id === null) {
            return null;
        }
        $user = User::query()->findOrFail($id);
        $decision = $this->participants->forTaskParticipation($user, $board);
        if ($decision->denied()) {
            throw ValidationException::withMessages([$field => (string) $decision->message()]);
        }

        return $user;
    }

    /** @return Collection<int, User> */
    private function collaborators(TaskBoard $board, array $ids): Collection
    {
        $ids = array_values(array_unique($ids));
        $users = User::query()->whereIn('id', $ids)->get();
        if ($users->count() !== count($ids)) {
            throw ValidationException::withMessages(['collaborator_user_ids' => 'One or more collaborators are unavailable.']);
        }
        foreach ($users as $user) {
            $decision = $this->participants->forTaskParticipation($user, $board);
            if ($decision->denied()) {
                throw ValidationException::withMessages(['collaborator_user_ids' => (string) $decision->message()]);
            }
        }

        return $users;
    }

    private function replaceCollaborators(Task $task, User $actor, Collection $users): void
    {
        TaskCollaborator::query()->where('task_id', $task->id)
            ->whereNotIn('user_id', $users->modelKeys())->delete();
        foreach ($users as $user) {
            TaskCollaborator::query()->firstOrCreate([
                'task_id' => $task->id,
                'user_id' => $user->id,
            ], [
                'board_id' => $task->board_id,
                'added_by' => $actor->id,
            ]);
        }
    }

    /** @return list<string> */
    private function applyResolutionState(Task $task, ?StatusCategory $before, StatusCategory $after, User $actor): array
    {
        $events = [];
        if ($after === StatusCategory::Completed && $before !== StatusCategory::Completed) {
            $task->completed_at = now();
            $task->completed_by = $actor->id;
            $task->cancelled_at = null;
            $task->cancelled_by = null;
            $events[] = 'task.completed';
        } elseif ($before === StatusCategory::Completed && $after !== StatusCategory::Completed) {
            $task->completed_at = null;
            $task->completed_by = null;
            if (! in_array($after, [StatusCategory::Completed, StatusCategory::Cancelled], true)) {
                $events[] = 'task.reopened';
            }
        }

        if ($after === StatusCategory::Cancelled) {
            $task->cancelled_at = now();
            $task->cancelled_by = $actor->id;
            $task->completed_at = null;
            $task->completed_by = null;
        } elseif ($before === StatusCategory::Cancelled) {
            $task->cancelled_at = null;
            $task->cancelled_by = null;
        }

        return $events;
    }

    private function nextPosition(TaskBoard $board, ?string $groupId, ?string $parentId): int
    {
        return (int) Task::query()->where('board_id', $board->id)->active()
            ->where('group_id', $groupId)->where('parent_task_id', $parentId)
            ->lockForUpdate()->max('position') + 1;
    }

    private function numberingPlace(TaskBoard $board): mixed
    {
        return $board->franchise()->first()
            ?? $board->company()->first()
            ?? $board->organization()->firstOrFail();
    }

    private function recordMutation(
        Task $task,
        User $actor,
        string $action,
        array $before,
        array $after,
        array $eventKeys,
        ?string $causationId = null,
        array $changedFields = [],
    ): ?AuditEvent {
        $scope = TaskPlace::auditScope($task->board()->firstOrFail());
        $audit = $this->audit->log(
            $action,
            target: $task,
            changes: ['before' => $before, 'after' => $after],
            metadata: ['board_id' => $task->board_id, 'task_id' => $task->id, 'changed_fields' => $changedFields],
            actor: $actor,
            causationId: $causationId,
            scopeType: $scope['type'],
            scopeId: $scope['id'],
        );
        TaskActivity::create([
            'board_id' => $task->board_id,
            'task_id' => $task->id,
            'actor_user_id' => $actor->id,
            'kind' => $action,
            'summary' => match ($action) {
                'task.created' => 'Task created',
                'task.archived' => 'Task archived',
                'task.restored' => 'Task restored',
                'task.moved' => 'Task moved to another group',
                'task.reordered' => 'Task reordered',
                default => 'Task changed',
            },
            'data' => [
                'changed_fields' => $changedFields,
                'causation_id' => $causationId,
                'changes' => $this->durableActivityChanges($before, $after, $changedFields),
            ],
        ]);
        foreach ($eventKeys as $eventKey) {
            $this->events->emit($eventKey, $task, [
                'board_id' => $task->board_id,
                'task_id' => $task->id,
                'owner_user_id' => $task->owner_user_id,
                'status_option_id' => $task->status_option_id,
                'due_on' => $task->due_on?->format('Y-m-d'),
                'due_revision' => (int) $task->due_revision,
                'operation_causation_id' => $causationId,
            ], $actor);
        }

        return $audit;
    }

    private function notify(Task $task, ?AuditEvent $audit, User $actor, array $recipients): void
    {
        if ($audit === null) {
            return;
        }
        foreach ($recipients as $userId => $kind) {
            if ($userId === $actor->id) {
                continue;
            }
            TaskNotificationIntent::query()->firstOrCreate([
                'recipient_user_id' => $userId,
                'kind' => $kind,
                'source_occurrence_key' => $audit->id,
            ], [
                'board_id' => $task->board_id,
                'task_id' => $task->id,
                'available_at' => now(),
            ]);
        }
    }

    private function notifyCollaborators(Task $task, ?AuditEvent $audit, User $actor, array $userIds): void
    {
        $this->notify($task, $audit, $actor, array_fill_keys($userIds, 'task.collaborator_added'));
    }

    private function notifyWatchers(Task $task, ?AuditEvent $audit, User $actor): void
    {
        $recipients = collect([$task->owner_user_id])
            ->concat($task->collaborators()->pluck('user_id'))
            ->concat(TaskWatchPreference::query()->where('task_id', $task->id)
                ->where('preference', 'watched')->pluck('user_id'))
            ->filter()->unique()->values();
        $muted = TaskWatchPreference::query()->where('task_id', $task->id)
            ->where('preference', 'muted')->pluck('user_id');
        $recipients = $recipients->diff($muted)->filter(function (string $userId) use ($task): bool {
            $user = User::query()->find($userId);

            return $user !== null && $this->participants->forTaskParticipation($user, $task->board)->allowed();
        });
        $this->notify($task, $audit, $actor, array_fill_keys($recipients->all(), 'task.changed'));
    }

    private function auditFields(Task $task): array
    {
        return [
            'title' => $task->title,
            'description' => $task->description,
            'group_id' => $task->group_id,
            'parent_task_id' => $task->parent_task_id,
            'position' => (int) $task->position,
            'owner_user_id' => $task->owner_user_id,
            'collaborator_user_ids' => $task->collaborators()->orderBy('user_id')->pluck('user_id')->all(),
            'status_option_id' => $task->status_option_id,
            'priority_option_id' => $task->priority_option_id,
            'start_on' => $task->start_on?->format('Y-m-d'),
            'due_on' => $task->due_on?->format('Y-m-d'),
            'due_revision' => (int) $task->due_revision,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'completed_by' => $task->completed_by,
            'cancelled_at' => $task->cancelled_at?->toIso8601String(),
            'cancelled_by' => $task->cancelled_by,
            'archived_at' => $task->archived_at?->toIso8601String(),
            'archived_with_parent_id' => $task->archived_with_parent_id,
            'version' => (int) $task->version,
        ];
    }

    private function insertionIndex(\Illuminate\Support\Collection $tasks, ?string $beforeId, ?string $afterId): int
    {
        $before = $beforeId === null ? null : $tasks->search(fn (Task $task) => $task->id === $beforeId);
        $after = $afterId === null ? null : $tasks->search(fn (Task $task) => $task->id === $afterId);
        if (($beforeId !== null && $before === false) || ($afterId !== null && $after === false)) {
            throw ValidationException::withMessages(['neighbors' => 'Reorder neighbors must be active tasks in the destination group.']);
        }
        if ($before !== null && $after !== null && $before !== $after + 1) {
            throw ValidationException::withMessages(['neighbors' => 'The submitted reorder neighbors are no longer adjacent.']);
        }

        return $before !== null ? $before : ($after !== null ? $after + 1 : $tasks->count());
    }

    private function temporarilyPosition(\Illuminate\Support\Collection $tasks, int $start): void
    {
        foreach ($tasks as $offset => $task) {
            $task->forceFill(['position' => $start - $offset])->save();
        }
    }

    private function denselyPosition(\Illuminate\Support\Collection $tasks): void
    {
        foreach ($tasks as $position => $task) {
            $task->forceFill(['position' => $position + 1])->save();
        }
    }

    private function compactOrderingSet(TaskBoard $board, ?string $groupId, ?string $parentId): void
    {
        $tasks = Task::query()->where('board_id', $board->id)->active()
            ->where('group_id', $groupId)->where('parent_task_id', $parentId)
            ->orderBy('position')->orderBy('id')->lockForUpdate()->get();
        $this->temporarilyPosition($tasks, -400000);
        $this->denselyPosition($tasks);
    }

    private function durableActivityChanges(array $before, array $after, array $changedFields): array
    {
        $durable = [];
        foreach (array_intersect($changedFields, ['collaborator_user_ids', 'group_id', 'position']) as $field) {
            $durable[$field] = [
                'before' => $before[$field] ?? null,
                'after' => $after[$field] ?? null,
            ];
        }

        return $durable;
    }

    private function relations(): array
    {
        return [
            'board', 'group', 'parent.group', 'owner', 'statusOption', 'priorityOption', 'collaborators.user',
            'subtasks' => fn ($query) => $query->active()->orderBy('position'),
        ];
    }
}
