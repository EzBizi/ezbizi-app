<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskUpdateWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskWatchPreferencesController extends TaskApiController
{
    public function __construct(private readonly TaskUpdateWriter $writer) {}

    public function update(Request $request, Task $task): JsonResponse
    {
        $input = $request->validate(['preference' => ['present', 'nullable', 'in:watched,muted']]);
        $preference = $this->writer->setWatchPreference(
            $request->user(), $task, ViewScope::forUser($request->user()), $input['preference'],
        );

        return response()->json(['data' => [
            'task_id' => $task->id, 'preference' => $preference?->preference,
        ]]);
    }
}
