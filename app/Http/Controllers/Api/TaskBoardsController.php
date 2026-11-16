<?php

namespace App\Http\Controllers\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskLink;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Scoping\OwnedScope;
use App\Support\Tasks\TaskBoards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Task Board: the boards in the caller's current view, one board's contents,
 * and (inbox 0120) custom boards with monday-style members.
 */
class TaskBoardsController extends Controller
{
    private const ORDER = ['user', 'franchise', 'company', 'custom'];

    public function __construct(private readonly TaskBoards $boards) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->boards->ensureFor($user);

        // A personal board whose owner is deactivated or erased is archived
        // (Jose, 2026-11-09: "Removed accounts should not show up on boards").
        // Derived from the owner, never stored, so it cannot drift; an
        // Administrator opens them with ?archived=1, each with its open tasks
        // to hand over.
        // Anyone sees the boards they could see that were archived; only an
        // Administrator sees the personal boards of people who left.
        $archived = $request->boolean('archived');
        $ownerGone = fn ($q) => $q->where(fn ($q) => $q->whereNotNull('archived_at')
            ->orWhere(fn ($q) => $q->where('kind', TaskBoard::KIND_USER)
                ->whereHas('owner', fn ($o) => $o->where('is_active', false))));

        // A board of a switched-off company or place, or one filed on either,
        // is not listed (Jose, 2026-11-10: "on task board, switched off
        // companies should not show up"). Its rows and tasks stay.
        $switchedOn = fn ($q) => $q->whereDoesntHave('company', fn ($c) => $c->switchedOff())
            ->whereDoesntHave('franchise', fn ($f) => $f->switchedOff());

        $boards = $this->boards->visibleTo($user)->where($switchedOn)
            ->when($archived, $ownerGone, fn ($q) => $q->whereNot($ownerGone))
            ->when($archived && ! $user->isAdministrator(), fn ($q) => $q->whereNotNull('archived_at'))
            ->withCount(['tasks as open_for_me' => fn ($q) => $q
                ->where('assignee_user_id', $user->getKey())
                ->where('status', '!=', Task::STATUS_DONE)])
            ->when($archived, fn ($q) => $q->withCount(['tasks as open_tasks' => fn ($t) => $t
                ->where('status', '!=', Task::STATUS_DONE)]))
            ->with([
                'company:id,name',
                'franchise' => fn ($q) => $q->withTrashed()->with('company:id,name'),
                'owner:id,name',
                'members' => fn ($q) => $q->with('user:id,name'),
            ])
            ->get()
            ->sortBy(fn (TaskBoard $b) => [array_search($b->kind, self::ORDER, true), $b->name])
            ->values();

