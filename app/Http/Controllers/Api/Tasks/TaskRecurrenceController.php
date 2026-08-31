<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PUT tasks/{task}/recurrence` — the route `TaskWriter::setRecurrence`
 * always needed and never had (task-9 report flagged it as unreachable;
 * task-13 brief assigns the gap here). Client contract is `TaskApi.
 * setRecurrence`: body `{expected_version, recurrence}`, response
 * `{data: <task>}` on the same shape every other single-purpose task
 * mutation (`collaborators`, `watch`) returns.
 *
 * `TaskWriter::setRecurrence` authorizes on `tasks.edit` plus an
 * organization read-only check ONLY — unlike `update()` / `setArchived()`,
 * it never calls `lockBoardFor()`, so it never asks the board's `ViewScope`
 * whether this actor may see it at all. `EnsureTaskRouteAccess` already
 * closes that gap structurally for every `{task}`-bound route, but this
 * endpoint repeats the same `viewDecision()` explicitly, the same
 * belt-and-braces shape `TaskDependenciesController::context()` uses,
 * rather than leaning on the middleware alone: an actor holding
 * `tasks.edit` anywhere in the organization must never be able to declare
 * recurrence on a board they cannot see.
 */
final class TaskRecurrenceController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskWriter $writer,
    ) {}

    public function update(Request $request, Task $task): JsonResponse
    {
        $board = TaskBoard::query()->active()->findOrFail($task->board_id);
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();

        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'recurrence' => ['present', 'nullable', 'array'],
        ]);

        return $this->taskMutation(fn () => $this->writer->setRecurrence(
            $request->user(),
            $task,
            $input['recurrence'],
            (int) $input['expected_version'],
        ));
    }
}
