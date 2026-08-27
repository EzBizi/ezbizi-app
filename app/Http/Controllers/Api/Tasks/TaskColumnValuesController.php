<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskColumnDefinition;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskColumnValueStore;
use App\Support\Tasks\TaskConflict;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskColumnValuesController extends TaskApiController
{
    public function __construct(private readonly TaskColumnValueStore $values) {}

    public function update(Request $request, Task $task, TaskColumnDefinition $column): JsonResponse
    {
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'value' => ['present'],
        ]);

        try {
            $updated = $this->values->replace(
                $request->user(), $task, $column, ViewScope::forUser($request->user()),
                (int) $input['expected_version'], $input['value'],
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

        return response()->json(['data' => [
            'task_id' => $updated->id,
            'column_definition_id' => $column->id,
            'type' => $column->type->value,
            'value' => $this->values->value($updated, $column),
            'version' => (int) $updated->version,
            'detail_version' => (int) $updated->detail_version,
        ]]);
    }
}
