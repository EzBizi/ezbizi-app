<?php

namespace App\Http\Controllers\Api;

use App\Jobs\MakeVideoPoster;
use App\Models\Attachment;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Tasks\SensitiveTasks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/attachments/{id}/stream — a Task Board video (or its poster) for
 * a player that cannot send our bearer token (Gallery, Jose 2026-11-12).
 *
 * The signature is the authentication: a 10-minute link naming the user it was
 * issued to. Board visibility is asked again for that user on every request,
 * so a link outlives nobody's access. Served as a file response so Range works
 * and the player can seek. The link is never logged: this route sits outside
 * the API-key middleware (the only request logger that records a query).
 */
class AttachmentStreamController extends Controller
{
    public const MINUTES = 10;

    /**
     * [session] (SensitiveTasks::sessionOf) is named only by a caller that has
     * passed SensitiveTasks::reveal: a sensitive task's video plays only while
     * that session's authenticator unlock lasts.
     */
    public static function url(Attachment $a, User $user, bool $poster = false, ?string $session = null): string
    {
        return URL::temporarySignedRoute('attachments.stream', now()->addMinutes(self::MINUTES),
            ['attachment' => $a->getKey(), 'user' => $user->getKey()] + ($poster ? ['poster' => 1] : [])
            + ($session !== null ? ['session' => $session] : []));
    }

    public function show(Request $request, string $attachment, TenantContext $tenant): Response
    {
        $a = Attachment::withoutGlobalScopes()->findOrFail($attachment);
        abort_unless($a->attachable_type === 'task_updates' && str_starts_with((string) $a->mime, 'video/'), 404);

        $tenant->run($a->organization, function () use ($a, $request) {
            $user = User::query()->findOrFail((string) $request->query('user'));
            $task = TaskUpdate::query()->with('task.board')->find($a->attachable_id)?->task;
            $board = $task?->board;
            abort_unless($board && Gate::forUser($user)->allows('view', $board), 404);

            // A sensitive task's video (Jose, 2026-11-12): never a poster, and
            // the bytes only while the session the link names is unlocked. A
            // link signed before the task was marked carries no session.
            if ($task->sensitive) {
                abort_if($request->boolean('poster'), 404);
                abort_unless(app(SensitiveTasks::class)->unlockedFor($user,
                    is_string($request->query('session')) ? $request->query('session') : null, $task, 'stream'), 403,
                    'This task is sensitive. Unlock it with your authenticator first.');
            }
        });

        $disk = Storage::disk($a->disk ?? config('filesystems.default'));

        if ($request->boolean('poster')) {
            $poster = MakeVideoPoster::posterPath($a);
            abort_unless($disk->exists($poster), 404);

            return response($disk->get($poster), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=600']);
        }

        // ponytail: encrypted videos stream whole (no seeking); none are encrypted today.
        if ($a->encrypted) {
            $bytes = $a->bytes();
            abort_if($bytes === null, 404);

            return response($bytes, 200, ['Content-Type' => $a->mime, 'Cache-Control' => 'private, no-store']);
        }

        abort_unless($disk->exists($a->path), 404);

        // A BinaryFileResponse answers Range / 206 itself.
        return response()->file($disk->path($a->path), [
            'Content-Type' => $a->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
