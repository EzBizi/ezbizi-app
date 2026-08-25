<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\PhoneConnection;
use App\Models\User;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Connect / list / disconnect Twilio accounts for Phone (spec §5).
 *
 * BYO verifies tenant keys; provisioned creates a subaccount under platform
 * env. Credentials are written via forceFill — never mass-assigned.
 */
class ConnectionsController extends Controller
{
    private const PLATFORM_GAP = 'EzBizi has not been given a Twilio account to create phone service with.';

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/connections */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneConnection::class);

        $user = $request->user();
        $rows = $this->connectionsInReach($user)
            ->sortBy(fn (PhoneConnection $c) => $c->scope_type.'|'.$c->scope_id)
            ->values()
            ->map(fn (PhoneConnection $c) => $this->row($c))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/connections */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', 'in:organization,company'],
            'scope_id' => ['required', 'uuid'],
            'kind' => ['required', Rule::in([PhoneConnection::KIND_BYO, PhoneConnection::KIND_SUBACCOUNT])],
            'account_sid' => ['required_if:kind,byo', 'nullable', 'string', 'size:34'],
            'api_key_sid' => ['required_if:kind,byo', 'nullable', 'string', 'size:34'],
            'api_secret' => ['required_if:kind,byo', 'nullable', 'string', 'min:1'],
        ]);

        $company = null;
        if ($input['scope_type'] === PhoneConnection::SCOPE_COMPANY) {
            $company = Company::find($input['scope_id']);
            abort_if($company === null, Response::HTTP_NOT_FOUND, 'That company was not found.');
        } elseif ($this->tenant->idOrFail() !== $input['scope_id']) {
            abort(Response::HTTP_NOT_FOUND, 'That organization was not found.');
        }

        $this->authorize('create', [PhoneConnection::class, $input['scope_type'], $company]);

        if ($input['kind'] === PhoneConnection::KIND_SUBACCOUNT && ! $this->platformTwilioReady()) {
            return response()->json(['message' => self::PLATFORM_GAP], Response::HTTP_NOT_IMPLEMENTED);
        }

        $existing = PhoneConnection::query()
            ->where('scope_type', $input['scope_type'])
            ->where('scope_id', $input['scope_id'])
            ->exists();

        if ($existing) {
            return response()->json([
                'message' => 'A phone connection is already set up for that place. Disconnect it before connecting another.',
            ], Response::HTTP_CONFLICT);
        }

        // Twilio HTTP must not sit inside a DB transaction: a rolled-back local
        // row would orphan a real subaccount / TwiML App with nothing to disconnect.
        // Provision first, persist a disconnectable row, then attach the App.
        try {
            if ($input['kind'] === PhoneConnection::KIND_BYO) {
                $verified = $this->twilio->verifyAccount(
                    $input['account_sid'],
                    $input['api_key_sid'],
                    $input['api_secret'],
                );

                $accountSid = $input['account_sid'];
                $apiKeySid = $input['api_key_sid'];
                $apiSecret = $input['api_secret'];
                $authToken = $verified['auth_token'] ?? null;
            } else {
                $friendly = $company?->name ?? (string) $this->tenant->get()?->name;
                $sub = $this->twilio->createSubaccount($friendly);
                $key = $this->twilio->createApiKey($sub['account_sid'], 'EzBizi');
                $accountSid = $sub['account_sid'];
                $apiKeySid = $key['sid'];
                $apiSecret = $key['secret'];
                $authToken = $sub['auth_token'] ?? null;
            }
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Twilio account to create phone service')) {
                return response()->json(['message' => self::PLATFORM_GAP], Response::HTTP_NOT_IMPLEMENTED);
            }

            throw ValidationException::withMessages([
                'account_sid' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Those Twilio credentials could not be verified.',
            ]);
        }

        if (! is_string($authToken) || $authToken === '') {
            throw ValidationException::withMessages([
                'account_sid' => 'Twilio did not return an account auth token for this connection.',
            ]);
        }

        $connection = new PhoneConnection([
            'organization_id' => $this->tenant->idOrFail(),
            'scope_type' => $input['scope_type'],
            'scope_id' => $input['scope_id'],
            'kind' => $input['kind'],
            'account_sid' => $accountSid,
            'api_key_sid' => $apiKeySid,
            'status' => PhoneConnection::STATUS_CONNECTED,
        ]);
        $connection->writeSecrets($apiSecret, $authToken);

        $host = rtrim((string) config('app.url'), '/');
        $voiceUrl = $host.'/api/v1/phone/voice/'.$connection->organization_id.'/'.$connection->id;
        $statusUrl = $host.'/api/v1/phone/status/'.$connection->organization_id.'/'.$connection->id;

        try {
            $app = $this->twilio->createTwimlApp(
                $accountSid,
                $apiKeySid,
                $apiSecret,
                'EzBizi Phone',
                $voiceUrl,
                $statusUrl,
            );
        } catch (RuntimeException $e) {
            // Row stays so Settings can disconnect / finish; do not roll it back.
            throw ValidationException::withMessages([
                'account_sid' => $e->getMessage() !== ''
                    ? $e->getMessage()
                    : 'Twilio could not create the voice application for this connection.',
            ]);
        }

        $connection->update(['twiml_app_sid' => $app['sid']]);

        return response()->json(['data' => $this->row($connection->fresh())], Response::HTTP_CREATED);
    }

    /** GET /api/v1/phone/connections/{connection}/trust */
    public function trustShow(Request $request, PhoneConnection $connection): JsonResponse
    {
        $this->authorize('view', $connection);

        return response()->json(['data' => $this->twilio->trustStatus($connection)]);
    }

    /** POST /api/v1/phone/connections/{connection}/trust */
    public function trustStore(Request $request, PhoneConnection $connection): JsonResponse
    {
        $this->authorize('update', $connection);

        $input = $request->validate([
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'friendly_name' => ['sometimes', 'nullable', 'string', 'max:191'],
            'policy_sid' => ['sometimes', 'nullable', 'string', 'max:34'],
        ]);

        return response()->json(['data' => $this->twilio->submitTrust($connection, $input)]);
    }

    /** DELETE /api/v1/phone/connections/{connection} */
    public function destroy(Request $request, PhoneConnection $connection): JsonResponse
    {
        $this->authorize('delete', $connection);

        $close = $request->boolean('close');

        try {
            foreach ($connection->numbers as $number) {
                if (filled($number->twilio_sid)) {
                    $this->twilio->updateIncoming($connection, (string) $number->twilio_sid, [
                        'voiceUrl' => '',
                        'smsUrl' => '',
                        'statusCallback' => '',
                    ]);
                }
            }

            if (filled($connection->twiml_app_sid)) {
                $this->twilio->clearTwimlApp($connection);
            }

            if ($connection->kind === PhoneConnection::KIND_SUBACCOUNT && $close) {
                $this->twilio->closeSubaccount($connection);
            }
        } catch (Throwable) {
            // Still drop our row so Settings is not stuck on a dead link.
        }

        $connection->delete();

        return response()->json(['message' => 'That phone connection has been removed.']);
    }

    /**
     * Org connection plus company connections the caller can act on.
     *
     * @return \Illuminate\Support\Collection<int, PhoneConnection>
     */
    private function connectionsInReach(User $user): \Illuminate\Support\Collection
    {
        if ($user->canActOnOrganization($user->organization)) {
            return PhoneConnection::query()->get();
        }

        $companyIds = $user->reachableCompanies()->pluck('id')->all();

        return PhoneConnection::query()
            ->where(function ($q) use ($user, $companyIds) {
                $q->where(function ($inner) use ($user) {
                    $inner->where('scope_type', PhoneConnection::SCOPE_ORGANIZATION)
                        ->where('scope_id', $user->organization_id);
                })->orWhere(function ($inner) use ($companyIds) {
                    $inner->where('scope_type', PhoneConnection::SCOPE_COMPANY)
                        ->whereIn('scope_id', $companyIds);
                });
            })
            ->get();
    }

    private function platformTwilioReady(): bool
    {
        foreach (['platform_account_sid', 'platform_api_key_sid', 'platform_api_secret'] as $key) {
            $value = config('services.twilio.'.$key);
            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function row(PhoneConnection $connection): array
    {
        return [
            'id' => $connection->id,
            'organization_id' => $connection->organization_id,
            'scope_type' => $connection->scope_type,
            'scope_id' => $connection->scope_id,
            'kind' => $connection->kind,
            'account_sid' => $connection->account_sid,
            'api_key_sid' => $connection->api_key_sid,
            'twiml_app_sid' => $connection->twiml_app_sid,
            'status' => $connection->status,
            'status_reason' => $connection->status_reason,
        ];
    }
}
