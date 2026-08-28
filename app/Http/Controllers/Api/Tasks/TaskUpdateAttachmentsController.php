<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Support\Access\Refusal;
use App\Support\Files\AttachmentUploader;
use App\Support\Files\UploadLimits;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\BoardVisibility;
use App\Support\Tasks\TaskBoardAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class TaskUpdateAttachmentsController extends TaskApiController
{
    public function __construct(
        private readonly AttachmentUploader $uploader,
        private readonly UploadLimits $limits,
        private readonly TaskBoardAccess $access,
    ) {}

    public function store(Request $request, Task $task): JsonResponse
    {
        // This is deliberately before every file validation response: someone
        // who cannot comment must not learn which upload shape would be valid.
        $this->authorize('comment', $task);
        $this->limits->assertUploadArrived($request, 'file');
        $request->validate(
            $this->limits->rulesFor('file'),
            $this->limits->messagesFor('file'),
        );

        $actor = $request->user();
        $scope = ViewScope::forUser($actor);
        $candidate = null;
        $attachment = $this->uploader->upload(
            $actor,
            $task,
            $request->file('file'),
            'tasks',
            prepareSubject: function () use ($actor, $task, $scope): Model {
                $board = TaskBoard::query()->active()->lockForUpdate()->findOrFail($task->board_id);
                if ($board->visibility === BoardVisibility::Private) {
                    TaskBoardMember::query()->where('board_id', $board->id)
                        ->where('user_id', $actor->id)->lockForUpdate()->first();
                }
                $this->access->viewDecision($actor, $board, $scope)->authorize();
                if (! $actor->hasPermission('tasks.comment')) {
                    Refusal::permission('comment on this task')->authorize();
                }
                if ($actor->organization?->isReadOnly()) {
                    throw ValidationException::withMessages([
                        'organization' => 'This organization is read-only because its subscription has ended.',
                    ]);
                }

                return Task::query()->where('board_id', $board->id)->active()
                    ->lockForUpdate()->findOrFail($task->id);
            },
            afterPersist: function (Attachment $stored, Model $locked) use ($actor, &$candidate): void {
                $candidate = TaskUpdateAttachmentCandidate::create([
                    'board_id' => $locked->board_id,
                    'task_id' => $locked->id,
                    'attachment_id' => $stored->id,
                    'actor_user_id' => $actor->id,
                    'expires_at' => now()->addDay(),
                ]);
            },
        );

        return response()->json(['data' => [
            'id' => $attachment->id,
            'original_name' => $attachment->original_name,
            'mime' => $attachment->mime,
            'size_bytes' => (int) $attachment->size_bytes,
            'checksum' => $attachment->checksum,
            'uploaded_at' => $attachment->created_at?->toIso8601String(),
            'expires_at' => $candidate?->expires_at?->toIso8601String(),
        ]], Response::HTTP_CREATED);
    }
}
