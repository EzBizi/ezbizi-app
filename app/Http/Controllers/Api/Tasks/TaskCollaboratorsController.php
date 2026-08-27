<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskCollaboratorsController extends TaskApiController
{
    public function __construct(private readonly TaskWriter $writer) {}

    public function update(Request $request, Task $task): JsonResponse
    {
        $input = $request->validate([
            'expected_version' => ['required', 'integer', 'min:1'],
            'user_ids' => ['present', 'array', 'max:100'],
            'user_ids.*' => ['uuid', 'distinct'],
        ]);

        return $this->taskMutation(fn () => $this->writer->update(
            $request->user(), $task, ViewScope::forUser($request->user()),
            (int) $input['expected_version'], ['collaborator_user_ids' => $input['user_ids']],
        ));
    }
}
