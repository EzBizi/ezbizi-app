<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Phone\ConnectionResolver;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Emergency addresses on a Twilio account (spec §6).
 *
 * ── Why this is not a field on the number ────────────────────────
 *
 * Twilio models an address as an account-level resource that lines point at,
 * because one premises answers for every line in the building. Registering it
 * per number would either duplicate the same address five times or quietly
 * disagree with itself.
 *
 * ── Why this exists at all ───────────────────────────────────────
 *
 * A US line cannot reach 911 without a validated emergency address, and
 * EzBizi has never set one. The failure is silent and total: everything about
 * the line looks healthy right up until somebody dials 911 from it. Twilio
 * will not tell us; the only signal is `emergency_status` on the number,
 * which is why Settings now reads it live rather than trusting a stored copy.
 */
class AddressesController extends Controller
{
    private const NO_CONNECTION = 'Connect Twilio for this place first.';

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly ConnectionResolver $resolver,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/addresses */
    public function index(Request $request): JsonResponse
    {
        $input = $this->scoped($request);

        $connection = $this->connectionFor($input);

        if ($connection === null) {
            return response()->json(['data' => [], 'message' => self::NO_CONNECTION]);
        }

        try {
            return response()->json(['data' => $this->twilio->listAddresses($connection)]);
        } catch (Throwable) {
            // The line form still has to open. An address list that cannot be
            // fetched is a missing choice, not a broken screen.
            return response()->json([
                'data' => [],
                'message' => 'EzBizi could not reach Twilio to list registered addresses.',
            ]);
        }
    }

    /**
     * POST /api/v1/phone/addresses
     *
     * Twilio validates the address against the postal record and can reject
     * it. That answer is passed straight back rather than swallowed: an
     * address that did not validate cannot carry 911, and saying "saved"
     * would be the exact lie this whole feature exists to prevent.
     */
    public function store(Request $request): JsonResponse
    {
        $input = $this->scoped($request, [
            'customer_name' => ['required', 'string', 'max:191'],
            'street' => ['required', 'string', 'max:191'],
            'city' => ['required', 'string', 'max:191'],
            'region' => ['required', 'string', 'max:64'],
            'postal_code' => ['required', 'string', 'max:16'],
            'iso_country' => ['required', 'string', 'size:2', 'alpha'],
            'friendly_name' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $connection = $this->connectionFor($input);

        if ($connection === null) {
            throw ValidationException::withMessages(['scope_id' => self::NO_CONNECTION]);
        }

        try {
            $created = $this->twilio->createAddress($connection, [
                'customer_name' => $input['customer_name'],
                'street' => $input['street'],
                'city' => $input['city'],
                'region' => $input['region'],
                'postal_code' => strtoupper((string) $input['postal_code']),
                'iso_country' => strtoupper((string) $input['iso_country']),
                'friendly_name' => $input['friendly_name'] ?? null,
            ]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages([
                'street' => 'Twilio would not accept that address: '.$e->getMessage(),
            ]);
        }

        return response()->json(['data' => $created], Response::HTTP_CREATED);
    }

    /**
     * Scope is how we find whose Twilio account this is, so it is required on
     * both verbs and authorised the same way buying a line is.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function scoped(Request $request, array $extra = []): array
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
            ...$extra,
        ]);

        $orgId = $this->tenant->idOrFail();

        $exists = match ($input['scope_type']) {
            PhoneNumber::SCOPE_ORGANIZATION => $input['scope_id'] === $orgId,
            PhoneNumber::SCOPE_COMPANY => Company::query()
                ->whereKey($input['scope_id'])
                ->where('organization_id', $orgId)
                ->exists(),
            PhoneNumber::SCOPE_FRANCHISE => Franchise::query()
                ->whereKey($input['scope_id'])
                ->where('organization_id', $orgId)
                ->exists(),
            default => false,
        };

        abort_unless($exists, Response::HTTP_NOT_FOUND, 'That place was not found.');

        $this->authorize('create', [
            PhoneNumber::class, $input['scope_type'], $input['scope_id'],
        ]);

        return $input;
    }

    /** @param  array<string, mixed>  $input */
    private function connectionFor(array $input): ?PhoneConnection
    {
        return $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );
    }
}
