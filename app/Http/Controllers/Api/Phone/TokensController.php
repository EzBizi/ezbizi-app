<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mint a Twilio Voice access token for the Call desk softphone (spec §15).
 *
 * Needs phone.view and at least one granted number on the named connection.
 */
class TokensController extends Controller
{
    private const NO_LINE = 'You have not been given a line on this phone account.';

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly PhoneNumberAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** POST /api/v1/phone/tokens */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $input = $request->validate([
            'connection_id' => ['required', 'uuid', $this->existsInTenant('phone_connections')],

            // Which kind of device is asking. Absent means a browser, which is
            // every desktop and web client and was the only caller until
            // handsets stopped ringing: a phone needs the token to name a push
            // credential, and only the phone knows which one it can receive.
            'platform' => ['sometimes', 'nullable', Rule::in(['ios', 'android'])],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $user = $request->user();

        if (! $this->access->hasLineOn($user, $connection)) {
            return response()->json(['message' => self::NO_LINE], Response::HTTP_FORBIDDEN);
        }

        $identity = ClientIdentity::of($user);
        $token = $this->twilio->accessToken($connection, $identity, $input['platform'] ?? null);

        return response()->json([
            'token' => $token,
            'identity' => $identity,
            'twiml_app_sid' => $connection->twiml_app_sid,
        ]);
    }

    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }
}
