<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskDuplicator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskDuplicationController extends TaskApiController
{
    public function __construct(private readonly TaskDuplicator $duplicator) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'destination_board_id' => ['required', 'uuid'],
            'destination_group_id' => ['required', 'uuid'],
            'title' => ['sometimes', 'nullable', 'string', 'max:191'],
            'mappings' => ['sometimes', 'array:groups,status_options,priority_options,column_definitions,column_options'],
            'mappings.groups' => ['sometimes', 'array'],
            'mappings.groups.*' => ['uuid'],
            'mappings.status_options' => ['sometimes', 'array'],
            'mappings.status_options.*' => ['uuid'],
            'mappings.priority_options' => ['sometimes', 'array'],
            'mappings.priority_options.*' => ['uuid'],
            'mappings.column_definitions' => ['sometimes', 'array'],
            'mappings.column_definitions.*' => ['uuid'],
            'mappings.column_options' => ['sometimes', 'array'],
            'mappings.column_options.*' => ['uuid'],
        ]);
        $destination = TaskBoard::query()->findOrFail($input['destination_board_id']);

        return $this->taskMutation(fn () => $this->duplicator->duplicateTask(
            $request->user(), $task, $destination, ViewScope::forUser($request->user()),
            (int) $input['expected_version'], $input['destination_group_id'],
            $input['mappings'] ?? [], $input['title'] ?? null,
        ), 201);
    }
}
