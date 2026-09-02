<?php

namespace App\Http\Controllers\Api;

use App\Models\Suggestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The author's half of Suggestions.
 *
 * ── Why every query filters user_id as well as the tenant ────────
 *
 * BelongsToTenant already scopes reads to the organization, and for every other
 * module that is the whole boundary. Here it is only half of one: the point of
 * this table is that a colleague — inside the same tenant, possibly holding
 * every permission the organization can grant — must not be able to read it.
 * So the filter is explicit on every path, including the ones that look like
 * they cannot need it.
 *
 * ── Why there is no permission check ─────────────────────────────
 *
 * There is no permission to check. Suggestions is deliberately absent from
 * ModuleRegistry so that no organization can take it away, which means
 * PermissionCatalogue publishes no key for it and Role::hasPermission has
 * nothing to answer. Anybody signed in reaches this. See Suggestion's docblock.
 */
class SuggestionsController extends Controller
{
    /** GET /api/v1/suggestions — your own, newest first. */
    public function index(Request $request): JsonResponse
    {
        $rows = Suggestion::query()
            ->where('user_id', $request->user()->getKey())
            ->latest('created_at')
            ->get()
            ->map(fn (Suggestion $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/suggestions */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $body = trim($input['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Write the suggestion first.',
                'errors' => ['body' => ['Write the suggestion first.']],
            ], 422);
        }

        $row = Suggestion::create([
            'user_id' => $request->user()->getKey(),
            'body' => $body,
            'status' => Suggestion::STATUS_NEW,
        ]);

        return response()->json(['data' => $this->row($row)], 201);
    }

    /** @return array<string, mixed> */
    private function row(Suggestion $row): array
    {
        return [
            'id' => $row->id,
            'body' => $row->body,
            'status' => $row->status,
            'decision_note' => $row->decision_note,
            'decided_at' => $row->decided_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }
}
