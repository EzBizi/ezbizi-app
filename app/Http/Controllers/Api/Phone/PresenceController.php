<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhonePresence;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Softphone presence on a Twilio connection — who is at the desk (spec §8).
 *
 * One row per (connection, user). Replaced in place; not a log.
 */
class PresenceController extends Controller
{
    private const NO_LINE = 'You have not been given a line on this phone account.';

    public function __construct(
        private readonly PhoneNumberAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/presence?connection_id= */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $input = $request->validate([
            'connection_id' => ['required', 'uuid', $this->existsInTenant('phone_connections')],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $user = $request->user();

        if (! $this->access->hasLineOn($user, $connection)) {
            return response()->json(['message' => self::NO_LINE], Response::HTTP_FORBIDDEN);
        }

        $rows = PhonePresence::query()
            ->where('connection_id', $connection->id)
            ->with('user')
            ->orderBy('updated_at')
            ->get()
            ->map(fn (PhonePresence $row) => [
                'user_id' => $row->user_id,
                'name' => $row->user?->name ?? '',
                'state' => $row->state,
                'updated_at' => $row->updated_at?->toIso8601String(),
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** PUT /api/v1/phone/presence */
    public function update(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $input = $request->validate([
            'connection_id' => ['required', 'uuid', $this->existsInTenant('phone_connections')],
            'state' => ['required', Rule::in([
                PhonePresence::STATE_REGISTERED,
                PhonePresence::STATE_DND,
                PhonePresence::STATE_ON_CALL,
            ])],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $user = $request->user();

        if (! $this->access->hasLineOn($user, $connection)) {
            return response()->json(['message' => self::NO_LINE], Response::HTTP_FORBIDDEN);
        }

        $presence = PhonePresence::query()->updateOrCreate(
            [
                'connection_id' => $connection->id,
                'user_id' => $user->id,
            ],
            [
                'organization_id' => $this->tenant->idOrFail(),
                'state' => $input['state'],
            ],
        );

        return response()->json([
            'data' => [
                'id' => $presence->id,
                'connection_id' => $presence->connection_id,
                'user_id' => $presence->user_id,
                'state' => $presence->state,
                'updated_at' => $presence->updated_at?->toIso8601String(),
            ],
        ]);
    }

    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }
}
