<?php

namespace App\Http\Controllers\Api;

use App\Models\FieldRecording;
use App\Models\Job;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Field\FieldTracking;
use App\Support\Files\FileStore;
use App\Support\Scoping\ViewScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Field safety recordings (ported from A1 Tools, 2026-10-16).
 *
 * The technician records (field.record): begin, one upload per minute, finish.
 * Their own list and playback are theirs. Everyone else's is field.listen,
 * within the people Field shows you; keeping one past ninety days is field.manage.
 */
final class FieldRecordingsController extends Controller
{
    public function __construct(
        private readonly FieldTracking $tracking,
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
    ) {}

    public function begin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'started_at' => ['required', 'date'],
            'job_id' => ['nullable', 'uuid'],
            'disclosure_confirmed' => ['accepted'],
            'customer_told' => ['nullable', 'boolean'],
        ]);
        $job = isset($data['job_id']) ? Job::query()->with('customer:id,state')->whereKey($data['job_id'])
            ->whereIn('franchise_id', ViewScope::forUser($request->user())->franchiseIds())->first() : null;

        // Decided here, not on the phone: an old build, or one that skips the
        // card, still cannot record where it may not.
        $rule = $this->tracking->recordingRule($request->user(), $job?->customer?->state);
        if (! $rule['allowed']) {
            throw new AuthorizationException($rule['reason']);
        }
        if ($rule['announce'] && empty($data['customer_told'])) {
            return response()->json(['message' => 'Tell the customer first: "'.$rule['notice'].'"'], 422);
        }
        $jobId = $job?->getKey();

        $recording = FieldRecording::query()->create([
            'customer_told' => (bool) ($data['customer_told'] ?? false),
            'user_id' => $request->user()->getKey(),
            'job_id' => $jobId,
            'started_at' => Carbon::parse($data['started_at'])->utc(),
        ]);

        $this->audit->log('field.recording_started', $recording,
            metadata: ['job_id' => $jobId, 'customer_told' => $recording->customer_told]);

        return response()->json(['data' => ['id' => $recording->getKey()]], 201);
    }

    /** One minute of audio. Sending the same seq twice is answered, not stored twice. */
    public function chunk(Request $request, FieldRecording $recording): JsonResponse
    {
        $this->assertOwn($request->user(), $recording);
        $data = $request->validate([
            'seq' => ['required', 'integer', 'min:0'],
            'started_at' => ['required', 'date'],
            'duration_ms' => ['required', 'integer', 'min:0', 'max:600000'],
            'sha256' => ['required', 'string', 'size:64'],
            // ponytail: 5 MB is ~20 min of 32 kbps AAC; a minute is ~240 KB.
            'audio' => ['required', 'file', 'max:5120'],
        ]);

        $existing = $recording->chunks()->where('seq', $data['seq'])->first();
        if ($existing !== null) {
            return response()->json(['data' => ['seq' => $existing->seq, 'stored' => true]]);
        }

        $bytes = (string) file_get_contents($request->file('audio')->getRealPath());
        if (! hash_equals(strtolower($data['sha256']), hash('sha256', $bytes))) {
            return response()->json(['message' => 'The audio arrived damaged. The phone sends it again.'], 422);
        }

        $path = $this->files->putContents($bytes, $this->directory($recording), 'm4a');
        $recording->chunks()->create([
            'seq' => $data['seq'],
            'started_at' => Carbon::parse($data['started_at'])->utc(),
            'duration_ms' => $data['duration_ms'],
            'bytes' => strlen($bytes),
            'sha256' => strtolower($data['sha256']),
            'path' => $path,
        ]);
        $recording->forceFill([
            'chunk_count' => $recording->chunks()->count(),
            'total_bytes' => (int) $recording->chunks()->sum('bytes'),
            'duration_s' => intdiv((int) $recording->chunks()->sum('duration_ms'), 1000),
        ])->save();

        return response()->json(['data' => ['seq' => (int) $data['seq'], 'stored' => true]], 201);
    }

    public function finish(Request $request, FieldRecording $recording): JsonResponse
    {
        $this->assertOwn($request->user(), $recording);
        $data = $request->validate([
            'ended_at' => ['required', 'date'],
            'stop_reason' => ['required', 'in:'.implode(',', FieldRecording::STOP_REASONS)],
            'events' => ['nullable', 'array', 'max:500'],
            'events.*.at' => ['required', 'date'],
            'events.*.type' => ['required', 'string', 'max:40'],
        ]);

        $recording->forceFill([
            'ended_at' => Carbon::parse($data['ended_at'])->utc(),
            'stop_reason' => $data['stop_reason'],
            'events' => collect($data['events'] ?? [])
                ->map(fn ($e) => ['at' => Carbon::parse($e['at'])->utc()->toIso8601ZuluString(), 'type' => $e['type']])
                ->all(),
        ])->save();

        return response()->json(['data' => $this->present($recording)]);
    }

    /** Yours, or with field.listen everyone's you can see on Field. ?user_id narrows, ?date picks a day. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['user_id' => ['nullable', 'uuid'], 'date' => ['nullable', 'date_format:Y-m-d']]);
        $actor = $request->user();

        $query = FieldRecording::query()->with(['user:id,name', 'job:id,number,title'])->latest('started_at');
        if ($actor->hasPermission('field.listen')) {
            $query->whereIn('user_id', $this->tracking->visiblePeople($actor)->select('id'));
            if (isset($data['user_id'])) {
                $query->where('user_id', $data['user_id']);
            }
        } else {
            $query->where('user_id', $actor->getKey());
        }
        if (isset($data['date'])) {
            $query->whereDate('started_at', $data['date']);
        }

        return response()->json(['data' => $query->limit(200)->get()->map(fn ($r) => $this->present($r))]);
    }

    public function audio(Request $request, FieldRecording $recording, int $seq): StreamedResponse
    {
        $this->assertMayHear($request->user(), $recording);
        $chunk = $recording->chunks()->where('seq', $seq)->firstOrFail();

        return $this->files->serve($chunk->path, mime: 'audio/mp4', filename: "recording-{$seq}.m4a", disposition: 'inline');
    }

    /** Keep past ninety days (field.manage, about somebody Field shows you). */
    public function flag(Request $request, FieldRecording $recording): JsonResponse
    {
        if (! $request->user()->hasPermission('field.manage') || ! $this->sees($request->user(), $recording)) {
            throw new AuthorizationException('Keeping a recording needs the Field "manage" grant.');
        }
        $data = $request->validate(['flagged' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $recording->forceFill([
            'flagged' => $data['flagged'],
            'flag_reason' => $data['flagged'] ? ($data['reason'] ?? null) : null,
            'flagged_by' => $data['flagged'] ? $request->user()->getKey() : null,
        ])->save();
        $this->audit->log($data['flagged'] ? 'field.recording_kept' : 'field.recording_unkept', $recording);

        return response()->json(['data' => $this->present($recording)]);
    }

    private function directory(FieldRecording $recording): string
    {
        return $this->files->directoryFor('field-audio', $recording->organization_id).'/'.$recording->getKey();
    }

    private function assertOwn(User $actor, FieldRecording $recording): void
    {
        if ($recording->user_id !== $actor->getKey()) {
            throw new AuthorizationException('Only the person who made a recording can add to it.');
        }
    }

    /** Your own, always; somebody else's with field.listen, if Field shows you them. */
    private function assertMayHear(User $actor, FieldRecording $recording): void
    {
        if ($recording->user_id !== $actor->getKey()
            && (! $actor->hasPermission('field.listen') || ! $this->sees($actor, $recording))) {
            throw new AuthorizationException('Listening to other people\'s recordings needs the Field "listen" grant.');
        }
    }

    private function sees(User $actor, FieldRecording $recording): bool
    {
        return $this->tracking->visiblePeople($actor)->whereKey($recording->user_id)->exists();
    }

    private function present(FieldRecording $r): array
    {
        return [
            'id' => $r->getKey(),
            'user' => ['id' => $r->user_id, 'name' => $r->user?->name],
            'job' => $r->job ? ['id' => $r->job->id, 'number' => $r->job->number, 'title' => $r->job->title] : null,
            'started_at' => $r->started_at->toIso8601ZuluString(),
            'ended_at' => $r->ended_at?->toIso8601ZuluString(),
            'stop_reason' => $r->stop_reason,
            'duration_s' => $r->duration_s,
            'chunks' => $r->chunk_count,
            'events' => $r->events ?? [],
            'customer_told' => $r->customer_told,
            'flagged' => $r->flagged,
            'flag_reason' => $r->flag_reason,
            'delete_on' => $r->flagged ? null : $r->started_at->copy()->addDays(FieldRecording::KEEP_DAYS)->toDateString(),
        ];
    }
}
