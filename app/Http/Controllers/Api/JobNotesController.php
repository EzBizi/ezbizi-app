<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\JobNote;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Notes\NoteActivity;
use App\Support\Workiz\WorkizJobWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A job's notes (step c of moving off Workiz, Jose 2026-10-20). Whoever may edit
 * the job may write on it. Its author, or an administrator, may change or
 * remove a note (Jose, 2026-11-17: "i cannot edit notes on project
 * management"), and Workiz's own job notes are edited in place and written
 * back to Workiz.
 */
class JobNotesController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly NoteActivity $notes) {}

    /** POST /api/v1/jobs/{job}/notes */
    public function store(Request $request, Job $job): JsonResponse
    {
        $this->authorize('update', $job);

        $input = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
            'mentions' => ['sometimes', 'array', 'max:50'],
            'mentions.*' => ['uuid'],
        ]);
        $body = trim((string) $input['body']);
        abort_if($body === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'A note needs some words.');

        $note = DB::transaction(function () use ($job, $body, $request, $input) {
            $mentions = $this->notes->mention($job, $input['mentions'] ?? [], $request->user(), $body);
            $note = JobNote::create(['job_id' => $job->getKey(), 'user_id' => $request->user()->getKey(), 'body' => $body, 'mentions' => $mentions ?: null]);
            $this->audit->log('job.note_added', $job, metadata: ['note_id' => $note->getKey()]);

            return $note;
        });

        return response()->json(['data' => self::present($note->load('user:id,name'), $request->user())], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/jobs/{job}/notes/{note}: its author, or an administrator. */
    public function update(Request $request, Job $job, string $note): JsonResponse
    {
        $row = $this->changeable($request, $job, $note);
        $body = trim((string) $request->validate(['body' => ['required', 'string', 'max:10000']])['body']);
        abort_if($body === '', Response::HTTP_UNPROCESSABLE_ENTITY, 'A note needs some words.');
        $row->update(['body' => $body, 'edited_at' => now()]);
        $this->audit->log('job.note_edited', $job, metadata: ['note_id' => $row->getKey()]);

        return response()->json(['data' => self::present($row->load('user:id,name'), $request->user())]);
    }

    /** DELETE /api/v1/jobs/{job}/notes/{note}: its author, or an administrator. */
    public function destroy(Request $request, Job $job, string $note): JsonResponse
    {
        $row = $this->changeable($request, $job, $note);
        $row->delete();
        $this->audit->log('job.note_deleted', $job, metadata: ['note_id' => $note]);

        return response()->json(['data' => ['id' => $note]]);
    }

    /**
     * PUT /api/v1/jobs/{job}/workiz-notes: Workiz's job notes, edited here
     * and written back to the Workiz job (its v2 description). A refusal
     * leaves both as they were and says why, so the text is not lost.
     */
    public function workiz(Request $request, Job $job, WorkizJobWriter $writer): JsonResponse
    {
        $this->authorize('update', $job);
        $in = $request->validate([
            'text' => ['present', 'nullable', 'string', 'max:20000'],
            // What the editor opened: Workiz's must still say it (compare-and-set).
            'was' => ['present', 'nullable', 'string', 'max:20000'],
        ]);
        // Plain text: Workiz shows a description as written, markup and all.
        $text = trim(strip_tags((string) ($in['text'] ?? '')));
        $mirror = $job->workizJob;
        abort_if($mirror === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'This job is not in Workiz.');

        $result = $writer->setDescription($mirror, $text, (string) ($in['was'] ?? ''));
        if ($result['refused'] !== null) {
            return response()->json(['message' => $result['refused'], 'current' => $result['current'] ?? null], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $notes = $result['job']?->payload['JobNotes'] ?? $text;

        return response()->json(['data' => ['workiz_notes' => is_string($notes) && trim($notes) !== '' ? trim($notes) : null]]);
    }

    private function changeable(Request $request, Job $job, string $note): JobNote
    {
        $this->authorize('update', $job);
        $row = JobNote::query()->where('job_id', $job->getKey())->find($note);
        abort_if($row === null, Response::HTTP_NOT_FOUND, 'That note is gone.');
        abort_unless($row->changeableBy($request->user()), Response::HTTP_FORBIDDEN, 'Only who wrote a note, or an administrator, can change it.');

        return $row;
    }

    /** @return array{id: string, body: string, author: array{id: string, name: string}|null, created_at: string|null} */
    public static function present(JobNote $note, ?User $viewer = null): array
    {
        $viewer ??= request()->user();

        return [
            'id' => $note->getKey(),
            'body' => $note->body,
            'mentions' => $note->mentions ?? [],
            'author' => $note->user ? ['id' => $note->user->getKey(), 'name' => $note->user->name] : null,
            'created_at' => $note->created_at?->toIso8601String(),
            'edited_at' => $note->edited_at?->toIso8601String(),
            'can_change' => $viewer instanceof User && $note->changeableBy($viewer),
        ];
    }
}
