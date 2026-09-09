<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneBlockedNumber;
use App\Models\PhoneConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The callers a company will not take a call from.
 *
 * ── Why blocking is an `update` on the connection ────────────────
 *
 * Adding a number here changes what the phone system does with an incoming
 * call, which is the same kind of decision as pointing a line at a different
 * ring group. It is not a new permission: somebody trusted to say which line
 * rings whom is trusted to say which caller rings nobody, and a separate
 * `phone.block` would be a permission nobody remembers to grant, on a screen
 * that then looks broken.
 */
class BlockedNumbersController extends Controller
{
    /** GET /api/v1/phone/blocked-numbers */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $connections = $this->reachableConnectionIds($request);
        if ($connections === []) {
            return response()->json(['data' => []]);
        }

        $rows = PhoneBlockedNumber::query()
            ->whereIn('connection_id', $connections)
            ->with('blocker:id,name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $rows->map(fn (PhoneBlockedNumber $row) => $this->row($row))->all(),
        ]);
    }

    /** POST /api/v1/phone/blocked-numbers */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'e164' => ['required', 'string', 'max:32', 'regex:/^\+[1-9]\d{6,18}$/'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        // Blocking a caller who is already blocked is not an error — it is
        // somebody pressing the button again because the screen was stale.
        // The unique index would make it a 500, so it is an upsert instead.
        $row = PhoneBlockedNumber::query()->firstOrNew([
            'connection_id' => $connection->id,
            'e164' => $input['e164'],
        ]);

        $row->fill([
            'organization_id' => $connection->organization_id,
            'reason' => $input['reason'] ?? $row->reason,
            'blocked_by' => $row->exists ? $row->blocked_by : $request->user()?->id,
        ])->save();

        return response()->json(['data' => $this->row($row->fresh(['blocker']))], Response::HTTP_CREATED);
    }

    /** DELETE /api/v1/phone/blocked-numbers/{blocked} */
    public function destroy(Request $request, PhoneBlockedNumber $blocked): JsonResponse
    {
        $connection = $blocked->connection;
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        $blocked->delete();

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function row(PhoneBlockedNumber $blocked): array
    {
        return [
            'id' => $blocked->id,
            'connection_id' => $blocked->connection_id,
            'e164' => $blocked->e164,
            'reason' => $blocked->reason,
            'blocked_by_name' => $blocked->blocker?->name,
            'created_at' => $blocked->created_at?->toIso8601String(),
        ];
    }

    /** @return list<string> */
    private function reachableConnectionIds(Request $request): array
    {
        return PhoneConnection::query()->get()
            ->filter(fn (PhoneConnection $c) => $request->user()->can('view', $c))
            ->pluck('id')
            ->all();
    }
}
