<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskRecordLink;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskRecordLinkConflict;
use App\Support\Tasks\TaskRecordLinkDefinition;
use App\Support\Tasks\TaskRecordLinkRegistry;
use App\Support\Tasks\TaskRecordLinkWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class TaskRecordLinksController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskRecordLinkRegistry $registry,
        private readonly TaskRecordLinkWriter $writer,
    ) {}

    public function types(Request $request, Task $task): JsonResponse
    {
        $this->context($request, $task);

        return response()->json(['data' => $this->registry->catalogue()]);
    }

    public function search(Request $request, Task $task): JsonResponse
    {
        $input = $request->validate([
            'type' => ['required', 'string', 'max:80'],
            'q' => ['present', 'string', 'max:100'],
        ]);
        [$board, $scope] = $this->context($request, $task);
        $definition = $this->definition($input['type']);

        return response()->json(['data' => $definition->search($request->user(), $board, $scope, $input['q'])]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $input = $request->validate([
            'expected_task_version' => ['required', 'integer', 'min:1'],
            'record_type' => ['required', 'string', 'max:80'],
            'record_id' => ['required', 'uuid'],
        ]);
        $this->definition($input['record_type']);

        try {
            $link = $this->writer->create(
                $request->user(), $task, ViewScope::forUser($request->user()),
                (int) $input['expected_task_version'], $input['record_type'], $input['record_id'],
            );
        } catch (TaskRecordLinkConflict $conflict) {
            return $this->conflict($conflict);
        }

        return response()->json(['data' => $this->present($link, $request)], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Task $task, TaskRecordLink $link): JsonResponse
    {
        abort_unless($link->task_id === $task->id, Response::HTTP_NOT_FOUND);
        $input = $request->validate(['expected_task_version' => ['required', 'integer', 'min:1']]);
        try {
            $this->writer->remove(
                $request->user(), $task, $link, ViewScope::forUser($request->user()),
                (int) $input['expected_task_version'],
            );
        } catch (TaskRecordLinkConflict $conflict) {
            return $this->conflict($conflict);
        }

        return response()->json(['data' => ['removed' => true]]);
    }

    /** @return array{TaskBoard,ViewScope} */
    private function context(Request $request, Task $task): array
    {
        $board = TaskBoard::query()->active()->findOrFail($task->board_id);
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();
        abort_unless($task->archived_at === null, Response::HTTP_NOT_FOUND);

        return [$board, $scope];
    }

    private function definition(string $type): TaskRecordLinkDefinition
    {
        try {
            return $this->registry->get($type);
        } catch (InvalidArgumentException) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'That linked-record type is unavailable.');
        }
    }

    private function present(TaskRecordLink $link, Request $request): array
    {
        [$board, $scope] = $this->context($request, $link->task);
        $definition = $this->definition($link->record_type);
        $record = $definition->resolve($request->user(), $board, $scope, $link->record_id);
        if ($record === null) {
            return ['link_id' => $link->id, 'available' => false, 'type' => $definition->label()];
        }

        return ['link_id' => $link->id, 'available' => true, ...$definition->present($record)];
    }

    private function conflict(TaskRecordLinkConflict $conflict): JsonResponse
    {
        return response()->json([
            'message' => $conflict->getMessage(),
            'meta' => ['current_task_version' => $conflict->currentVersion],
        ], Response::HTTP_CONFLICT);
    }
}
