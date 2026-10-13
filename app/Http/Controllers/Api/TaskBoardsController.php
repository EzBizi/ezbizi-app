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

            return [
                'id' => $b->id,
                'kind' => $b->kind,
                'name' => $b->name,
                'visibility' => $b->visibility,
                // Franchise names repeat across companies: the client groups by these.
                'company_id' => ($b->company ?? $b->franchise?->company)?->id,
                'company_name' => ($b->company ?? $b->franchise?->company)?->name,
                'open_for_me' => (int) $b->open_for_me,
                'can_edit' => $this->boards->canEdit($user, $b),
                'my_role' => $b->kind === TaskBoard::KIND_USER
                    ? ($b->owner_user_id === $user->getKey() ? TaskBoardMember::OWNER : null)
                    : $b->members->firstWhere('user_id', $user->getKey())?->role,
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
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => [
            'id' => $taskBoard->id,
            'kind' => $taskBoard->kind,
            'name' => $taskBoard->name,
            'visibility' => $taskBoard->visibility,
            'my_role' => $this->boards->roleOf($user, $taskBoard),
            'members_count' => $taskBoard->members()->count(),
            'can_edit' => $this->boards->canEdit($user, $taskBoard),
            'statuses' => $taskBoard->statuses->map(fn (TaskStatus $s) => TaskBoardSettingsController::presentStatus($s))->all(),
            'columns' => $taskBoard->columns->map(fn (TaskColumn $c) => TaskBoardSettingsController::presentColumn($c))->all(),
            'groups' => $taskBoard->groups->map(fn (TaskGroup $g) => TaskBoardSettingsController::presentGroup($g))->all(),
            'assignable' => $this->boards->assignable($taskBoard)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'tasks' => $tasks->map(fn (Task $t) => TasksController::present($t))->all(),
        ]]);
    }

    /** POST task-boards {name, visibility, company_id?, franchise_id?}: a custom board, filed on the current view. */
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission('tasks.edit'), 403, 'You need permission to edit tasks to create a board.');

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

    /** PATCH task-boards/{id} {name?, visibility?}: owners and Administrators, custom boards only. */
    public function update(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorizeManage($request->user(), $taskBoard);
        $input = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'visibility' => ['sometimes', 'required', Rule::in([TaskBoard::MAIN, TaskBoard::PRIVATE])],
        ]);
        $taskBoard->fill($input)->save();

        return $this->show($request, $taskBoard->fresh());
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

        $members = $taskBoard->members()->with('user:id,name')->get()
            ->sortBy(fn (TaskBoardMember $m) => [array_search($m->role, TaskBoardMember::ROLES, true), $m->user?->name])
            ->values();

        $candidates = $taskBoard->kind === TaskBoard::KIND_CUSTOM && $this->boards->canManage($request->user(), $taskBoard)
            ? $this->boards->reach($taskBoard)->load('role:id,name')
                ->reject(fn (User $u) => $members->contains('user_id', $u->id))
                ->values()
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'role_name' => $u->role?->name])
                ->all()
            : [];

        return response()->json(['data' => [
            'members' => $members->map(fn (TaskBoardMember $m) => self::presentMember($m))->all(),
            'candidates' => $candidates,
        ]]);
    }

    public function storeMember(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorizeManage($request->user(), $taskBoard);
        $input = $request->validate([
            'user_id' => ['required', 'uuid'],
            'role' => ['required', Rule::in(TaskBoardMember::ROLES)],
        ]);

        if (! $this->boards->reach($taskBoard)->contains('id', $input['user_id'])) {
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
        $this->authorizeManage($request->user(), $taskBoard);
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
        $this->authorizeManage($request->user(), $taskBoard);
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

    /** A board always keeps at least one owner. */
    private function keepAnOwner(TaskBoard $board, TaskBoardMember $leaving): void
    {
        if ($leaving->role !== TaskBoardMember::OWNER) {
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
            throw ValidationException::withMessages(['board' => 'This board is made automatically: it cannot be renamed, deleted or given members.']);
        }
        abort_unless($this->boards->canManage($user, $board), 403, 'Only an owner of this board or an Administrator can do that.');
    }
}
