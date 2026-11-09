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

        $boards = $this->boards->visibleTo($user)
            ->withCount(['tasks as open_for_me' => fn ($q) => $q
                ->where('assignee_user_id', $user->getKey())
                ->where('status', '!=', Task::STATUS_DONE)])
            ->with([
                'company:id,name',
                'franchise' => fn ($q) => $q->withTrashed()->with('company:id,name'),
                'owner:id,name',
                'members' => fn ($q) => $q->with('user:id,name'),
            ])
            ->get()
            ->sortBy(fn (TaskBoard $b) => [array_search($b->kind, self::ORDER, true), $b->name])
            ->values();

        return response()->json(['data' => $boards->map(function (TaskBoard $b) use ($user) {
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
                'open_for_me' => (int) $b->open_for_me,
                'can_edit' => $this->boards->canEdit($user, $b),
                'my_role' => $role,
                'can_manage' => $this->boards->canManageMembers($user, $b),
                'section' => $this->boards->sectionOf($user, $b, $role),
                'owner' => $owner ? ['id' => $owner->id, 'name' => $owner->name] : null,
            ];
        })->all()]);
    }

    public function show(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        $user = $request->user();

        $tasks = $taskBoard->tasks()
            ->with(TasksController::WITH)
            ->withCount(TasksController::counts())
            ->orderBy('position')
            ->orderBy('created_at')
            ->get();

        // A company board carries its branches' tasks as the same rows, each
        // marked with the board it lives on (Jose, 2026-10-28).
        $sources = $this->boards->rolledUpBoards($user, $taskBoard)->keyBy('id');
        $rolled = $sources->isEmpty() ? collect() : Task::query()
            ->whereIn('board_id', $sources->keys())
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

        return response()->json(['data' => [
            'id' => $taskBoard->id,
            'kind' => $taskBoard->kind,
            'name' => $taskBoard->name,
            'visibility' => $taskBoard->visibility,
            'edit_mode' => $taskBoard->edit_mode ?? 'everyone',
            'can_set_edit_mode' => $this->boards->canManage($user, $taskBoard),
            // In assignee mode: whether this person may change any task, not only their own.
            'can_edit_all' => ($taskBoard->edit_mode ?? 'everyone') !== 'assignee' || $this->boards->canManage($user, $taskBoard),
            'my_role' => $this->boards->roleOf($user, $taskBoard),
            'members_count' => count($people = $this->effectiveMembers($taskBoard)),
            // The first four people on it, owners first, for the header avatars.
            'members_preview' => collect($people)->take(4)
                ->map(fn (array $m) => ['id' => $m['user_id'], 'name' => $m['name']])->values()->all(),
            'can_manage' => $this->boards->canManageMembers($user, $taskBoard),
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
            'assignable' => $this->boards->assignable($taskBoard)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'tasks' => $tasks->map($present)
                ->concat($rolled->map(fn (Task $t) => $present($t) + ['rolled_up_from' => [
                    'board_id' => $t->board_id,
                    'board_name' => $sources[$t->board_id]->name,
                    // The client files it under this board's group of the same name.
                    'group_name' => $t->group?->name,
                ]]))->all(),
        ]]);
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
