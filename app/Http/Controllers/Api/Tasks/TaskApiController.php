<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Http\Controllers\Api\Controller;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskConflict;
use App\Support\Tasks\TaskOrderConflict;
use App\Support\Tasks\TaskStructureConflict;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

abstract class TaskApiController extends Controller
{
    protected function taskMutation(callable $mutation, int $status = Response::HTTP_OK): JsonResponse
    {
        try {
            $task = $mutation();
        } catch (TaskConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'data' => $conflict->current,
                'meta' => [
                    'current_version' => $conflict->currentVersion,
                    'conflicting_fields' => $conflict->conflictingFields,
                ],
            ], Response::HTTP_CONFLICT);
        } catch (TaskOrderConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'meta' => ['current_group_versions' => $conflict->currentGroupVersions],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json(['data' => $this->presentTask($task)], $status);
    }

    protected function presentTask(Task $task): array
    {
        $effectiveGroup = $task->group ?? $task->parent?->group;

        return [
            'id' => $task->id,
            'board_id' => $task->board_id,
            'group_id' => $effectiveGroup?->id,
            'parent_task_id' => $task->parent_task_id,
            'number' => $task->number,
            'title' => $task->title,
            'description' => $task->description,
            'description_plain' => $task->description_plain,
            'owner_user_id' => $task->owner_user_id,
            'collaborator_user_ids' => $task->relationLoaded('collaborators')
                ? $task->collaborators->pluck('user_id')->values()->all()
                : [],
            'status_option_id' => $task->status_option_id,
            'priority_option_id' => $task->priority_option_id,
            'start_on' => $task->start_on?->format('Y-m-d'),
            'due_on' => $task->due_on?->format('Y-m-d'),
            'due_revision' => (int) $task->due_revision,
            'completed_at' => $task->completed_at?->toIso8601String(),
            'cancelled_at' => $task->cancelled_at?->toIso8601String(),
            'position' => (int) $task->position,
            'version' => (int) $task->version,
            'detail_version' => (int) $task->detail_version,
            'archived' => $task->archived_at !== null,
            'subtasks' => $task->relationLoaded('subtasks')
                ? $task->subtasks->map(fn (Task $subtask) => [
                    'id' => $subtask->id,
                    'title' => $subtask->title,
                    'status_option_id' => $subtask->status_option_id,
                    'version' => (int) $subtask->version,
                    'archived' => $subtask->archived_at !== null,
                ])->values()->all()
                : [],
        ];
    }

    protected function structureMutation(
        TaskBoard $board,
        callable $mutation,
        callable $present,
        int $status = Response::HTTP_OK,
    ): JsonResponse {
        try {
            $result = $mutation();
        } catch (TaskStructureConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'meta' => ['current_structure_version' => $conflict->currentVersion],
            ], Response::HTTP_CONFLICT);
        }

        $versionBoard = $result instanceof TaskBoard ? $result : $board;

        return response()->json([
            'data' => $present($result),
            'meta' => ['structure_version' => (int) $versionBoard->fresh()->structure_version],
        ], $status);
    }
}
