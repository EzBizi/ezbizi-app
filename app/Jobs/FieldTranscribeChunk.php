<?php

namespace App\Jobs;

use App\Models\FieldRecording;
use App\Models\FieldRecordingChunk;
use App\Models\Organization;
use App\Services\Leads\CallTranscripts;
use App\Support\Files\FileStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * One minute of a Field recording through Whisper with VAD: the speech is
 * kept as segments at clock time, and the audio is deleted (Jose: never
 * store recordings). Silence costs a few seconds and leaves nothing.
 */
class FieldTranscribeChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    // Whisper is given 120 s; `database-long` has retry_after 200.
    public int $timeout = 150;

    public function __construct(public string $chunkId)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(CallTranscripts $whisper, FileStore $files, TenantContext $tenant): void
    {
        $chunk = FieldRecordingChunk::query()->find($this->chunkId);
        $recording = $chunk ? FieldRecording::query()->withoutGlobalScopes()->find($chunk->recording_id) : null;
        if ($chunk === null || $chunk->transcribed_at !== null || $chunk->path === null || $recording === null) {
            return;
        }

        $disk = Storage::disk($files->defaultDisk());
        $bytes = $disk->get($chunk->path);
        $segments = $bytes === null || $bytes === '' ? [] : $whisper->whisperSegments($bytes);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($recording->organization_id), function () use ($chunk, $recording, $segments) {
            foreach ($segments as $s) {
                $text = trim((string) ($s['text'] ?? ''));
                if ($text === '') {
                    continue;
                }
                $recording->segments()->create([
                    'started_at' => $chunk->started_at->copy()->addMilliseconds((int) round($s['start_s'] * 1000)),
                    'ended_at' => $chunk->started_at->copy()->addMilliseconds((int) round($s['end_s'] * 1000)),
                    'text' => $text,
                ]);
            }
        });

        $disk->delete($chunk->path);
        $chunk->forceFill(['path' => null, 'transcribed_at' => now()])->save();

        self::summarizeIfDone($recording->fresh());
    }

    /** Once the recording is closed and every minute is words, summarise it. */
    public static function summarizeIfDone(FieldRecording $recording): void
    {
        if ($recording->ended_at !== null && $recording->summarized_at === null
            && ! $recording->chunks()->whereNull('transcribed_at')->exists()) {
            FieldSummarizeRecording::dispatch($recording->getKey());
        }
    }
}
