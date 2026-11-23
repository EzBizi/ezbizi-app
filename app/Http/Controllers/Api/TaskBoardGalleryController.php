<?php

namespace App\Http\Controllers\Api;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskUpdate;
use App\Support\Tasks\SensitiveTasks;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Every file on a board, newest first (Task Board Gallery, Jose 2026-11-12).
 *
 * Files live on task updates (morph `task_updates`); a subtask is a task on
 * the same board, so its updates' files are included. The board's own `view`
 * decides, exactly as for the board; the file bytes still go through
 * GET attachments/{id}, which checks again.
 */
class TaskBoardGalleryController extends Controller
{
    public const PAGE = 60;

    public function index(Request $request, TaskBoard $taskBoard): JsonResponse
    {
        $this->authorize('view', $taskBoard);
        $input = $request->validate([
            'type' => ['sometimes', 'in:photo,video,file'],
            'person' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'cursor' => ['sometimes', 'string'],
        ]);

        // A sensitive task's files: a locked tile (no name, no thumbnail, no
        // link); an API key or the AI is not shown them at all. A type filter
        // leaves them out too, or it would say what kind of file they are.
        $hidden = SensitiveTasks::machineReader($request->user()) || isset($input['type']);
        $updates = TaskUpdate::query()
            ->whereIn('task_id', Task::query()->where('board_id', $taskBoard->id)
                ->when($hidden, fn (Builder $q) => $q->where('sensitive', false))->select('id'))
            ->when($input['person'] ?? null, fn (Builder $q, $p) => $q->where('user_id', $p));

        $query = Attachment::query()
            ->where('attachable_type', 'task_updates')
            ->whereIn('attachable_id', (clone $updates)->select('id'))
            ->when($input['type'] ?? null, function (Builder $q, string $type) {
                match ($type) {
                    'photo' => $q->where('mime', 'like', 'image/%'),
                    'video' => $q->where('mime', 'like', 'video/%'),
                    'file' => $q->where('mime', 'not like', 'image/%')->where('mime', 'not like', 'video/%'),
                };
            })
            ->when($input['from'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '>=', $d))
            ->when($input['to'] ?? null, fn (Builder $q, $d) => $q->where('created_at', '<', now()->parse($d)->addDay()))
            ->orderByDesc('created_at')->orderByDesc('id');

        $page = $query->cursorPaginate(self::PAGE);
        $byUpdate = TaskUpdate::query()->with(['task:id,title,sensitive', 'user:id,name'])
            ->whereKey($page->getCollection()->pluck('attachable_id')->unique()->values())
            ->get()->keyBy('id');

        $people = null;
        if (! isset($input['cursor'])) {
            $people = TaskUpdate::query()
                ->whereIn('task_id', Task::query()->where('board_id', $taskBoard->id)->select('id'))
                ->whereIn('id', Attachment::query()->where('attachable_type', 'task_updates')->select('attachable_id'))
                ->with('user:id,name')->get()->pluck('user')->filter()->unique('id')
                ->sortBy('name')->values()->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->all();
        }

        $viewer = $request->user();

        return response()->json([
            'data' => $page->getCollection()->map(function (Attachment $a) use ($byUpdate, $viewer) {
                $u = $byUpdate->get($a->attachable_id);
                $url = url("/api/v1/attachments/{$a->id}");
                $mime = (string) $a->mime;
                $inline = in_array($mime, AttachmentsController::INLINE_MIMES, true);

                if ($u?->task?->sensitive) {
                    return [
                        'id' => $a->id,
                        'locked' => true,
                        'kind' => 'file',
                        'name' => null,
                        'mime' => null,
                        'size' => null,
                        'created_at' => $a->created_at?->toIso8601String(),
                        'uploader' => $u->user ? ['id' => $u->user->id, 'name' => $u->user->name] : null,
                        'task' => ['id' => $u->task->id, 'name' => $u->task->title, 'sensitive' => true],
                        'url' => null,
                        'inline_url' => null,
                        'thumbnail_url' => null,
                        'stream_url' => null,
                        'poster_url' => null,
                    ];
                }

                return [
                    'id' => $a->id,
                    'kind' => str_starts_with($mime, 'image/') ? 'photo' : (str_starts_with($mime, 'video/') ? 'video' : 'file'),
                    'name' => $a->original_name,
                    'mime' => $mime,
                    'size' => (int) $a->size_bytes,
                    'created_at' => $a->created_at?->toIso8601String(),
                    'uploader' => $u?->user ? ['id' => $u->user->id, 'name' => $u->user->name] : null,
                    'task' => $u?->task ? ['id' => $u->task->id, 'name' => $u->task->title] : null,
                    'url' => $url,
                    'inline_url' => $inline ? $url.'?inline=1' : null,
                    'thumbnail_url' => $inline && str_starts_with($mime, 'image/') ? $url.'?inline=1' : null,
                    // Signed, 10 minutes, for a player that cannot send the token.
                    'stream_url' => str_starts_with($mime, 'video/') ? AttachmentStreamController::url($a, $viewer) : null,
                    'poster_url' => str_starts_with($mime, 'video/') && ! $a->encrypted
                        && \Illuminate\Support\Facades\Storage::disk($a->disk ?? config('filesystems.default'))->exists(\App\Jobs\MakeVideoPoster::posterPath($a))
                        ? AttachmentStreamController::url($a, $viewer, poster: true) : null,
                ];
            })->values(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'people' => $people,
        ]);
    }
}
