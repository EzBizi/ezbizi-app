<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskConflict;
use App\Support\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskBulkController extends TaskApiController
{
    public function __construct(private readonly TaskWriter $writer) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'tasks' => ['required', 'array', 'min:1', 'max:200'],
            'tasks.*.id' => ['required', 'uuid', 'distinct'],
            'tasks.*.expected_version' => ['required', 'integer', 'min:1'],
            'changes' => ['required', 'array:title,description,owner_user_id,collaborator_user_ids,status_option_id,priority_option_id,start_on,due_on,archived'],
            'changes.title' => ['sometimes', 'string', 'max:191'],
            'changes.description' => ['sometimes', 'nullable', 'string'],
            'changes.owner_user_id' => ['sometimes', 'nullable', 'uuid'],
            'changes.collaborator_user_ids' => ['sometimes', 'array', 'max:100'],
            'changes.collaborator_user_ids.*' => ['uuid', 'distinct'],
            'changes.status_option_id' => ['sometimes', 'uuid'],
            'changes.priority_option_id' => ['sometimes', 'nullable', 'uuid'],
            'changes.start_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'changes.due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'changes.archived' => ['sometimes', 'boolean'],
        ]);
        abort_if($input['changes'] === [], Response::HTTP_UNPROCESSABLE_ENTITY, 'Submit at least one bulk change.');
        abort_if(array_key_exists('archived', $input['changes']) && count($input['changes']) !== 1,
            Response::HTTP_UNPROCESSABLE_ENTITY, 'Archive or restore must be the only bulk change.');

        try {
            $tasks = $this->writer->bulk(
                $request->user(),
                $board,
                ViewScope::forUser($request->user()),
                $input['tasks'],
                $input['changes'],
            );
        } catch (TaskConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'data' => $conflict->current,
                'meta' => [
                    'current_version' => $conflict->currentVersion,
                    'conflicting_fields' => $conflict->conflictingFields,
                ],
            ], Response::HTTP_CONFLICT);
        }

        return response()->json([
            'data' => collect($tasks)->map(fn (Task $task) => $this->presentTask($task))->values(),
            'meta' => ['total' => count($tasks)],
        ]);
    }
}
