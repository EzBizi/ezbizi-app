<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskDependency;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskDependencyWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST tasks/{task}/dependencies` and `DELETE tasks/dependencies/{dependency}`
 * (task-4 brief). `{task}` is the task that is BLOCKED; the request names its
 * blocker by id.
 */
final class TaskDependenciesController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskDependencyWriter $writer,
    ) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->context($request, $task);
        $input = $request->validate(['blocker_task_id' => ['required', 'uuid']]);
        $blocker = $this->resolveVisible($request, $input['blocker_task_id']);

        $edge = $this->writer->add($request->user(), $task, $blocker);

        return response()->json(['data' => $this->present($edge)], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, TaskDependency $dependency): JsonResponse
    {
        $board = TaskBoard::query()->active()->findOrFail($dependency->board_id);
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();

        $this->writer->remove($request->user(), $dependency);

        return response()->json(['data' => ['removed' => true]]);
    }

    private function context(Request $request, Task $task): void
    {
        $board = TaskBoard::query()->active()->findOrFail($task->board_id);
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();
        abort_unless($task->archived_at === null, Response::HTTP_NOT_FOUND);
    }

    /**
     * Resolve a body-supplied task id the same way the visibility layer would
     * — a task on a board the actor cannot reach, or one that never existed,
     * both 404 identically. That is what keeps an attacker-controlled
     * `blocker_task_id` from disclosing which is which (EnsureTaskRouteAccess).
     */
    private function resolveVisible(Request $request, string $taskId): Task
    {
        $scope = ViewScope::forUser($request->user());
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $request->user(), $scope)
            ->select('task_boards.id');

        return Task::query()->whereIn('board_id', $visibleBoards)->active()->findOrFail($taskId);
    }

    private function present(TaskDependency $edge): array
    {
        return [
            'id' => $edge->id,
            'board_id' => $edge->board_id,
            'blocker_task_id' => $edge->blocker_task_id,
            'blocked_task_id' => $edge->blocked_task_id,
        ];
    }
}
