<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Task Board: the boards in the caller's current view, and one board's contents. */
class TaskBoardsController extends Controller
{
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
            ->with(['company:id,name', 'franchise' => fn ($q) => $q->withTrashed()->with('company:id,name')])
            ->get()
            ->sortBy(fn (TaskBoard $b) => [array_search($b->kind, ['user', 'franchise', 'company'], true), $b->name])
            ->values();

        return response()->json(['data' => $boards->map(fn (TaskBoard $b) => [
            'id' => $b->id,
            'kind' => $b->kind,
            'name' => $b->name,
            // Franchise names repeat across companies: the client groups by these.
            'company_id' => ($b->company ?? $b->franchise?->company)?->id,
            'company_name' => ($b->company ?? $b->franchise?->company)?->name,
            'open_for_me' => (int) $b->open_for_me,
            'can_edit' => $this->boards->canEdit($user, $b),
        ])->all()]);
    }

    public function show(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);

        $tasks = $taskBoard->tasks()
            ->with(TasksController::WITH)
            ->withCount(TasksController::counts())
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => [
            'id' => $taskBoard->id,
            'kind' => $taskBoard->kind,
            'name' => $taskBoard->name,
            'can_edit' => $this->boards->canEdit($request->user(), $taskBoard),
            'statuses' => $taskBoard->statuses->map(fn (TaskStatus $s) => TaskBoardSettingsController::presentStatus($s))->all(),
            'columns' => $taskBoard->columns->map(fn (TaskColumn $c) => TaskBoardSettingsController::presentColumn($c))->all(),
            'groups' => $taskBoard->groups->map(fn (TaskGroup $g) => TaskBoardSettingsController::presentGroup($g))->all(),
            'assignable' => $this->boards->assignable($taskBoard)->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all(),
            'tasks' => $tasks->map(fn (Task $t) => TasksController::present($t))->all(),
        ]]);
    }
}
