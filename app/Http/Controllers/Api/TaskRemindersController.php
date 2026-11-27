<?php

namespace App\Http\Controllers\Api;

use App\Models\Task;
use App\Models\TaskReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** A person's reminders on a task (Shoval 11-13): set, list, remove. Only their own. */
class TaskRemindersController extends Controller
{
    public function index(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);

        return response()->json(['data' => TaskReminder::query()->where('task_id', $task->id)->where('user_id', $request->user()->id)
            ->orderBy('remind_at')->get(['id', 'remind_at', 'note', 'sent_at'])]);
    }

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->authorize('view', $task);
        $data = $request->validate(['remind_at' => ['required', 'date', 'after:now'], 'note' => ['nullable', 'string', 'max:255']]);
        $reminder = TaskReminder::query()->create([
            'organization_id' => $task->organization_id, 'task_id' => $task->id, 'user_id' => $request->user()->id,
            'remind_at' => $data['remind_at'], 'note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $reminder->only(['id', 'remind_at', 'note', 'sent_at'])], 201);
    }

    public function destroy(Request $request, Task $task, TaskReminder $reminder): Response
    {
        abort_unless($reminder->task_id === $task->id && $reminder->user_id === $request->user()->id, 404);
        $reminder->delete();

        return response()->noContent();
    }
}
