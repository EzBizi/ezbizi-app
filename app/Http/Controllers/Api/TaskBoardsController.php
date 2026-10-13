<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskGroup;
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
            ->get()
            ->sortBy(fn (TaskBoard $b) => [array_search($b->kind, ['user', 'franchise', 'company'], true), $b->name])
            ->values();

        return response()->json(['data' => $boards->map(fn (TaskBoard $b) => [
            'id' => $b->id,
            'kind' => $b->kind,
            'name' => $b->name,
            'open_for_me' => (int) $b->open_for_me,
            'can_edit' => $this->boards->canEdit($user, $b),
        ])->all()]);
    }

    public function show(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);

        $tasks = $taskBoard->tasks()
            ->with(['assignee:id,name', 'creator:id,name'])
            ->withCount('updates')
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => [
            'id' => $taskBoard->id,
            'kind' => $taskBoard->kind,
            'name' => $taskBoard->name,
            'can_edit' => $this->boards->canEdit($request->user(), $taskBoard),
            'statuses' => collect(Task::STATUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'groups' => $taskBoard->groups->map(fn (TaskGroup $g) => [
                'id' => $g->id, 'name' => $g->name, 'colour' => $g->colour, 'position' => $g->position,
            ])->all(),
            'tasks' => $tasks->map(fn (Task $t) => TasksController::present($t))->all(),
        ]]);
    }
}
