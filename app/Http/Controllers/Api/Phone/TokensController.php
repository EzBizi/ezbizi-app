<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhoneDeviceRegistration;
use App\Support\Phone\ClientIdentity;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
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
    /**
     * GET /api/v1/phone/registrations: every phone account this person can take calls on.
     *
     * A token belongs to one Twilio account, and a ring group on another
     * account cannot reach a device registered on this one (EverDoors'
     * "Owners", Jose 2026-11-11). So the desk registers once per account here.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);
        $user = $request->user();

        return response()->json(['data' => PhoneConnection::query()
            ->where('status', PhoneConnection::STATUS_CONNECTED)
            ->orderBy('id')
            ->get()
            ->filter(fn (PhoneConnection $connection) => $this->access->takesCallsOn($user, $connection))
            ->map(fn (PhoneConnection $connection) => ['connection_id' => $connection->id])
            ->values()
            ->all()]);
    }

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

            // WHICH INSTALL IS ASKING.
            //
            // A Voice identity now names a device as well as a person, because
            // `<Dial>` makes one call leg per `<Client>` noun — so a person
            // named once rings one device, whichever Twilio picks. Jose had
            // the desk on a PC and the app on an iPhone, both registered, and
            // only the iPhone rang.
            //
            // Optional: an older client sends none and gets the bare identity
            // it always had. Hex only, because it becomes half of a Twilio
            // identity and those are alphanumeric.
            'device_id' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9]+$/'],

            // What to call it when somebody is choosing where to send a call.
            'device_label' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $connection = PhoneConnection::query()->findOrFail($input['connection_id']);
        $user = $request->user();

        if (! $this->access->takesCallsOn($user, $connection)) {
            return response()->json(['message' => self::NO_LINE], Response::HTTP_FORBIDDEN);
        }

        $deviceId = (string) ($input['device_id'] ?? '');

        if ($deviceId !== '') {
            // Written on every token request, which is also the heartbeat.
            // A device that stops asking stops being dialled once it falls
            // past the deadline — nothing has to run to clean it up, and a
            // browser tab closed without warning costs one stale row rather
            // than one of the ten `<Client>` slots forever.
            PhoneDeviceRegistration::query()->updateOrCreate(
                [
                    'connection_id' => $connection->id,
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                ],
                [
                    'organization_id' => $this->tenant->idOrFail(),
                    'label' => $input['device_label'] ?? null,
                    'platform' => $input['platform'] ?? null,
                    'last_seen_at' => now(),
                ],
            );
        }

        $identity = ClientIdentity::of($user, $deviceId);
        $token = $this->twilio->accessToken($connection, $identity, $input['platform'] ?? null);

        return response()->json([
            'token' => $token,
            'identity' => $identity,
            'twiml_app_sid' => $connection->twiml_app_sid,

            // HOW LONG THIS LASTS, so the desk can ask for another one in
            // time. Only the browser and the WebView are told by their SDK
            // that a token is expiring; a handset gets no such event, so the
            // only way it can renew is to know the clock. Sent rather than
            // assumed, because a client with its own copy of this number goes
            // quiet the day the number changes and says nothing.
            'expires_in' => TwilioGateway::TOKEN_TTL_SECONDS,
        ]);
    }

    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }
}
