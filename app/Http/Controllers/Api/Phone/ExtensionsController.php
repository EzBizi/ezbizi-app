<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\PhoneConnection;
use App\Models\PhoneExtension;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short numbers that reach one person.
 *
 * Twilio has no concept of an extension. To Twilio these are Client
 * identities; the digits are ours, and resolving them before we dial is the
 * whole of the feature.
 */
class ExtensionsController extends Controller
{
    use ResolvesPhoneConnections;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/phone/extensions
     *
     * Everyone who could hold an extension, and the one they hold.
     *
     * People without one are listed too. A screen that shows only assigned
     * extensions makes "who has not got one yet" the question you cannot
     * answer, which is the question somebody setting this up is actually
     * asking.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $connections = $this->connectionsInReach($request->user());

        if ($connections->isEmpty()) {
            return response()->json(['data' => [], 'connection_id' => null]);
        }

        $connection = $connections->first();

        $held = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->get()
            ->keyBy('user_id');

        $rows = User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'user_id' => $user->id,
                'name' => $user->name,
                'extension' => $held[$user->id]->extension ?? null,
            ])
            ->values()
            ->all();

        return response()->json([
            'data' => $rows,
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * PUT /api/v1/phone/extensions
     *
     * Give somebody an extension, or take theirs away.
     *
     * One call for both because they are the same decision from the screen's
     * side — a field with digits in it or a field somebody cleared.
     */
    public function update(Request $request): JsonResponse
    {
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'user_id' => ['required', 'uuid'],

            // Digits only. Letters would be unreachable from a phone keypad,
            // which is the one place this has to work.
            'extension' => ['present', 'nullable', 'string', 'regex:/^\d{2,8}$/'],
        ]);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);
        $this->authorize('update', $connection);

        $user = User::query()->find($input['user_id']);
        abort_if($user === null, Response::HTTP_NOT_FOUND);

        $digits = $input['extension'] ?? null;

        if ($digits === null || $digits === '') {
            PhoneExtension::query()
                ->where('connection_id', $connection->id)
                ->where('user_id', $user->id)
                ->delete();

            return response()->json(['data' => [
                'user_id' => $user->id,
                'name' => $user->name,
                'extension' => null,
            ]]);
        }

        // Refused rather than silently reassigned. Two people on one extension
        // makes "call 101" ambiguous, and the unique index would throw a 500
        // here rather than say which person already holds it.
        $taken = PhoneExtension::query()
            ->where('connection_id', $connection->id)
            ->where('extension', $digits)
            ->where('user_id', '!=', $user->id)
            ->first();

        if ($taken !== null) {
            throw ValidationException::withMessages([
                'extension' => $taken->user?->name
                    ? $taken->user->name.' already has extension '.$digits.'.'
                    : 'Extension '.$digits.' is already taken.',
            ]);
        }

        $row = PhoneExtension::query()->updateOrCreate(
            ['connection_id' => $connection->id, 'user_id' => $user->id],
            [
                'organization_id' => $this->tenant->idOrFail(),
                'extension' => $digits,
            ],
        );

        return response()->json(['data' => [
            'user_id' => $user->id,
            'name' => $user->name,
            'extension' => $row->extension,
        ]]);
    }
}
