<?php

namespace App\Http\Controllers\Api;

use App\Models\Notification;
use App\Models\Suggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The Development-side queue: what everybody has asked for, and answering it.
 *
 * ── Why this reads across tenants ────────────────────────────────
 *
 * Everything else in the API is scoped to one organization by TenantContext.
 * This is the control plane: the whole point is to see every customer's
 * suggestion in one list, and it sits behind the `staff` middleware rather than
 * `tenant`. Queries here are deliberately unscoped and must stay in this
 * controller — the same query on a tenant-facing route is a leak, and for this
 * table it is a leak to the author's own colleagues as well as to strangers.
 */
class StaffSuggestionsController extends Controller
{
    /** GET /api/v1/staff/suggestions */
    public function index(): JsonResponse
    {
        $rows = Suggestion::query()
            ->withoutGlobalScopes()
            ->with(['organization:id,name', 'author:id,name'])
            // Undecided first, and among those the untouched ones — that is the
            // order somebody working the queue actually wants, rather than the
            // order things arrived.
            ->orderByRaw('CASE status WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                Suggestion::STATUS_NEW,
                Suggestion::STATUS_REVIEWING,
            ])
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->map(fn (Suggestion $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/staff/suggestions/{suggestion}/decide
     *
     * `new` is not accepted. It is the state of a suggestion nobody has touched,
     * and restoring it would hand the author back the right to rewrite text a
     * reviewer has already read. A second decide on an already-decided one IS
     * accepted — a reversal is a thing that happens — and replaces both the
     * status and the note.
     */
    public function decide(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', Suggestion::DECIDABLE)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var Suggestion|null $row */
        $row = Suggestion::query()->withoutGlobalScopes()->find($id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        $note = filled($input['note'] ?? null) ? trim((string) $input['note']) : null;

        if ($input['status'] === Suggestion::STATUS_DENIED && $note === null) {
            throw ValidationException::withMessages([
                'note' => 'Say why, in a sentence. The person who sent it reads it.',
            ]);
        }

        DB::transaction(function () use ($row, $input, $note, $request) {
            $row->status = $input['status'];
            $row->decision_note = $note;
            $row->decided_by = $request->user()?->getKey();
            $row->decided_at = now();
            $row->save();

            if (in_array($input['status'], Suggestion::ANNOUNCED, true)) {
                $this->tell($row);
            }
        });

        return response()->json(['data' => $this->row($row->fresh([
            'organization:id,name', 'author:id,name',
        ]))]);
    }

    /**
     * One notification row, composed here rather than by NotificationDispatcher.
     *
     * The dispatcher stamps organization_id from TenantContext::idOrFail, and a
     * staff request is in PLATFORM SCOPE with no tenant to ask — calling it here
     * would throw from the control plane. The suggestion already carries the
     * organization, so the row is written directly. Nothing else the dispatcher
     * does is wanted either: its audience resolution answers "who could have
     * seen this record", and this record has exactly one reader, already known.
     *
     * `source` is `system`, which NotificationDispatcher::SOURCE_SETTINGS leaves
     * out deliberately and permanently — so an organization's notification
     * toggles cannot suppress it. That is consistent with the rest of this
     * module: the organization does not control this channel.
     */
    private function tell(Suggestion $row): void
    {
        Notification::insert([
            'id' => (string) Str::uuid7(),
            'organization_id' => $row->organization_id,
            'user_id' => $row->user_id,
            'source' => 'system',
            'title' => $row->status === Suggestion::STATUS_APPROVED
                ? 'Your suggestion was approved'
                : 'Your suggestion was answered',
            'body' => $row->decision_note,
            'subject_type' => Suggestion::class,
            'subject_id' => $row->id,
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Suggestion $row): array
    {
        return [
            'id' => $row->id,
            'organization' => $row->organization?->name ?? 'Unknown',
            'organization_id' => $row->organization_id,
            'author' => $row->author?->name ?? 'Somebody who has since left',
            'body' => $row->body,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
