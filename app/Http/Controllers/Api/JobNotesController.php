<?php

namespace App\Http\Controllers\Api;

use App\Models\Job;
use App\Models\JobNote;
use App\Support\Audit\AuditLogger;
use App\Support\Notes\NoteActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * A job's notes (step c of moving off Workiz, Jose 2026-10-20). Whoever may edit
 * the job may write on it. No edit or delete: a note records what somebody
 * said, and the next note corrects it.
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

        return response()->json(['data' => self::present($note->load('user:id,name'))], Response::HTTP_CREATED);
    }

    /** @return array{id: string, body: string, author: array{id: string, name: string}|null, created_at: string|null} */
    public static function present(JobNote $note): array
    {
        return [
            'id' => $note->getKey(),
            'body' => $note->body,
            'mentions' => $note->mentions ?? [],
            'author' => $note->user ? ['id' => $note->user->getKey(), 'name' => $note->user->name] : null,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }
}