        return response()->json(['data' => $boards->map(function (TaskBoard $b) use ($user, $archived) {
            $owner = $b->kind === TaskBoard::KIND_USER
                ? $b->owner
                : $b->members->firstWhere('role', TaskBoardMember::OWNER)?->user;

            $role = $b->kind === TaskBoard::KIND_USER && $b->owner_user_id === $user->getKey()
                ? TaskBoardMember::OWNER
                : ($b->members->firstWhere('user_id', $user->getKey())?->role ?? $this->boards->roleOf($user, $b));

            return [
                'id' => $b->id,
                'kind' => $b->kind,
                'name' => $b->name,
                'visibility' => $b->visibility,
                // Franchise names repeat across companies: the client groups by these.
                'company_id' => ($b->company ?? $b->franchise?->company)?->id,
                'company_name' => ($b->company ?? $b->franchise?->company)?->name,
                'franchise_id' => $b->franchise_id,
                'folder_id' => $b->folder_id,
                'colour' => $b->colour,
                'position' => $b->position,
                'open_for_me' => (int) $b->open_for_me,
                'can_edit' => $this->boards->canEdit($user, $b),
                'my_role' => $role,
                'can_manage' => $this->boards->canManageMembers($user, $b),
                'section' => $this->boards->sectionOf($user, $b, $role),
                'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
                ...($archived ? ['archived' => true, 'open_tasks' => (int) $b->open_tasks] : []),
            ];
        })->concat($archived ? $this->deleted($user) : [])->all(), 'meta' => [
            // The Archived list's switch, with how many wait there.
            'archived_count' => $archived ? null : $this->boards->visibleTo($user)->where($switchedOn)->where($ownerGone)
                ->when(! $user->isAdministrator(), fn ($q) => $q->whereNotNull('archived_at'))->count()
                + $this->deleted($user)->count(),
        ]]);
    }

    /**
     * Boards deleted in the last 30 days that this person could restore,
     * listed under Archived with the day they go for good.
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function deleted(User $user): \Illuminate\Support\Collection
    {
        return $this->boards->visibleTo($user)->onlyTrashed()
            ->where('deleted_at', '>=', now()->subDays(\App\Console\Commands\PurgeDeletedTaskBoards::DAYS))
            ->get()->filter(fn (TaskBoard $b) => $this->canRestore($user, $b))
            ->map(fn (TaskBoard $b) => ['id' => $b->id, 'kind' => $b->kind, 'name' => $b->name, 'archived' => true,
                'deleted' => true, 'open_tasks' => 0, 'section' => null, 'owner' => null, 'can_edit' => false,
                'restore_until' => $b->deleted_at->copy()->addDays(\App\Console\Commands\PurgeDeletedTaskBoards::DAYS)->toDateString()])
            ->values();
    }

    /** POST task-boards/{id}/archive: out of the list, reversibly. Automatic boards too. */
    public function archive(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorizeArchive($request->user(), $taskBoard);
        $taskBoard->forceFill(['archived_at' => now()])->save();

        return response()->json(['data' => ['id' => $taskBoard->id, 'archived' => true]]);
    }

    public function unarchive(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorizeArchive($request->user(), $taskBoard);
        $taskBoard->forceFill(['archived_at' => null])->save();

        return response()->json(['data' => ['id' => $taskBoard->id, 'archived' => false]]);
    }

    /** POST task-boards/{id}/restore: a deleted board back, within 30 days. */
    public function restore(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $board = $this->boards->visibleTo($user)->onlyTrashed()->findOrFail($id);
        abort_unless($this->canRestore($user, $board), 403, 'Only an owner of this board or an Administrator can do that.');
        abort_if($board->deleted_at->lt(now()->subDays(\App\Console\Commands\PurgeDeletedTaskBoards::DAYS)), 404);
        $board->restore();

        return response()->json(['data' => ['id' => $board->id]]);
    }

    /** canManage without its canView, which a deleted board no longer passes. */
    private function canRestore(User $user, TaskBoard $board): bool
    {
        return $user->isAdministrator() || $this->boards->roleOf($user, $board) === TaskBoardMember::OWNER;
    }

    private function authorizeArchive(User $user, TaskBoard $board): void
    {
        $this->authorize('view', $board);
        abort_unless($this->boards->canManage($user, $board), 403, 'Only an owner of this board or an Administrator can do that.');
    }

    public function show(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        $user = $request->user();
        // Taken before the read: GET changes?since=<this> picks up from here.
        $cursor = now()->startOfSecond()->toIso8601String();

        // An API key (MCP) or the AI is never shown a sensitive task, not even its title.
        $hidden = \App\Support\Tasks\SensitiveTasks::machineReader($user);
        // ?per_group=N: the first N top-level tasks of each group (and their
        // subtasks), with each group's full count and footers. A 24,713-task
        // board was a 20 MB reply (Jose, 2026-11-16). Absent: every task.
        $perGroup = min(max($request->integer('per_group'), 0), 500);
        $groups = null;
        if ($perGroup > 0) {
            $groups = $this->groupPages($taskBoard, $hidden, $perGroup);
            $pick = collect($groups)->flatMap(fn (array $g) => $g['ids'])->all();
            $tasks = $this->ownTasks($taskBoard, $hidden)
                ->where(fn ($q) => $q->whereIn('id', $pick)->orWhereIn('parent_task_id', $pick))
                ->get();
        } else {
            $tasks = $this->ownTasks($taskBoard, $hidden)->get();
        }

        // A company board carries its branches' tasks as the same rows, each
        // marked with the board it lives on (Jose, 2026-10-28).
        $sources = $this->boards->rolledUpBoards($user, $taskBoard)->keyBy('id');
        $rolled = $sources->isEmpty() ? collect() : Task::query()
            ->whereIn('board_id', $sources->keys())
            ->when($hidden, fn ($q) => $q->where('sensitive', false))
            // Linked to a task this board already holds: that row is the one shown.
            ->whereNotIn('id', TaskLink::query()->whereIn('linked_task_id', $taskBoard->tasks()->select('id'))->select('task_id'))
            ->whereNotIn('id', TaskLink::query()->whereIn('task_id', $taskBoard->tasks()->select('id'))->select('linked_task_id'))
            ->with([...TasksController::WITH, 'group:id,name'])
            ->withCount(TasksController::counts())
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        $unread = \App\Support\Notes\NoteActivity::unreadCounts('task', 'task_updates', 'task_id',
            $tasks->concat($rolled)->map(fn (Task $t) => (string) $t->id)->all(), (string) $user->id);
        $present = fn (Task $t) => TasksController::present($t) + ['unread_notes' => $unread[(string) $t->id] ?? 0];
        $assignable = $this->boards->assignable($taskBoard);
        $withPhoto = \App\Models\User::query()->whereIn('id', $assignable->pluck('id'))
            ->whereNotNull('avatar_path')->pluck('id')->mapWithKeys(fn ($id) => [(string) $id => true])->all();

        return response()->json(['data' => [
            'id' => $taskBoard->id,
            'cursor' => $cursor,
            'kind' => $taskBoard->kind,
            'name' => $taskBoard->name,
            'visibility' => $taskBoard->visibility,
            'edit_mode' => $taskBoard->edit_mode ?? 'everyone',
            'can_set_edit_mode' => $this->boards->canManage($user, $taskBoard),
            'archived' => $taskBoard->archived_at !== null,
            // Delete: custom boards only; automatic ones can only be archived.
            'can_delete' => ! $taskBoard->isAutomatic() && $this->boards->canManage($user, $taskBoard),
            // In assignee mode: whether this person may change any task, not only their own.
            'can_edit_all' => ($taskBoard->edit_mode ?? 'everyone') !== 'assignee' || $this->boards->canManage($user, $taskBoard),
            'my_role' => $this->boards->roleOf($user, $taskBoard),
            'members_count' => count($people = $this->effectiveMembers($taskBoard)),
            // The first four people on it, owners first, for the header avatars.
            'members_preview' => collect($people)->take(4)
                ->map(fn (array $m) => ['id' => $m['user_id'], 'name' => $m['name']])->values()->all(),
            'can_manage' => $this->boards->canManageMembers($user, $taskBoard),
            // The top bar's Automate count: task rules whose live version names this board.
            'automations_count' => $this->automationsOn($taskBoard),
            // Rename and delete: custom boards, their owners and Administrators.
            'can_rename' => ! $taskBoard->isAutomatic() && $this->boards->canManage($user, $taskBoard),
            'company_id' => $taskBoard->company_id,
            'franchise_id' => $taskBoard->franchise_id,
            'can_edit' => $this->boards->canEdit($user, $taskBoard),
            'statuses' => $taskBoard->statuses->map(fn (TaskStatus $s) => TaskBoardSettingsController::presentStatus($s))->all(),
            'columns' => $taskBoard->columns->map(fn (TaskColumn $c) => TaskBoardSettingsController::presentColumn($c))->all(),
            // Owner, Status, Due, About, Created, Updates: {key: {label, visible}}, every key.
            'builtin_columns' => $taskBoard->resolvedBuiltinColumns(),
            // Saved views: the board's shared ones and this person's own.
            'views' => TaskBoardViewsController::visible($taskBoard, $user->id),
            'groups' => $taskBoard->groups->map(fn (TaskGroup $g) => TaskBoardSettingsController::presentGroup($g))->all(),
            // has_avatar: a face without a photo is not asked for one (each was a 404).
            'assignable' => $assignable->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'has_avatar' => isset($withPhoto[(string) $u->id]),
            ])->all(),
            'tasks' => $tasks->map($present)
                ->concat($rolled->map(fn (Task $t) => $present($t) + ['rolled_up_from' => [
                    'board_id' => $t->board_id,
                    'board_name' => $sources[$t->board_id]->name,
                    // The client files it under this board's group of the same name.
                    'group_name' => $t->group?->name,
                ]]))->all(),
        ] + ($groups === null ? [] : [
            // {group id, or '' for none of them: {count, shown, has_more, footers}}.
            'group_totals' => (object) collect($groups)
                ->map(fn (array $g) => array_diff_key($g, ['ids' => 0]))->all(),
        ])]);
    }

    /** The board's own tasks, as the list presents them. */
    private function ownTasks(TaskBoard $board, bool $hidden)
    {
        return $board->tasks()
            ->when($hidden, fn ($q) => $q->where('sensitive', false))
            ->with(TasksController::WITH)
            ->withCount(TasksController::counts())
            ->orderBy('position')
            ->orderBy('created_at');
    }

    /**
     * Each group's top-level tasks in board order: its count, the page
     * [$skip, $skip + $take) of ids, and its number columns' sum and average
     * over ALL its top-level tasks (a locked sensitive task's values are not
     * shown, so they are not added either). Keyed by group id; '' holds the
     * tasks in none of the board's groups.
     *
     * @return array<string, array{count: int, shown: int, has_more: bool, ids: list<string>, footers: object}>
     */
    private function groupPages(TaskBoard $board, bool $hidden, int $take, int $skip = 0, ?string $only = null): array
    {
        $known = $board->groups->pluck('id')->map(fn ($id) => (string) $id)->all();
        $key = fn ($groupId) => in_array((string) $groupId, $known, true) ? (string) $groupId : '';
        $rows = $board->tasks()
            ->when($hidden, fn ($q) => $q->where('sensitive', false))
            ->whereNull('parent_task_id')
            ->orderBy('position')->orderBy('created_at')
            ->get(['id', 'group_id'])
            ->groupBy(fn (Task $t) => $key($t->group_id))->toBase();
        if ($only !== null) {
            $rows = $rows->only([$only]);
        }

        $numbers = $board->columns->where('type', 'number')->pluck('id')->all();
        $sums = [];
        if ($numbers !== []) {
            $found = \App\Models\TaskColumnValue::query()
                ->join('tasks', 'tasks.id', '=', 'task_column_values.task_id')
                ->where('tasks.board_id', $board->id)
                ->whereNull('tasks.deleted_at')
                ->whereNull('tasks.parent_task_id')
                ->where('tasks.sensitive', false)
                ->whereIn('task_column_values.column_id', $numbers)
                ->whereNotNull('task_column_values.value_number')
                ->groupBy('tasks.group_id', 'task_column_values.column_id')
                ->selectRaw('tasks.group_id as g, task_column_values.column_id as c, SUM(task_column_values.value_number) as s, COUNT(*) as n')
                ->toBase()->get();
            foreach ($found as $r) {
                $g = $key($r->g);
                $c = (string) $r->c;
                $sums[$g][$c] = [
                    's' => ($sums[$g][$c]['s'] ?? 0) + (float) $r->s,
                    'n' => ($sums[$g][$c]['n'] ?? 0) + (int) $r->n,
                ];
            }
        }

        $out = [];
        foreach ($rows as $group => $tasks) {
            $out[(string) $group] = [
                'count' => $tasks->count(),
                'shown' => min($skip + $take, $tasks->count()),
                'has_more' => $tasks->count() > $skip + $take,
                'ids' => $tasks->slice($skip, $take)->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                'footers' => (object) collect($sums[(string) $group] ?? [])
                    ->map(fn (array $a) => ['sum' => $a['s'], 'average' => $a['n'] > 0 ? $a['s'] / $a['n'] : 0])->all(),
            ];
        }

        return $out;
    }

    /**
     * GET task-boards/{id}/changes?since=<cursor>: what changed on the board
     * since the cursor, so an open board patches itself (Jose, 2026-11-16:
     * a Monday status change showed only after leaving the tab). Tasks are
     * the same rows show gives (rolled-up ones included), deleted ids are the
     * soft-deleted ones, and groups are the board's whole list (a deleted
     * group has no row to date). group_totals come with any task change.
     * Inclusive at the second, so a write in the cursor's own second is sent
     * twice rather than never. Over 500 changed tasks: reload, nothing else.
     */
    public function changes(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        $input = $request->validate(['since' => ['required', 'string', 'max:64']]);
        try {
            $since = \Carbon\CarbonImmutable::parse($input['since'])->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['since' => 'Not a time.']);
        }
        $cursor = now()->startOfSecond();
        $user = $request->user();
        $hidden = \App\Support\Tasks\SensitiveTasks::machineReader($user);
        $sources = $this->boards->rolledUpBoards($user, $taskBoard)->keyBy('id');
        $boardIds = [$taskBoard->id, ...$sources->keys()->all()];

        $touched = Task::query()->withTrashed()
            ->whereIn('board_id', $boardIds)
            ->where(fn ($q) => $q->where('updated_at', '>=', $since)->orWhere('deleted_at', '>=', $since));
        if ((clone $touched)->count() > 500) {
            return response()->json(['data' => ['reload' => true, 'cursor' => $cursor->toIso8601String()]]);
        }
        $rows = $touched->with([...TasksController::WITH, 'group:id,name'])
            ->withCount(TasksController::counts())->get();
        // Gone from this reader: deleted, or (for an API key) made sensitive.
        [$gone, $live] = $rows->partition(fn (Task $t) => $t->trashed() || ($hidden && $t->sensitive));

        $unread = \App\Support\Notes\NoteActivity::unreadCounts('task', 'task_updates', 'task_id',
            $live->map(fn (Task $t) => (string) $t->id)->values()->all(), (string) $user->id);
        $tasks = $live->map(function (Task $t) use ($unread, $sources, $taskBoard) {
            $row = TasksController::present($t) + ['unread_notes' => $unread[(string) $t->id] ?? 0];

            return $t->board_id === $taskBoard->id ? $row : $row + ['rolled_up_from' => [
                'board_id' => $t->board_id,
                'board_name' => $sources[$t->board_id]->name,
                'group_name' => $t->group?->name,
            ]];
        })->values()->all();

        return response()->json(['data' => [
            'cursor' => $cursor->toIso8601String(),
            'tasks' => $tasks,
            'deleted' => $gone->map(fn (Task $t) => (string) $t->id)->values()->all(),
            'groups' => $taskBoard->groups()->get()->map(fn (TaskGroup $g) => TaskBoardSettingsController::presentGroup($g))->all(),
        ] + ($rows->isEmpty() ? [] : [
            // Counts and footers only: shown/has_more are the client's, by what it loaded.
            'group_totals' => (object) collect($this->groupPages($taskBoard, $hidden, 0))
                ->map(fn (array $g) => ['count' => $g['count'], 'footers' => $g['footers']])->all(),
        ])]);
    }

    /**
     * GET task-boards/{id}/group-tasks?group_id=&offset=&limit=: the next page
     * of one group's top-level tasks and their subtasks ("Show more").
     * group_id '' (or absent) is the tasks in none of the board's groups.
     */
    public function groupTasks(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        $input = $request->validate([
            'group_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $user = $request->user();
        $hidden = \App\Support\Tasks\SensitiveTasks::machineReader($user);
        $group = (string) ($input['group_id'] ?? '');
        $page = $this->groupPages($taskBoard, $hidden, (int) ($input['limit'] ?? 50), (int) ($input['offset'] ?? 0), $group)[$group]
            ?? ['count' => 0, 'shown' => 0, 'has_more' => false, 'ids' => [], 'footers' => (object) []];
        $tasks = $this->ownTasks($taskBoard, $hidden)
            ->where(fn ($q) => $q->whereIn('id', $page['ids'])->orWhereIn('parent_task_id', $page['ids']))
            ->get();
        $unread = \App\Support\Notes\NoteActivity::unreadCounts('task', 'task_updates', 'task_id',
            $tasks->map(fn (Task $t) => (string) $t->id)->all(), (string) $user->id);

        return response()->json([
            'data' => $tasks->map(fn (Task $t) => TasksController::present($t) + ['unread_notes' => $unread[(string) $t->id] ?? 0])->all(),
            'meta' => array_diff_key($page, ['ids' => 0]),
        ]);
    }

    /** POST task-boards {name, visibility, company_id?, franchise_id?}: a custom board, filed on the current view. */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission('tasks.create_boards'), 403, 'You need permission to create boards.');

        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visibility' => ['required', Rule::in([TaskBoard::MAIN, TaskBoard::PRIVATE])],
            'company_id' => ['sometimes', 'nullable', 'uuid', 'prohibits:franchise_id'],
            'franchise_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $company = ! empty($input['company_id']) ? Company::query()->find($input['company_id']) : null;
        $franchise = ! empty($input['franchise_id']) ? Franchise::query()->find($input['franchise_id']) : null;
        [$type, $id] = match (true) {
            $franchise !== null => [Assignment::SCOPE_FRANCHISE, $franchise->id],
            $company !== null => [Assignment::SCOPE_COMPANY, $company->id],
            default => [Assignment::SCOPE_ORGANIZATION, null],
        };
        if ((! empty($input['company_id']) && ! $company) || (! empty($input['franchise_id']) && ! $franchise)
            || ! OwnedScope::mayWrite($user, $user->organization_id, $type, $id)) {
            throw ValidationException::withMessages([
                'place' => 'You can only create a board where your current view lets you work.',
            ]);
        }

        $board = $this->boards->createCustom($user, $input['name'], $input['visibility'], $company, $franchise);

        return $this->show($request, $board)->setStatusCode(201);
    }

    /**
     * PATCH task-boards/{id} {name?, visibility?, builtin_columns?}.
     * Name and visibility: owners and Administrators, custom boards only.
     * builtin_columns ({key: {label?, visible?}}, merged into what is saved):
     * anyone who may edit the board's columns, automatic boards included,
     * because it is column configuration like adding a column.
     */
    public function update(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        if ($request->hasAny(['name', 'visibility'])) {
            $this->authorizeManage($request->user(), $taskBoard);
        } elseif ($request->hasAny(['folder_id', 'colour', 'position'])) {
            // Arranging the list (folders, colours, order) is shared for the
            // organization: the right to make boards, on a board you can see.
            $this->authorize('view', $taskBoard);
            abort_unless($request->user()->hasPermission('tasks.create_boards'), 403, 'Only somebody who may make boards can arrange the board list.');
        } elseif ($request->has('edit_mode')) {
            // Automatic boards too: A-1's busiest place board is the one that needs it.
            $this->authorize('view', $taskBoard);
            abort_unless($this->boards->canManage($request->user(), $taskBoard), 403, 'Only an owner of this board or an Administrator can do that.');
        } elseif (! $request->has('builtin_columns')) {
            $this->authorizeManage($request->user(), $taskBoard);
        } else {
            $this->authorize('update', $taskBoard);
        }
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'required', Rule::in([TaskBoard::MAIN, TaskBoard::PRIVATE])],
            'edit_mode' => ['sometimes', 'required', Rule::in(['everyone', 'assignee'])],
            'builtin_columns' => ['sometimes', 'array:'.implode(',', array_keys(TaskBoard::BUILTIN_COLUMNS))],
            'builtin_columns.*' => ['array:label,visible'],
            'builtin_columns.*.label' => ['sometimes', 'string', 'min:1', 'max:60'],
            'builtin_columns.*.visible' => ['sometimes', 'boolean:strict'],
            'folder_id' => ['sometimes', 'nullable', 'uuid', fn ($a, $v, $fail) => $v === null || \App\Models\TaskBoardFolder::query()->whereKey($v)->exists() ?: $fail('That folder is not in this organization.')],
            'colour' => ['sometimes', 'nullable', TaskBoardFoldersController::COLOUR],
            'position' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ]);
        if (array_key_exists('builtin_columns', $input)) {
            $input['builtin_columns'] = self::mergeBuiltinColumns($taskBoard, $input['builtin_columns']);
        }
        $taskBoard->fill($input)->save();

        return $this->show($request, $taskBoard->fresh());
    }

    /**
     * Saved settings with $changes laid over them, key by key and field by
     * field. Only what was ever set is stored; the defaults stay in code.
     *
     * @param  array<string, array{label?: string, visible?: bool}>  $changes
     */
    public static function mergeBuiltinColumns(TaskBoard $board, array $changes): array
    {
        $saved = is_array($board->builtin_columns) ? $board->builtin_columns : [];
        foreach ($changes as $key => $fields) {
            if (! array_key_exists($key, TaskBoard::BUILTIN_COLUMNS)) {
                continue;
            }
            $row = is_array($saved[$key] ?? null) ? $saved[$key] : [];
            if (array_key_exists('label', $fields)) {
                $row['label'] = trim((string) $fields['label']);
            }
            if (array_key_exists('visible', $fields)) {
                $row['visible'] = (bool) $fields['visible'];
            }
            $saved[$key] = $row;
        }

        return $saved;
    }

    public function destroy(Request $request, TaskBoard $taskBoard): Response
    {
        $this->authorizeManage($request->user(), $taskBoard);
        // Typed back (Jose, 2026-11-09), as a job's number is before it goes.
        abort_unless(trim((string) $request->input('confirm')) === trim($taskBoard->name), 422, 'Type the board\'s name to delete it.');
        $taskBoard->delete();

        return response()->noContent();
    }

    /**
     * GET task-boards/{id}/members: the members, and (owners and
     * Administrators only) the people who may still be added.
     */
    public function members(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);

        $members = collect($this->effectiveMembers($taskBoard));

        $candidates = $this->boards->canManageMembers($request->user(), $taskBoard)
            ? $this->boards->candidates($taskBoard)->load('role:id,name')
                ->reject(fn (User $u) => $members->contains('user_id', $u->id))
                ->values()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role_name' => $u->role?->name])
                ->all()
            : [];

        return response()->json(['data' => [
            'members' => $members->all(),
            'candidates' => $candidates,
        ]]);
    }

    /**
     * GET task-boards/{id}/people/{user}: the hover card for somebody this
     * board can name (Jose 11-13, Monday parity). Only people the board can
     * assign are answered, so the card is no wider than the person picker.
     * Email and phone come back for the "Contact details" fold; the client
     * keeps them hidden until it is opened.
     */
    public function person(Request $request, TaskBoard $taskBoard, User $user): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        abort_unless($this->boards->assignable($taskBoard)->contains('id', $user->id), 404);

        $user->loadMissing('role:id,name');
        $state = \App\Models\PhonePresence::query()->where('user_id', $user->id)->value('state');

        return response()->json(['data' => [
            'id' => $user->id,
            'name' => $user->name,
            'has_avatar' => $user->avatar_path !== null,
            'role_name' => $user->role?->name,
            'timezone' => $tz = \App\Support\Time\ViewTimezone::forUser($user),
            // Worked out here: the client has no zone database.
            'local_time' => now($tz)->format('g:i A T'),
            'presence' => $state,
            'email' => $user->email,
            'phone' => $user->phone,
        ]]);
    }

    public function storeMember(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorizeMembers($request->user(), $taskBoard);
        $input = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(TaskBoardMember::ROLES)],
        ]);

        if (! $this->boards->candidates($taskBoard)->contains('id', $input['user_id'])) {
            throw ValidationException::withMessages(['user_id' => 'That person cannot work where this board is.']);
        }
        if ($taskBoard->members()->where('user_id', $input['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => 'That person is already on this board.']);
        }

        $member = $taskBoard->members()->create([
            'organization_id' => $taskBoard->organization_id,
            'user_id' => $input['user_id'],
            'role' => $input['role'],
        ]);

        return response()->json(['data' => self::presentMember($member->load('user:id,name'))], 201);
    }

    public function updateMember(Request $request, TaskBoard $taskBoard, TaskBoardMember $member): JsonResponse
    {
        $this->authorizeMembers($request->user(), $taskBoard);
        abort_unless($member->board_id === $taskBoard->id, 404);
        $input = $request->validate(['role' => ['required', Rule::in(TaskBoardMember::ROLES)]]);

        DB::transaction(function () use ($taskBoard, $member, $input) {
            if ($input['role'] !== TaskBoardMember::OWNER) {
                $this->keepAnOwner($taskBoard, $member);
            }
            $member->update(['role' => $input['role']]);
        });

        return response()->json(['data' => self::presentMember($member->load('user:id,name'))]);
    }

    public function destroyMember(Request $request, TaskBoard $taskBoard, TaskBoardMember $member): Response
    {
        $this->authorizeMembers($request->user(), $taskBoard);
        abort_unless($member->board_id === $taskBoard->id, 404);

        DB::transaction(function () use ($taskBoard, $member) {
            $this->keepAnOwner($taskBoard, $member);
            $member->delete();
        });

        return response()->noContent();
    }

    public static function presentMember(TaskBoardMember $m): array
    {
        return [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => $m->user?->name,
            'role' => $m->role,
        ];
    }

    /**
     * Manual rows plus, on a company or franchise board, everyone assigned
     * there (`implicit: true`, role member, no id, cannot be removed; a manual row has no `implicit` key), owners first.
     *
     * @return list<array<string, mixed>>
     */
    /** Task rules (on or off) whose current version is conditioned on `board_id eq` this board. */
    private function automationsOn(TaskBoard $board): int
    {
        return \App\Models\Automation::query()
            ->where('trigger_key', 'like', 'task.%')
            ->with('activeVersion:id,conditions')
            ->get(['id', 'active_version_id'])
            ->filter(fn ($a) => collect($a->activeVersion?->conditions['all'] ?? [])->contains(
                fn ($c) => is_array($c) && ($c['field'] ?? null) === 'board_id'
                    && ($c['op'] ?? null) === 'eq' && (string) ($c['value'] ?? '') === (string) $board->id
            ))->count();
    }

    private function effectiveMembers(TaskBoard $board): array
    {
        $manual = $board->members()->with('user:id,name')->get()
            ->map(fn (TaskBoardMember $m) => self::presentMember($m));
        $implicit = $this->boards->implicitMembers($board)->map(fn (User $u) => [
            'id' => null, 'user_id' => $u->id, 'name' => $u->name, 'role' => TaskBoardMember::MEMBER, 'implicit' => true,
        ]);

        return $manual->concat($implicit)
            ->sortBy(fn (array $m) => [array_search($m['role'], TaskBoardMember::ROLES, true), $m['name']])
            ->values()->all();
    }

    /** Members on any kind of board: owner, tasks.manage_members, Administrator. */
    private function authorizeMembers(User $user, TaskBoard $board): void
    {
        $this->authorize('view', $board);
        abort_unless($this->boards->canManageMembers($user, $board), 403, 'Only an owner of this board, or somebody allowed to add people to any board, can do that.');
    }

    /**
     * A board always keeps at least one owner. A place board starts with
     * none, so this guards only a board that has owners.
     */
    private function keepAnOwner(TaskBoard $board, TaskBoardMember $leaving): void
    {
        if ($leaving->role !== TaskBoardMember::OWNER) {
            return;
        }
        if ($board->kind === TaskBoard::KIND_USER && $leaving->user_id === $board->owner_user_id) {
            throw ValidationException::withMessages(['role' => 'The person whose board this is stays its owner.']);
        }
        if (in_array($board->kind, [TaskBoard::KIND_COMPANY, TaskBoard::KIND_FRANCHISE], true)) {
            return;
        }
        $others = $board->members()->where('role', TaskBoardMember::OWNER)
            ->whereKeyNot($leaving->getKey())->lockForUpdate()->count();
        if ($others === 0) {
            throw ValidationException::withMessages(['role' => 'A board needs at least one owner. Make somebody else an owner first.']);
        }
    }

    private function authorizeManage(User $user, TaskBoard $board): void
    {
        $this->authorize('view', $board);
        if ($board->isAutomatic()) {
            throw ValidationException::withMessages(['board' => 'This board is made automatically: it cannot be renamed or deleted.']);
        }
        abort_unless($this->boards->canManage($user, $board), 403, 'Only an owner of this board or an Administrator can do that.');
    }
}
