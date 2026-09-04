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

    /** PATCH /api/v1/suggestions/{suggestion} */
    public function update(Request $request, string $id): JsonResponse
    {
        $input = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $row = $this->mine($request, $id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        if ($row->status !== Suggestion::STATUS_NEW) {
            return $this->frozen($row);
        }

        $body = trim($input['body']);

        if ($body === '') {
            return response()->json([
                'message' => 'Write the suggestion first.',
                'errors' => ['body' => ['Write the suggestion first.']],
            ], 422);
        }

        $row->body = $body;
        $row->save();

        return response()->json(['data' => $this->row($row)]);
    }

    /** DELETE /api/v1/suggestions/{suggestion} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = $this->mine($request, $id);

        if ($row === null) {
            return response()->json(['message' => 'That suggestion no longer exists.'], 404);
        }

        if ($row->status !== Suggestion::STATUS_NEW) {
            return $this->frozen($row);
        }

        $row->delete();

        return response()->json([], 204);
    }

    /**
     * Yours, or nothing.
     *
     * 404 rather than 403 for a colleague's row, deliberately: 403 would confirm
     * that a suggestion with that id exists, which is a fact this module keeps
     * from them.
     */
    private function mine(Request $request, string $id): ?Suggestion
    {
        return Suggestion::query()
            ->where('user_id', $request->user()->getKey())
            ->find($id);
    }

    /**
     * Why it can no longer be touched, in words that name the actual state.
     *
     * Two different sentences because they are two different situations to be
     * in: one is "somebody is reading it right now", the other is "this has been
     * answered and the answer is on screen above".
     */
    private function frozen(Suggestion $row): JsonResponse
    {
        $message = $row->status === Suggestion::STATUS_REVIEWING
            ? 'Somebody at EzBizi is reading this one, so it is fixed while they do. '
                .'Send another if there is more to say.'
            : 'This one has been answered, so it stays as it was written. '
                .'Send another if there is more to say.';

        return response()->json([
            'message' => $message,
            'errors' => ['body' => [$message]],
        ], 422);
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
