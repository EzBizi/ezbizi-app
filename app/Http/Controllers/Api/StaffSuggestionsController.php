<?php

namespace App\Http\Controllers\Api;

use App\Models\Concerns\TenantScope;
use App\Models\Notification;
use App\Models\Suggestion;
use App\Support\Files\FileStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));
        $like = '%'.addcslashes($term, '%_\\').'%';

        $rows = Suggestion::query()
            ->withoutGlobalScopes()
            ->with(['organization:id,name', 'author:id,name'])
            // Every tenant's, like the list itself: the related reads drop the
            // tenant scope for the same reason the outer one does.
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('body', 'like', $like)
                ->orWhere('module', 'like', $like)
                ->orWhere('decision_note', 'like', $like)
                ->orWhereHas('organization', fn ($o) => $o->where('name', 'like', $like))
                ->orWhereHas('author', fn ($u) => $u->withoutGlobalScope(TenantScope::class)->where('name', 'like', $like))))
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

    /** GET /api/v1/staff/suggestions/{suggestion}/files/{index} */
    public function file(FileStore $store, string $id, int $index): StreamedResponse
    {
        $row = Suggestion::query()->withoutGlobalScopes()->find($id);

        abort_if($row === null, 404);

        return SuggestionsController::serve($store, $row, $index);
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

        /**
         * This used to be enforced by a disabled button on the queue screen,
         * and a disabled button is not a guarantee. "In review" tells the
         * author nothing — the bell stays silent on purpose — so a note
         * attached to it would land on their screen with no notice that
         * anything had changed. Approve it or deny it if there is something
         * to say.
         */
        if ($input['status'] === Suggestion::STATUS_REVIEWING && $note !== null) {
            throw ValidationException::withMessages([
                'note' => 'In review tells them nothing — this note would show up on their screen with no notice. Approve or deny it if there is something to say.',
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
     *
     * ── IF YOU ARE ADDING EMAIL TO THE DISPATCHER, READ THIS ────────
     *
     * This bypass costs nothing today because the dispatcher writes a row and
     * nothing else. The day it also sends mail, **every module gains email
     * except this one**, silently — no error, no failing test, just an author
     * who is told on the bell and never by post. Nothing here would fail; the
     * row is still written and the bell still lights.
     *
     * The reason for the bypass does not go away (a staff request has no tenant
     * for `idOrFail` to find), so the fix is not to route this through the
     * dispatcher. It is to give the dispatcher a path that takes an explicit
     * organization, and move this call onto it.
     *
     * Written here rather than in a document because this is the file you will
     * have open. Recorded 2026-09-14, the day SES production access landed and
     * made "somebody adds email" a question of when.
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
            'module' => $row->module,
            'module_label' => $row->moduleLabel(),
            'files' => $row->presentFiles(),
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
