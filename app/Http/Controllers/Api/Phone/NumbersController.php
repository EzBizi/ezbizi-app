<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneNumber;
use App\Support\Phone\ConnectionResolver;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Buy, list, and release Twilio numbers hung on org / company / franchise.
 *
 * Buy uses the connection resolved for the chosen scope — never a sibling
 * company's credentials. List is phone.view ∩ grant ∩ reach ∩ view-scope.
 */
class NumbersController extends Controller
{
    private const NO_CONNECTION = 'Connect Twilio for this place first.';

    public function __construct(
        private readonly TwilioGateway $twilio,
        private readonly ConnectionResolver $resolver,
        private readonly PhoneNumberAccess $access,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/phone/numbers */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $rows = $this->access->visibleTo($request->user())
            ->map(fn (PhoneNumber $number) => $this->row($number))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/numbers */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
            'area_code' => ['required_without:e164', 'nullable', 'string', 'max:8'],
            'e164' => ['required_without:area_code', 'nullable', 'string', 'max:16'],
            'label' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $this->assertScopeExists($input['scope_type'], $input['scope_id']);
        $this->authorize('create', [PhoneNumber::class, $input['scope_type'], $input['scope_id']]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            throw ValidationException::withMessages(['scope_id' => self::NO_CONNECTION]);
        }

        $phoneNumber = filled($input['e164'] ?? null)
            ? (string) $input['e164']
            : $this->pickAvailable($connection, (string) $input['area_code']);

        $purchased = $this->twilio->purchaseIncoming($connection, $phoneNumber);

        $number = PhoneNumber::query()->create([
            'organization_id' => $this->tenant->idOrFail(),
            'connection_id' => $connection->id,
            'scope_type' => $input['scope_type'],
            'scope_id' => $input['scope_id'],
            'twilio_sid' => $purchased['sid'],
            'e164' => $purchased['phone_number'],
            'label' => filled($input['label'] ?? null) ? (string) $input['label'] : $purchased['phone_number'],
            'after_hours' => 'voicemail',
        ]);

        return response()->json(['data' => $this->row($number)], Response::HTTP_CREATED);
    }

    /** DELETE /api/v1/phone/numbers/{number} */
    public function destroy(Request $request, PhoneNumber $number): JsonResponse
    {
        $this->authorize('delete', $number);

        $connection = $this->resolver->forNumber($number) ?? $number->connection;

        if ($connection !== null && filled($number->twilio_sid)) {
            $this->twilio->releaseIncoming($connection, (string) $number->twilio_sid);
        }

        $number->delete();

        return response()->json(['data' => ['id' => $number->id, 'deleted' => true]]);
    }

    private function assertScopeExists(string $scopeType, string $scopeId): void
    {
        $orgId = $this->tenant->idOrFail();

        $ok = match ($scopeType) {
            PhoneNumber::SCOPE_ORGANIZATION => $scopeId === $orgId,
            PhoneNumber::SCOPE_COMPANY => Company::query()
                ->whereKey($scopeId)
                ->where('organization_id', $orgId)
                ->exists(),
            PhoneNumber::SCOPE_FRANCHISE => Franchise::query()
                ->whereKey($scopeId)
                ->where('organization_id', $orgId)
                ->exists(),
            default => false,
        };

        abort_unless($ok, Response::HTTP_NOT_FOUND, 'That place was not found.');
    }

    private function pickAvailable(\App\Models\PhoneConnection $connection, string $areaCode): string
    {
        $available = $this->twilio->searchAvailable($connection, [
            'area_code' => $areaCode,
            'voice' => true,
            'sms' => true,
        ]);

        if ($available === []) {
            throw ValidationException::withMessages([
                'area_code' => 'Twilio has no available numbers for that area code right now.',
            ]);
        }

        return (string) $available[0]['phone_number'];
    }

    /** @return array<string, mixed> */
    private function row(PhoneNumber $number): array
    {
        return [
            'id' => $number->id,
            'organization_id' => $number->organization_id,
            'connection_id' => $number->connection_id,
            'scope_type' => $number->scope_type,
            'scope_id' => $number->scope_id,
            'e164' => $number->e164,
            'label' => $number->label,
            'twilio_sid' => $number->twilio_sid,
            'record' => $number->record,
            'transcribe' => $number->transcribe,
            'after_hours' => $number->after_hours,
            'language' => $number->language,
        ];
    }
}
