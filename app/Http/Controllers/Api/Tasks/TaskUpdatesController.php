<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskUpdate;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskUpdateConflict;
use App\Support\Tasks\TaskUpdateWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskUpdatesController extends TaskApiController
{
    public function __construct(private readonly TaskUpdateWriter $writer) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        $this->authorize('comment', $task);
        $input = $request->validate($this->rules(creating: true));
        $existed = TaskUpdate::query()->where('task_id', $task->id)
            ->where('idempotency_key', $input['idempotency_key'])->exists();
        $update = $this->writer->create(
            $request->user(), $task, ViewScope::forUser($request->user()),
            $input['body'] ?? null, $input['idempotency_key'],
            mentionUserIds: $input['mention_user_ids'] ?? [],
            attachmentCandidateIds: $input['attachment_candidate_ids'] ?? [],
        );

        return response()->json(['data' => $this->present($update)],
            $existed ? Response::HTTP_OK : Response::HTTP_CREATED);
    }

    public function update(Request $request, Task $task, TaskUpdate $update): JsonResponse
    {
        abort_unless($update->task_id === $task->id, Response::HTTP_NOT_FOUND);
        $input = $request->validate($this->rules());

        return $this->mutate(fn () => $this->writer->update(
            $request->user(), $task, $update, ViewScope::forUser($request->user()),
            (int) $input['expected_update_version'], $input['body'], $input['mention_user_ids'] ?? [],
        ));
    }

    public function archive(Request $request, Task $task, TaskUpdate $update): JsonResponse
    {
        abort_unless($update->task_id === $task->id, Response::HTTP_NOT_FOUND);
        $input = $request->validate(['expected_update_version' => ['required', 'integer', 'min:1']]);

        return $this->mutate(fn () => $this->writer->archive(
            $request->user(), $task, $update, ViewScope::forUser($request->user()),
            (int) $input['expected_update_version'],
        ));
    }

    private function mutate(callable $callback): JsonResponse
    {
        try {
            return response()->json(['data' => $this->present($callback())]);
        } catch (TaskUpdateConflict $conflict) {
            return response()->json([
                'message' => $conflict->getMessage(),
                'meta' => ['current_update_version' => $conflict->currentVersion],
            ], Response::HTTP_CONFLICT);
        }
    }

    private function rules(bool $creating = false): array
    {
        return [
            'body' => [$creating ? 'required_without:attachment_candidate_ids' : 'required', 'string'],
            'mention_user_ids' => ['sometimes', 'array', 'max:100'],
            'mention_user_ids.*' => ['uuid', 'distinct'],
            'attachment_candidate_ids' => [$creating ? 'required_without:body' : 'prohibited', 'array', 'max:20'],
            'attachment_candidate_ids.*' => ['uuid', 'distinct'],
            'idempotency_key' => [$creating ? 'required' : 'prohibited', 'string', 'max:100'],
            'expected_update_version' => [$creating ? 'prohibited' : 'required', 'integer', 'min:1'],
        ];
    }

    private function present(TaskUpdate $update): array
    {
        return [
            'id' => $update->id, 'task_id' => $update->task_id,
            'author_user_id' => $update->author_user_id, 'body' => $update->body,
            'body_plain' => $update->body_plain, 'version' => (int) $update->version,
            'archived' => $update->archived_at !== null,
            'mention_user_ids' => $update->mentions->pluck('user_id')->values()->all(),
            'attachments' => $update->attachments->map(fn ($attachment) => $attachment->present())->values(),
            'versions' => $update->versions->map(fn ($version) => [
                'version' => (int) $version->version, 'body' => $version->body,
                'body_plain' => $version->body_plain, 'edited_by' => $version->edited_by,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->values(),
            'created_at' => $update->created_at?->toIso8601String(),
            'updated_at' => $update->updated_at?->toIso8601String(),
        ];
    }
}
