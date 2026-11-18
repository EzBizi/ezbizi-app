<?php

namespace App\Support\Tasks;

use App\Http\Controllers\Api\TasksController;
use App\Models\Attachment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskAssignee;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Jobs\JobTechnicianSync;
use App\Support\Live\ChangeFeed;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tasks\Monday\MondayOutbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one write path for tasks: the controller and the automation runner both
 * call this, so a rule can do nothing a person could not and both raise the
 * same events. Permission is the CALLER's job (policy / runner).
 *
 * Payloads carry ids and the task's own fields only — never a phone or email.
 * In payloads `status` is the status NAME (built-in and custom alike), with
 * status_id, status_key (tasks.status) and is_done beside it.
 *
 * tasks.status stays the built-in key; a custom status maps to `done` when it
 * is_done and `not_started` otherwise, so Reports keep reading one column.
 */
class TaskWriter
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /**
     * @param  array{title: string, parent_task_id?: ?string, group_id?: ?string, status?: ?string, status_id?: ?string, assignee_user_id?: ?string, due_on?: ?string, about_type?: ?string, about_id?: ?string, priority?: ?string, estimate_minutes?: ?int, values?: array<string, mixed>}  $input
     *
     * `status` is a built-in key or a status name on this board (any case).
     * `group` is a group NAME (any case), an alternative to group_id.
     * `assignee_user_ids` lists every owner, the first the primary
     * (assignee_user_id); assignee_user_id alone still works.
     * `values` may be keyed by column id or by column name (any case).
     */
    public function create(TaskBoard $board, array $input, ?User $actor, ?string $automationId = null): Task
    {
        return DB::transaction(function () use ($board, $input, $actor, $automationId) {
            $parent = null;
            if (! empty($input['parent_task_id'])) {
                $parent = Task::query()->where('board_id', $board->id)->find($input['parent_task_id'])
                    ?? throw ValidationException::withMessages(['parent_task_id' => 'That task is not on this board.']);
                if ($parent->parent_task_id !== null) {
                    throw ValidationException::withMessages(['parent_task_id' => 'A subtask cannot have subtasks of its own.']);
                }
            }

            $owners = $this->ownersFrom($board, $input, []);
            $groupId = $this->groupIdFrom($board, $input);
            $groupId = match (true) {
                $parent !== null => $parent->group_id,
                $groupId !== null => $this->groupOn($board, $groupId)->id,
                default => $board->groups()->value('id'),
            };

            $status = $this->resolveStatus($board, $input)
                ?? $board->statuses()->where('key', Task::STATUS_NOT_STARTED)->first()
                ?? $board->statuses()->first();

            $task = Task::query()->create([
                'organization_id' => $board->organization_id,
                'board_id' => $board->id,
                'parent_task_id' => $parent?->id,
                'group_id' => $groupId,
                'position' => (int) Task::query()->where('group_id', $groupId)->max('position') + 1,
                // A subtask is filed exactly where its parent is.
                'company_id' => $parent ? $parent->company_id : TaskBoards::companyIdOf($board),
                'franchise_id' => $parent ? $parent->franchise_id : $board->franchise_id,
                'title' => $input['title'],
                // A subtask of a sensitive task is sensitive too (SensitiveTasks).
                'sensitive' => (bool) ($input['sensitive'] ?? false) || (bool) $parent?->sensitive,
                'status' => $status?->statusKey() ?? Task::STATUS_NOT_STARTED,
                'status_id' => $status?->id,
                'assignee_user_id' => $input['assignee_user_id'] ?? null,
                'due_on' => $input['due_on'] ?? null,
                'completed_at' => ($status?->is_done ?? false) ? now() : null,
                'about_type' => $input['about_type'] ?? null,
                'about_id' => $input['about_id'] ?? null,
                'priority' => $input['priority'] ?? null,
                'estimate_minutes' => $input['estimate_minutes'] ?? null,
                'created_by_user_id' => $actor?->getKey(),
                'created_by_automation_id' => $automationId,
                // No person and no rule: a system command (desk 11-18), shown as EzBizi.
                'created_by_system' => $actor === null && $automationId === null ? mb_substr((string) ($input['source'] ?? 'system'), 0, 40) : null,
            ]);

            if ($owners !== null) {
                $this->syncOwners($task, $owners, $actor);
            }

            if (! empty($input['values'])) {
                $this->writeValues($task, $input['values'], $actor);
            }

            if ($automationId !== null && $parent === null) {
                $this->linkTwins($task);
            }

            $this->audit->log('task.created', $task, actor: $actor);
            if ($task->external_ref === null) {
                app(MondayOutbox::class)->record($task, ['create']);
            }
            $this->events->emitAfterCommit('task.created', $task, [
                'assignee_user_id' => $task->assignee_user_id,
                'assignee_user_ids' => $this->ownerIds($task),
                'board_id' => $task->board_id,
                'board_name' => $task->board?->name,
                'after' => $this->snapshot($task),
            ]);
            $this->checkRules($task);

            return $task;
        });
    }

    /** Management's board rules, once the write is saved (BoardRules). */
    private function checkRules(Task $task): void
    {
        if (! $this->syncing) {
            DB::afterCommit(fn () => app(BoardRules::class)->check($task));
        }
    }

    /** Only the keys present in $input change. */
    public function update(Task $task, array $input, ?User $actor): Task
    {
        return DB::transaction(function () use ($task, $input, $actor) {
            app(MondayOutbox::class)->record($task, self::mondayKinds($input));
            $seen = $this->readable($task);
            $owners = $this->ownersFrom($task->board, $input, $this->ownerIds($task));
            if (empty($input['group_id']) && isset($input['group'])) {
                $input['group_id'] = $this->groupIdFrom($task->board, $input);
            }
            $fields = array_intersect_key($input, array_flip([
                'title', 'group_id', 'assignee_user_id', 'due_on', 'about_type',
                'about_id', 'priority', 'estimate_minutes',
            ]));

            if (isset($fields['group_id'])) {
                if ($task->parent_task_id !== null && $fields['group_id'] !== $task->group_id) {
                    throw ValidationException::withMessages(['group_id' => 'A subtask stays in its parent\'s group.']);
                }
                $this->groupOn($task->board, $fields['group_id']);
            }

            $before = $this->statusOf($task);
            $task->fill($fields);

            $status = $this->resolveStatus($task->board, $input);
            if ($status !== null && $status->id !== $task->status_id) {
                $this->applyStatus($task, $status, $before['is_done']);
            }
            if ($task->isDirty('due_on')) {
                // A new due date is a new chance to be overdue.
                $task->overdue_announced_at = null;
            }

            if (! empty($input['values']) && is_array($input['values'])) {
                $this->writeValues($task, $input['values'], $actor, announce: true);
            }

            $ownersChanged = $owners !== null && $owners !== $this->ownerIds($task);
            if ($ownersChanged) {
                $this->syncOwners($task, $owners, $actor);
                if (! $task->isDirty()) {
                    $this->audit->log('task.owners_changed', $task, metadata: ['assignee_user_ids' => $owners], actor: $actor);
                }
            }

            if (! $task->isDirty()) {
                // A column value or owner lives in its own table: date the
                // task anyway, so an open board's changes feed sees it.
                if ($ownersChanged || ! empty($input['values'])) {
                    Task::query()->whereKey($task->id)->update(['updated_at' => now()]);
                    ChangeFeed::record($task, 'tasks');
                }
                if ($ownersChanged) {
                    $this->syncLinked($task, [], false, true, $actor);
                }
                $this->recordChanges($task, $seen, $actor);

                return $task;
            }

            $synced = array_keys(array_intersect_key($task->getDirty(), array_flip(self::SYNCED)));
            $statusChanged = $task->isDirty('status_id') || $task->isDirty('status');
            $groupChanged = $task->isDirty('group_id');
            $this->audit->logChange('task.updated', $task, $actor);
            $task->save();

            if ($groupChanged) {
                Task::query()->withTrashed()->where('parent_task_id', $task->id)->update(['group_id' => $task->group_id]);
            }

            if ($statusChanged) {
                $this->events->emitAfterCommit('task.status_changed', $task, [
                    'assignee_user_id' => $task->assignee_user_id,
                    'assignee_user_ids' => $this->ownerIds($task),
                    'board_id' => $task->board_id,
                    'board_name' => $task->board?->name,
                    'before' => $before,
                    'after' => $this->snapshot($task),
                ]);
            }
            $this->syncLinked($task, $synced, $statusChanged, $ownersChanged, $actor);
            $this->recordChanges($task, $seen, $actor);
            if ($statusChanged || $groupChanged || array_key_exists('title', $fields)) {
                $this->checkRules($task);
            }

            return $task;
        });
    }

    /**
     * The fields an update names, as the Monday outbox counts them; it reads
     * the values from the task when it sends.
     *
     * @return list<string>
     */
    private static function mondayKinds(array $input): array
    {
        $kinds = [];
        foreach (['title' => 'title', 'status' => 'status', 'status_id' => 'status', 'due_on' => 'due', 'assignee_user_id' => 'owners',
            'assignee_user_ids' => 'owners', 'group' => 'group', 'group_id' => 'group'] as $key => $kind) {
            if (array_key_exists($key, $input)) {
                $kinds[] = $kind;
            }
        }
        foreach (array_keys((array) ($input['values'] ?? [])) as $column) {
            $kinds[] = 'values:'.$column;
        }

        return array_values(array_unique($kinds));
    }

    /**
     * The task's own fields as the board shows them, for its Activity log.
     *
     * @return array<string, array{0: string, 1: ?string}>
     */
    private function readable(Task $task): array
    {
        $task->unsetRelation('statusRow')->unsetRelation('group');
        $owners = TaskAssignee::query()->where('task_id', $task->id)->orderBy('position')
            ->join('users', 'users.id', '=', 'task_assignees.user_id')->pluck('users.name')->implode(', ');

        return [
            'title' => ['Task', $task->title],
            'status' => ['Status', $task->statusRow?->name ?? $task->status],
            'owner' => ['Owner', $owners !== '' ? $owners : null],
            'due' => ['Due date', $task->due_on?->toDateString()],
            'group' => ['Group', $task->group?->name],
        ];
    }

    /** One Activity row per field that changed since [seen]. */
    private function recordChanges(Task $task, array $seen, ?User $actor): void
    {
        foreach ($this->readable($task) as $field => [$label, $now]) {
            if (($seen[$field][1] ?? null) !== $now) {
                $this->activity($task, $field, $label, $seen[$field][1] ?? null, $now, $actor);
            }
        }
    }

    private function activity(Task $task, string $field, string $label, ?string $from, ?string $to, ?User $actor): void
    {
        TaskActivity::query()->create([
            'organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $actor?->id,
            'field' => mb_substr($field, 0, 64), 'label' => mb_substr($label, 0, 120),
            // A sensitive task's diffs are sealed like its fields (SensitiveTasks).
            'from' => $task->sensitive ? SensitiveTasks::seal($from) : $from,
            'to' => $task->sensitive ? SensitiveTasks::seal($to) : $to,
        ]);
    }

    /**
     * An automation filed the same task on a company board and on one of its
     * branches' (Jose, 2026-10-29: both remain, synced): link it at once to
     * an open twin from the last day, by the key ezbizi:link-duplicate-tasks
     * uses (automation-made, top level, same title any case, same About).
     */
    private function linkTwins(Task $task): void
    {
        $board = $task->board;
        $companyId = TaskBoards::companyIdOf($board);
        if ($companyId === null || ! in_array($board->kind, [TaskBoard::KIND_COMPANY, TaskBoard::KIND_FRANCHISE], true)) {
            return;
        }
        $related = $board->kind === TaskBoard::KIND_COMPANY
            ? TaskBoard::query()->where('kind', TaskBoard::KIND_FRANCHISE)
                ->whereIn('franchise_id', Franchise::query()->where('company_id', $companyId)->select('id'))
            : TaskBoard::query()->where('kind', TaskBoard::KIND_COMPANY)->where('company_id', $companyId);

        Task::query()->whereIn('board_id', $related->select('id'))
            ->whereNull('parent_task_id')->whereNotNull('created_by_automation_id')->whereNull('completed_at')
            ->where('created_at', '>=', now()->subDay())
            ->where('about_type', $task->about_type)->where('about_id', $task->about_id)
            ->get()
            ->filter(fn (Task $t) => mb_strtolower(trim($t->title)) === mb_strtolower(trim($task->title)))
            ->each(fn (Task $t) => TaskLinks::link($task, $t, null));
    }

    /** Fields a linked task keeps the same (Jose, 2026-10-29: "both remain and should be synced"). */
    private const SYNCED = ['title', 'due_on', 'priority', 'estimate_minutes', 'about_type', 'about_id'];

    /** True while a change is being copied across a link: the copies do not copy on. */
    private bool $syncing = false;

    /** The Monday mirror writes quietly: only EzBizi-origin changes ring a bell. */
    public bool $quiet = false;

    /**
     * Copy what changed to every linked task. Status goes by name on the
     * other board, else Done / reopened by its first done status or Not
     * started; owners go when the other board can take them.
     *
     * @param  list<string>  $fields
     */
    private function syncLinked(Task $task, array $fields, bool $statusChanged, bool $ownersChanged, ?User $actor): void
    {
        if ($this->syncing || ($fields === [] && ! $statusChanged && ! $ownersChanged) || $task->parent_task_id !== null) {
            return;
        }
        $this->syncing = true;
        try {
            foreach (TaskLinks::of($task) as $other) {
                if ($other->board === null) {
                    continue;
                }
                $input = [];
                foreach ($fields as $f) {
                    $input[$f] = $f === 'due_on' ? $task->due_on?->toDateString() : $task->getAttribute($f);
                }
                if ($statusChanged && ($target = $this->matchingStatus($task, $other)) !== null) {
                    $input['status_id'] = $target->id;
                }
                $owners = $ownersChanged ? ['assignee_user_ids' => $this->ownerIds($task)] : [];
                if ($input === [] && $owners === []) {
                    continue;
                }
                try {
                    $this->update($other, $input + $owners, $actor);
                } catch (ValidationException) {
                    // An owner the other board cannot take: the rest still goes.
                    if ($owners !== [] && $input !== []) {
                        $this->update($other, $input, $actor);
                    }
                }
            }
        } finally {
            $this->syncing = false;
        }
    }

    private function matchingStatus(Task $task, Task $other): ?TaskStatus
    {
        $statuses = $other->board->statuses()->get();
        $name = mb_strtolower(trim((string) $task->statusRow?->name));
        $same = $statuses->first(fn (TaskStatus $s) => mb_strtolower(trim($s->name)) === $name);
        if ($same !== null) {
            return $same->id === $other->status_id ? null : $same;
        }
        $done = $this->statusOf($task)['is_done'];
        if ($this->statusOf($other)['is_done'] === $done) {
            return null;
        }

        return $done
            ? $statuses->firstWhere('is_done', true)
            : ($statuses->first(fn (TaskStatus $s) => ! $s->is_done && $s->key === Task::STATUS_NOT_STARTED)
                ?? $statuses->firstWhere('is_done', false));
    }

    /**
     * Move a top-level task (and its subtasks) to a group, on this board or
     * another, at a 0-based position among that group's top-level tasks; the
     * group is renumbered. Across boards the task is refiled to the new place
     * and takes the new board's status with the same key (else Not started).
     * The caller has checked write on both boards.
     */
    public function move(Task $task, TaskGroup $group, ?int $position, ?User $actor): Task
    {
        return DB::transaction(function () use ($task, $group, $position, $actor) {
            if ($task->parent_task_id !== null) {
                throw ValidationException::withMessages(['task' => 'A subtask moves with its parent.']);
            }
            $board = $group->board;
            $fields = ['group_id' => $group->id];
            if ($board->id !== $task->board_id) {
                $statuses = $board->statuses()->get();
                $status = $statuses->firstWhere('key', $task->status) ?? $statuses->firstWhere('key', Task::STATUS_NOT_STARTED) ?? $statuses->first();
                $fields += [
                    'board_id' => $board->id,
                    'company_id' => TaskBoards::companyIdOf($board),
                    'franchise_id' => $board->franchise_id,
                    'status_id' => $status?->id,
                    'status' => $status?->statusKey() ?? $task->status,
                ];
            }

            $ids = Task::query()->where('group_id', $group->id)->whereNull('parent_task_id')
                ->whereKeyNot($task->id)->orderBy('position')->orderBy('created_at')->pluck('id')->all();
            $at = $position === null ? count($ids) : max(0, min($position, count($ids)));
            array_splice($ids, $at, 0, [$task->id]);

            $task->fill($fields);
            $this->audit->logChange('task.moved', $task, $actor);
            $task->save();
            Task::query()->withTrashed()->where('parent_task_id', $task->id)
                ->update(array_intersect_key($fields, array_flip(['group_id', 'board_id', 'company_id', 'franchise_id'])));
            foreach ($ids as $i => $id) {
                Task::query()->whereKey($id)->update(['position' => $i]);
            }
            app(MondayOutbox::class)->record($task, ['group']);
            $this->checkRules($task);

            return $task->refresh();
        });
    }

    /** A new task like this one, on $group's board: title, dates, About, owners; its columns' values when the board is the same. */
    public function copy(Task $task, TaskGroup $group, ?User $actor): Task
    {
        $sameBoard = $group->board_id === $task->board_id;
        $input = [
            'title' => $task->title,
            'group_id' => $group->id,
            'due_on' => $task->due_on?->toDateString(),
            'about_type' => $task->about_type,
            'about_id' => $task->about_id,
            'priority' => $task->priority,
            'estimate_minutes' => $task->estimate_minutes,
            'sensitive' => (bool) $task->sensitive,
        ];
        if ($sameBoard) {
            $input['status_id'] = $task->status_id;
            $input['values'] = $task->values()->with('column')->get()->mapWithKeys(fn (TaskColumnValue $v) => [$v->column_id => TasksController::valueOf($v)])->filter(fn ($v) => $v !== null)->all();
            $input['assignee_user_ids'] = $this->ownerIds($task);
        }

        return $this->create($group->board, $input, $actor);
    }

    /** Undo a delete: the task and the subtasks that went with it. */
    public function restore(Task $task, ?User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor) {
            $at = $task->deleted_at;
            // Subtasks go a moment before their parent (delete()), so a second
            // can tick between them: take the ones deleted in the 5 s up to it.
            Task::onlyTrashed()->where('parent_task_id', $task->id)
                ->whereBetween('deleted_at', [$at->copy()->subSeconds(5), $at])->get()
                ->each(fn (Task $c) => $c->restore());
            $task->restore();
            $this->audit->log('task.restored', $task, actor: $actor);

            return $task;
        });
    }

    /** Deleting a parent takes its subtasks with it (soft, like the parent). */
    public function delete(Task $task, ?User $actor): void
    {
        DB::transaction(function () use ($task, $actor) {
            foreach ($task->subtasks as $child) {
                $this->audit->log('task.deleted', $child, actor: $actor);
                $child->delete();
            }
            $this->audit->log('task.deleted', $task, actor: $actor);
            app(MondayOutbox::class)->record($task, ['delete']); // archived on Monday; its subitems go with it
            $task->delete();
        });
    }

    public function comment(Task $task, User $author, string $body, array $mentions = []): TaskUpdate
    {
        return DB::transaction(function () use ($task, $author, $body, $mentions) {
            $update = TaskUpdate::query()->create([
                'organization_id' => $task->organization_id,
                'task_id' => $task->id,
                'user_id' => $author->getKey(),
                'body' => $task->sensitive ? SensitiveTasks::seal($body) : $body,
                'mentions' => $mentions ?: null,
            ]);
            $this->audit->log('task.update_posted', $task, actor: $author);
            app(MondayOutbox::class)->record($task, [], $update);

            return $update;
        });
    }

    /**
     * A status's is_done was edited: every task on it (deleted ones too) gets
     * tasks.status and completed_at re-derived.
     */
    public function rederive(TaskStatus $status): void
    {
        $tasks = Task::query()->withTrashed()->where('status_id', $status->id);
        $tasks->clone()->update(['status' => $status->statusKey()]);
        $status->is_done
            ? $tasks->clone()->whereNull('completed_at')->update(['completed_at' => now()])
            : $tasks->clone()->update(['completed_at' => null]);
    }

    /** Before a status is deleted: its tasks move to $to (live ones through update, so events fire). */
    public function moveStatus(TaskStatus $from, TaskStatus $to, ?User $actor): void
    {
        Task::query()->where('status_id', $from->id)->get()
            ->each(fn (Task $t) => $this->update($t, ['status_id' => $to->id], $actor));
        $wasDone = $from->is_done;
        $trashed = Task::onlyTrashed()->where('status_id', $from->id);
        $trashed->clone()->update(['status_id' => $to->id, 'status' => $to->statusKey()]
            + ($to->is_done === $wasDone ? [] : ['completed_at' => $to->is_done ? now() : null]));
    }

    /** group_id as given, or the id of the group named by `group` (any case); null when neither. */
    private function groupIdFrom(TaskBoard $board, array $input): ?string
    {
        if (! empty($input['group_id'])) {
            return (string) $input['group_id'];
        }
        if (! isset($input['group']) || ! is_string($input['group']) || trim($input['group']) === '') {
            return null;
        }
        $wanted = mb_strtolower(trim($input['group']));

        return TaskGroup::query()->where('board_id', $board->id)->get()
            ->first(fn (TaskGroup $g) => mb_strtolower(trim($g->name)) === $wanted)?->id
            ?? throw ValidationException::withMessages(['group' => "This board has no group called {$input['group']}."]);
    }

    /**
     * The owners $input asks for, primary first, or null when it names none.
     * Sets $input['assignee_user_id'] to the primary. A lone
     * assignee_user_id replaces the primary and keeps the other owners.
     *
     * @param  list<string>  $current
     * @return list<string>|null
     */
    private function ownersFrom(TaskBoard $board, array &$input, array $current): ?array
    {
        if (array_key_exists('assignee_user_ids', $input)) {
            $ids = $input['assignee_user_ids'] ?? [];
            if (! is_array($ids) || count($ids) > 50) {
                throw ValidationException::withMessages(['assignee_user_ids' => 'A list of up to 50 people.']);
            }
            $assignable = app(TaskBoards::class)->assignable($board)->pluck('name', 'id');
            $out = [];
            foreach (array_values($ids) as $i => $id) {
                if (! is_string($id) || ! $assignable->has($id)) {
                    $name = is_string($id) ? User::query()->whereKey($id)->value('name') : null;
                    throw ValidationException::withMessages(["assignee_user_ids.{$i}" => $name !== null
                        ? ($board->isPrivate() ? "Add {$name} to this board first." : "{$name} cannot own a task on this board.")
                        : 'That person is not in this organization.']);
                }
                $out[$id] = true;
            }
            $out = array_keys($out);
            $input['assignee_user_id'] = $out[0] ?? null;

            return $out;
        }

        if (array_key_exists('assignee_user_id', $input)) {
            $primary = $input['assignee_user_id'];

            return $primary === null || $primary === ''
                ? []
                : array_values(array_unique([(string) $primary, ...array_slice($current, 1)]));
        }

        return null;
    }

    /** @return list<string> every owner, primary first */
    private function ownerIds(Task $task): array
    {
        if (! $task->exists) {
            return [];
        }

        return TaskAssignee::query()->where('task_id', $task->id)->orderBy('position')->pluck('user_id')->all();
    }

    /** @param  list<string>  $ids */
    private function syncOwners(Task $task, array $ids, ?User $actor = null): void
    {
        $had = TaskAssignee::query()->where('task_id', $task->id)->pluck('user_id')->all();
        TaskAssignee::query()->where('task_id', $task->id)->whereNotIn('user_id', $ids)->delete();
        foreach ($ids as $position => $id) {
            TaskAssignee::query()->updateOrCreate(
                ['task_id' => $task->id, 'user_id' => $id],
                ['organization_id' => $task->organization_id, 'position' => $position],
            );
        }
        $task->unsetRelation('owners');

        // Jose 11-17: whoever is newly given a task hears about it, whether a
        // person or a rule gave it; not the person who gave it to themselves,
        // and not again for a linked twin.
        $new = array_values(array_diff($ids, $had, [$actor?->getKey()]));
        if ($new !== [] && ! $this->quiet && ! $this->syncing) {
            DB::afterCommit(fn () => self::tellAssigned($task, $new));
        }
    }

    /** @param  string[]  $userIds */
    public static function tellAssigned(Task $task, array $userIds): int
    {
        // After commit a command may have left its tenant: tell in the task's.
        $org = Organization::query()->withoutGlobalScopes()->find($task->organization_id);

        return $org === null ? 0 : app(TenantContext::class)->run($org, function () use ($task, $userIds) {
            $told = 0;
            foreach (User::query()->whereKey($userIds)->get() as $user) {
                $told += app(NotificationDispatcher::class)->dispatch(
                    title: 'New task: '.$task->title,
                    body: 'On '.($task->board?->name ?? 'the Task Board'),
                    subject: $task, onlyTo: $user, kind: 'task.assigned',
                );
            }

            return $told;
        });
    }

    private function groupOn(TaskBoard $board, string $groupId): TaskGroup
    {
        return TaskGroup::query()->where('board_id', $board->id)->find($groupId)
            ?? throw ValidationException::withMessages(['group_id' => 'That group is not on this board.']);
    }

    /** The status named in $input (status_id, or `status` as a key or a name), or null when neither is given. */
    private function resolveStatus(TaskBoard $board, array $input): ?TaskStatus
    {
        if (! empty($input['status_id'])) {
            $own = TaskStatus::query()->where('board_id', $board->id)->find($input['status_id']);
            if ($own !== null) {
                return $own;
            }
            // A branch task shown on its company board (roll-up) is sent the
            // company board's status: take the task's board's of the same name.
            $other = TaskStatus::query()->find($input['status_id']);
            $wanted = $other ? mb_strtolower(trim($other->name)) : null;

            return ($wanted === null ? null : TaskStatus::query()->where('board_id', $board->id)->get()
                ->first(fn (TaskStatus $s) => mb_strtolower(trim($s->name)) === $wanted))
                ?? throw ValidationException::withMessages(['status_id' => $other
                    ? "The board this task is on has no status called {$other->name}."
                    : 'That status is not on this board.']);
        }

        if (! isset($input['status']) || $input['status'] === '') {
            return null;
        }

        $wanted = (string) $input['status'];

        return TaskStatus::query()->where('board_id', $board->id)->where('key', $wanted)->first()
            ?? TaskStatus::query()->where('board_id', $board->id)->get()
                ->first(fn (TaskStatus $s) => mb_strtolower($s->name) === mb_strtolower($wanted))
            ?? throw ValidationException::withMessages(['status' => "This board has no status called {$wanted}."]);
    }

    private function applyStatus(Task $task, TaskStatus $status, bool $wasDone): void
    {
        $task->status_id = $status->id;
        $task->status = $status->statusKey();
        $task->setRelation('statusRow', $status);

        if ($status->is_done && ! $wasDone) {
            $task->completed_at = now();
        } elseif (! $status->is_done) {
            $task->completed_at = null;
        }
    }

    /** @return array{status: string, status_id: ?string, status_name: string, status_key: string, is_done: bool} */
    private function statusOf(Task $task): array
    {
        $row = $task->status_id ? $task->statusRow : null;
        $name = $row?->name ?? (Task::STATUSES[$task->status] ?? (string) $task->status);

        return [
            'status' => $name,
            'status_id' => $task->status_id,
            'status_name' => $name,
            'status_key' => (string) $task->status,
            'is_done' => $row?->is_done ?? $task->status === Task::STATUS_DONE,
        ];
    }

    /**
     * column_id => value; null clears. Each value is checked against its
     * column's type before anything is written.
     *
     * @param  array<string, mixed>  $values
     */
    private function writeValues(Task $task, array $values, ?User $actor, bool $announce = false): void
    {
        $columns = TaskColumn::query()->where('board_id', $task->board_id)->get()->keyBy('id');
        $assignable = null;
        $rows = [];

        foreach ($values as $columnId => $value) {
            $key = "values.{$columnId}";
            $column = $columns->get($columnId)
                ?? $columns->first(fn (TaskColumn $c) => mb_strtolower(trim($c->name)) === mb_strtolower(trim((string) $columnId)))
                ?? throw ValidationException::withMessages([$key => "This board has no column called {$columnId}."]);

            if ($value === null) {
                $rows[$column->id] = null;

                continue;
            }

            [$row, $why] = $this->encode($column, $value, $task->board, $assignable);
            $ok = $row !== null;
            // A card's Technician is its job's (Jose, 2026-10-22): the job takes it first, or nothing is saved.
            if ($ok && $column->type === 'person' && JobTechnicianSync::isTechnicianColumn($column)) {
                JobTechnicianSync::toJob($task, $value, $key);
            }

            if (! $ok) {
                throw ValidationException::withMessages([$key => $why]);
            }
            if ($task->sensitive && SensitiveTasks::sealsColumn($column->type)) {
                $row['value_text'] = SensitiveTasks::seal($row['value_text']);
            }
            $rows[$column->id] = $row;
        }

        $was = TaskColumnValue::query()->with('column')->where('task_id', $task->id)
            ->whereIn('column_id', array_keys($rows))->get()->keyBy('column_id');

        foreach ($rows as $columnId => $row) {
            $row === null
                ? TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $columnId)->delete()
                : TaskColumnValue::query()->updateOrCreate(
                    ['task_id' => $task->id, 'column_id' => $columnId],
                    ['organization_id' => $task->organization_id] + $row,
                );
        }

        // Ids only: a text value can hold anything somebody typed. The words
        // go to the task's own Activity log, which erasure reaches.
        $this->audit->log('task.values_changed', $task, metadata: ['column_ids' => array_keys($rows)], actor: $actor);
        $now = TaskColumnValue::query()->with('column')->where('task_id', $task->id)
            ->whereIn('column_id', array_keys($rows))->get()->keyBy('column_id');
        foreach (array_keys($rows) as $columnId) {
            $from = $was->has($columnId) ? ColumnConverter::words($columns[$columnId], $was[$columnId]) : null;
            $to = $now->has($columnId) ? ColumnConverter::words($columns[$columnId], $now[$columnId]) : null;
            if ($from !== $to) {
                $this->activity($task, $columnId, $columns[$columnId]->name, $from, $to, $actor);
                if ($announce) {
                    // Monday's "when Area changes to Connecticut" (parity audit 2026-11-13):
                    // one event per column that changed, its value as a person reads it.
                    $payload = [
                        'assignee_user_id' => $task->assignee_user_id,
                        'assignee_user_ids' => $this->ownerIds($task),
                        'board_id' => $task->board_id,
                        'board_name' => $task->board?->name,
                        'before' => ['value' => $from],
                        'after' => $this->snapshot($task) + ['column_id' => $columnId, 'column' => $columns[$columnId]->name, 'value' => $to],
                    ];
                    // In the task's own organization: a command's outer transaction can commit after its tenant run ended.
                    DB::afterCommit(fn () => app(TenantContext::class)->run(
                        Organization::query()->withoutGlobalScopes()->findOrFail($task->organization_id),
                        // emitAfterCommit, not emit: with no transaction open it emits now, and an
                        // import's silenced emitter (MondayImport) still silences it.
                        fn () => $this->events->emitAfterCommit('task.column_changed', $task, $payload),
                    ));
                }
            }
        }
    }

    /**
     * One value as its stored row for [column], or null with why not. The
     * one place a type's rule lives: writing a task and converting a column
     * to another type both come here.
     *
     * @param  list<string>|null  $assignable  filled on first use of a person column
     * @return array{0: ?array<string, mixed>, 1: string}
     */
    public function encode(TaskColumn $column, mixed $value, TaskBoard $board, ?array &$assignable = null): array
    {
        $row = ['value_text' => null, 'value_number' => null, 'value_date' => null, 'value_user_id' => null];
        $ok = true;
        $why = '';
        $string = fn (int $max) => is_string($value) && trim($value) !== '' && mb_strlen($value) <= $max;

        switch ($column->type) {
            case 'text':
                $ok = is_string($value) && mb_strlen($value) <= 5000;
                $why = 'Text, up to 5000 characters.';
                $row['value_text'] = $value;
                break;
            case 'long_text':
                $ok = is_string($value) && mb_strlen($value) <= 20000;
                $why = 'Text, up to 20000 characters.';
                $row['value_text'] = $value;
                break;
            case 'phone':
                $digits = is_string($value) ? preg_replace('/\D/', '', $value) : '';
                $ok = $string(40) && strlen($digits) >= 7 && strlen($digits) <= 15 && preg_match('/^[\d\s()+.\-]+$/', $value);
                $why = 'A phone number, 7 to 15 digits.';
                $row['value_text'] = $ok ? trim($value) : null;
                break;
            case 'email':
                $ok = $string(254) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) !== false;
                $why = 'An email address.';
                $row['value_text'] = $ok ? trim($value) : null;
                break;
            case 'url':
                $ok = $string(2000) && filter_var(trim($value), FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', trim($value));
                $why = 'A web address starting http:// or https://.';
                $row['value_text'] = $ok ? trim($value) : null;
                break;
            case 'datetime':
                $ok = is_string($value) && (\DateTime::createFromFormat('!Y-m-d H:i', $value) ?: null)?->format('Y-m-d H:i') === $value;
                $why = 'A date and time as YYYY-MM-DD HH:MM.';
                $row['value_text'] = $value;
                break;
            case 'tags':
                $ids = array_column($column->options(), 'id');
                $ok = is_array($value) && array_is_list($value) && $value !== []
                    && array_diff($value, $ids) === [] && count($value) === count(array_unique($value));
                $why = 'A list of this column\'s options.';
                $row['value_text'] = $ok ? json_encode(array_values($value)) : null;
                break;
            case 'link':
                $ok = is_string($value) && self::linkOf($value) !== null;
                $why = 'A customer or job, as customer:<id> or job:<id>.';
                $row['value_text'] = $value;
                break;
            case 'number':
            case 'currency':
                $ok = (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)))
                    && abs((float) $value) < 1e11;
                $why = 'This column takes a number.';
                $row['value_number'] = $ok ? (float) $value : null;
                break;
            case 'date':
                $ok = is_string($value) && (\DateTime::createFromFormat('!Y-m-d', $value) ?: null)?->format('Y-m-d') === $value;
                $why = 'A date as YYYY-MM-DD.';
                $row['value_date'] = $value;
                break;
            case 'person':
                $assignable ??= app(TaskBoards::class)->assignable($board)->pluck('id')->all();
                $ok = in_array($value, $assignable, true);
                $why = 'That person cannot be named on this board.';
                $row['value_user_id'] = $value;
                break;
            case 'people':
                // Several people (Monday's People column): a list of user ids.
                $assignable ??= app(TaskBoards::class)->assignable($board)->pluck('id')->all();
                $ids = is_array($value) && array_is_list($value) ? array_values(array_unique($value)) : null;
                $ok = $ids !== null && $ids !== [] && count($ids) <= 50 && array_diff($ids, $assignable) === [];
                $why = 'A list of people who can be named on this board.';
                $row['value_text'] = $ok ? json_encode($ids) : null;
                break;
            case 'timeline':
                $day = fn ($d) => is_string($d) && (\DateTime::createFromFormat('!Y-m-d', $d) ?: null)?->format('Y-m-d') === $d;
                $ok = is_array($value) && $day($value['from'] ?? null) && $day($value['to'] ?? null) && $value['from'] <= $value['to'];
                $why = 'A range as {from, to}, YYYY-MM-DD, from no later than to.';
                $row['value_date'] = $ok ? $value['from'] : null;
                $row['value_text'] = $ok ? $value['to'] : null;
                break;
            case 'location':
                // {address, lat|null, lng|null}
                $lat = $value['lat'] ?? null;
                $lng = $value['lng'] ?? null;
                $ok = is_array($value) && is_string($value['address'] ?? null) && trim($value['address']) !== ''
                    && mb_strlen($value['address']) <= 500
                    && ($lat === null || (is_numeric($lat) && abs((float) $lat) <= 90))
                    && ($lng === null || (is_numeric($lng) && abs((float) $lng) <= 180));
                $why = 'A place as {address, lat, lng}; lat and lng may be empty.';
                $row['value_text'] = $ok ? json_encode(['address' => trim($value['address']),
                    'lat' => $lat === null ? null : (float) $lat, 'lng' => $lng === null ? null : (float) $lng]) : null;
                break;
            case 'rating':
                $max = (int) ($column->settings['max'] ?? 5);
                $ok = is_int($value) || (is_string($value) && ctype_digit($value));
                $ok = $ok && (int) $value >= 0 && (int) $value <= $max;
                $why = "A whole number from 0 to {$max}.";
                $row['value_number'] = $ok ? (int) $value : null;
                break;
            case 'files':
                // Files already on the task (its attachments), by id.
                $ids = is_array($value) && array_is_list($value) ? array_values(array_unique(array_map('strval', $value))) : null;
                $ok = $ids !== null && $ids !== [] && count($ids) <= 50
                    && Attachment::query()->whereIn('id', $ids)->count() === count($ids);
                $why = 'A list of files added to this task.';
                $row['value_text'] = $ok ? json_encode($ids) : null;
                break;
            case 'connect':
            case 'dependency':
                // Other tasks: anywhere in the organization (connect) or on this board (dependency).
                $ids = is_array($value) && array_is_list($value) ? array_values(array_unique(array_map('strval', $value))) : null;
                $found = $ids === null ? 0 : Task::query()->whereIn('id', $ids)
                    ->when($column->type === 'dependency', fn ($q) => $q->where('board_id', $board->id))->count();
                $ok = $ids !== null && $ids !== [] && count($ids) <= 50 && $found === count($ids);
                $why = $column->type === 'dependency' ? 'A list of tasks on this board.' : 'A list of tasks.';
                $row['value_text'] = $ok ? json_encode($ids) : null;
                break;
            case 'last_updated':
            case 'creation_log':
            case 'mirror':
                // Read from the task (or the tasks it is connected to); nothing to store.
                $ok = false;
                $why = 'This column fills itself in.';
                break;
            case 'formula':
                // Worked out from the other columns where it is shown; nothing to store.
                $ok = false;
                $why = 'A formula column works itself out.';
                break;
            case 'checkbox':
                $ok = in_array($value, [true, false, 0, 1, '0', '1'], true);
                $why = 'This column takes true or false.';
                $row['value_number'] = $ok && (bool) $value ? 1 : 0;
                break;
            default: // status, dropdown: the option's id
                $ok = is_string($value) && in_array($value, array_column($column->options(), 'id'), true);
                $why = 'That is not one of this column\'s options.';
                $row['value_text'] = $value;
        }

        return [$ok ? $row : null, $why];
    }

    /**
     * A link value as {type, id, label}, or null when it names nothing in this
     * organization. ponytail: one query per linked value; batch if boards grow.
     *
     * @return array{type: string, id: string, label: string}|null
     */
    public static function linkOf(?string $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^(customer|job):([0-9a-f\-]{36})$/i', $value, $m)) {
            return null;
        }
        $model = $m[1] === 'customer' ? Customer::query()->find($m[2]) : Job::query()->with('customer:id,name')->find($m[2]);
        if ($model === null) {
            return null;
        }

        return ['type' => $m[1], 'id' => $model->id, 'label' => $m[1] === 'customer'
            ? (string) $model->name
            : trim($model->number.' · '.($model->customer?->name ?? ''), ' ·')];
    }

    private function snapshot(Task $task): array
    {
        return [
            'board_id' => $task->board_id,
            'parent_task_id' => $task->parent_task_id,
            'title' => $task->title,
            ...$this->statusOf($task),
            'assignee_user_id' => $task->assignee_user_id,
            'assignee_user_ids' => $this->ownerIds($task),
            'created_by_user_id' => $task->created_by_user_id,
            'group_id' => $task->group_id,
            'due_on' => $task->due_on?->toDateString(),
            'about_type' => $task->about_type,
            'about_id' => $task->about_id,
        ];
    }
}
