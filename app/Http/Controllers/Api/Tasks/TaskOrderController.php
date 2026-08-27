<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskOrderController extends TaskApiController
{
    public function __construct(private readonly TaskWriter $writer) {}

    public function store(Request $request, TaskBoard $board): JsonResponse
    {
        $input = $request->validate([
            'task_id' => ['required', 'uuid'],
            'destination_group_id' => ['required', 'uuid'],
            'before_task_id' => ['sometimes', 'nullable', 'uuid', 'different:task_id'],
            'after_task_id' => ['sometimes', 'nullable', 'uuid', 'different:task_id'],
            'expected_source_order_version' => ['required', 'integer', 'min:1'],
            'expected_destination_order_version' => ['required', 'integer', 'min:1'],
            'expected_task_version' => ['required', 'integer', 'min:1'],
        ]);

        return $this->taskMutation(fn () => $this->writer->reorder(
            $request->user(),
            $board,
            ViewScope::forUser($request->user()),
            $input,
        ));
    }
}
