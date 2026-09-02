<?php

namespace App\Http\Controllers\Api;

use App\Models\Suggestion;
use Illuminate\Http\JsonResponse;

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
