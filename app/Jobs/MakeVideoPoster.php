<?php

namespace App\Jobs;

use App\Models\Attachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;

/**
 * One poster frame (jpg, 1s in) beside a Task Board video, for the Gallery
 * tile (Jose 2026-11-12). Skips quietly when ffmpeg is not installed, the file
 * is encrypted or on a sensitive task, or a poster already exists. Queued on create; backfill with
 * `attachments:video-posters`.
 */
class MakeVideoPoster implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $attachmentId) {}

    public static function posterPath(Attachment $a): string
    {
        return $a->path.'.poster.jpg';
    }

    public static function wants(Attachment $a): bool
    {
        // Never for a sensitive task's video (encrypted, name sealed): a poster is a frame in the clear.
        return $a->attachable_type === 'task_updates' && str_starts_with((string) $a->mime, 'video/')
            && ! $a->encrypted && $a->sealed_name === null;
    }

    public function handle(): void
    {
        $a = Attachment::withoutGlobalScopes()->find($this->attachmentId);
        $ffmpeg = (new ExecutableFinder)->find('ffmpeg');

        if (! $a || ! self::wants($a) || $ffmpeg === null) {
            return;
        }

        $disk = Storage::disk($a->disk ?? config('filesystems.default'));

        if (! $disk->exists($a->path) || $disk->exists(self::posterPath($a))) {
            return;
        }

        Process::timeout(110)->run([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-ss', '1',
            '-i', $disk->path($a->path), '-frames:v', '1', '-vf', 'scale=480:-2', '-y', $disk->path(self::posterPath($a))]);
    }
}
