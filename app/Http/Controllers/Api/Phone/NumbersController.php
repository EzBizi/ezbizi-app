<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\ResolvesPhoneConnections;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneNumber;
use App\Models\PhoneRingGroup;
use App\Support\Phone\ConnectionResolver;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Buy, list, and release Twilio numbers hung on org / company / franchise.
 *
 * Buy uses the connection resolved for the chosen scope — never a sibling
 * company's credentials. List is phone.view ∩ grant ∩ reach ∩ view-scope.
 */
class NumbersController extends Controller
{
    use ResolvesPhoneConnections;

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

        $host = rtrim((string) config('app.url'), '/');
        $base = $host.'/api/v1/phone';
        $orgId = $connection->organization_id;
        $connectionId = $connection->id;

        $purchased = $this->twilio->purchaseIncoming($connection, $phoneNumber, [
            'voiceUrl' => $base.'/voice/'.$orgId.'/'.$connectionId,
            'voiceMethod' => 'POST',
            'smsUrl' => $base.'/sms/'.$orgId.'/'.$connectionId,
            'smsMethod' => 'POST',
            'statusCallback' => $base.'/status/'.$orgId.'/'.$connectionId,
            'statusCallbackMethod' => 'POST',
        ]);

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

    /**
     * GET /api/v1/phone/numbers/importable
     *
     * Lines this Twilio account already holds that EzBizi has no row for.
     *
     * Adopting an account means adopting its numbers. A-1 Chimney had been
     * answering five lines for a year; without this the Numbers screen was
     * empty on a connected account, which reads as a broken screen rather
     * than as an unfinished import.
     *
     * Read-only. Nothing is written and no webhook is touched until somebody
     * chooses — see [import].
     */
    public function importable(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneNumber::class);

        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
        ]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            return response()->json(['data' => [], 'message' => self::NO_CONNECTION]);
        }

        try {
            $held = $this->twilio->listIncoming($connection);
        } catch (Throwable) {
            // Twilio being unreachable must not take the screen down; the
            // numbers already imported still list.
            return response()->json([
                'data' => [],
                'message' => 'EzBizi could not reach Twilio to list the numbers on this phone account.',
            ]);
        }

        $known = PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->pluck('twilio_sid')
            ->all();

        $rows = [];
        foreach ($held as $line) {
            if (in_array($line['sid'], $known, true)) {
                continue;
            }

            $rows[] = [
                'sid' => $line['sid'],
                'e164' => $line['phone_number'],
                'label' => $line['friendly_name'],

                // Whether this line currently answers to somebody else. Shown
                // rather than hidden: importing rewrites it, and that is a
                // thing somebody should be told before they press the button.
                'answers_elsewhere' => $line['voice_url'] !== ''
                    && ! str_contains($line['voice_url'], rtrim((string) config('app.url'), '/')),
                'voice_url' => $line['voice_url'],
            ];
        }

        return response()->json(['data' => $rows]);
    }

    /**
     * POST /api/v1/phone/numbers/import
     *
     * Take over lines the account already holds: write our row and point the
     * carrier at us.
     *
     * The webhook rewrite is the whole act. A row without it is a number
     * EzBizi lists but never hears from, which is worse than not listing it —
     * the screen would say the line is set up and calls would go on reaching
     * wherever they used to.
     */
    public function import(Request $request): JsonResponse
    {
        $input = $request->validate([
            'scope_type' => ['required', Rule::in([
                PhoneNumber::SCOPE_ORGANIZATION,
                PhoneNumber::SCOPE_COMPANY,
                PhoneNumber::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['required', 'uuid'],
            'sids' => ['required', 'array', 'min:1'],
            'sids.*' => ['string', 'distinct', 'max:64'],
        ]);

        $this->authorize('create', [
            PhoneNumber::class, $input['scope_type'], $input['scope_id'],
        ]);

        $connection = $this->resolver->forScope(
            $this->tenant->idOrFail(),
            $input['scope_type'],
            $input['scope_id'],
        );

        if ($connection === null) {
            throw ValidationException::withMessages(['scope_id' => self::NO_CONNECTION]);
        }

        $held = [];
        foreach ($this->twilio->listIncoming($connection) as $line) {
            $held[$line['sid']] = $line;
        }

        $host = rtrim((string) config('app.url'), '/');
        $base = $host.'/api/v1/phone';
        $orgId = $connection->organization_id;
        $connectionId = $connection->id;

        $made = [];

        foreach ($input['sids'] as $sid) {
            if (! isset($held[$sid])) {
                throw ValidationException::withMessages([
                    'sids' => 'That line is not on this phone account any more.',
                ]);
            }

            $existing = PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->where('twilio_sid', $sid)
                ->first();

            if ($existing !== null) {
                continue;
            }

            // Carrier first. If this fails the row is never written, so a
            // retry is a clean retry rather than a half-imported line.
            $this->twilio->updateIncoming($connection, $sid, [
                'voiceUrl' => $base.'/voice/'.$orgId.'/'.$connectionId,
                'voiceMethod' => 'POST',
                'smsUrl' => $base.'/sms/'.$orgId.'/'.$connectionId,
                'smsMethod' => 'POST',
                'statusCallback' => $base.'/status/'.$orgId.'/'.$connectionId,
                'statusCallbackMethod' => 'POST',
            ]);

            $line = $held[$sid];

            $made[] = PhoneNumber::query()->create([
                'organization_id' => $this->tenant->idOrFail(),
                'connection_id' => $connection->id,
                'scope_type' => $input['scope_type'],
                'scope_id' => $input['scope_id'],
                'twilio_sid' => $sid,
                'e164' => $line['phone_number'],
                'label' => $line['friendly_name'] !== ''
                    ? $line['friendly_name']
                    : $line['phone_number'],
                'after_hours' => 'voicemail',
            ]);
        }

        return response()->json([
            'data' => array_map(fn (PhoneNumber $n) => $this->row($n), $made),
        ], Response::HTTP_CREATED);
    }

    /**
     * PATCH /api/v1/phone/numbers/{number}
     *
     * How this line behaves. Chiefly which ring group answers it: without this
     * `ring_group_id` stayed null forever and TwimlRenderer sent every inbound
     * call to voicemail on an otherwise working account.
     *
     * The E.164 and the Twilio SID are NOT editable. Twilio owns those; a local
     * edit would only make our row disagree with the carrier.
     */
    public function update(Request $request, PhoneNumber $number): JsonResponse
    {
        $this->authorize('update', $number);

        $input = $request->validate([
            'label' => ['sometimes', 'string', 'max:191'],
            'ring_group_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_ring_groups')],
            'hours_schedule_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('phone_hours_schedules')],
            'record' => ['sometimes', 'boolean'],
            'transcribe' => ['sometimes', 'boolean'],
            'after_hours' => ['sometimes', Rule::in(PhoneNumber::AFTER_HOURS)],
            'after_hours_number' => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);

        // A group on another Twilio account expands to Client identities this
        // call can never reach, so the Dial would ring nobody and look fine.
        if (filled($input['ring_group_id'] ?? null)) {
            $group = PhoneRingGroup::query()->find($input['ring_group_id']);

            if ($group === null || $group->connection_id !== $number->connection_id) {
                throw ValidationException::withMessages([
                    'ring_group_id' => 'That group belongs to a different phone account.',
                ]);
            }
        }

        if (($input['after_hours'] ?? $number->after_hours) === 'forward'
            && ! filled($input['after_hours_number'] ?? $number->after_hours_number)) {
            throw ValidationException::withMessages([
                'after_hours_number' => 'Say which number after-hours calls should go to.',
            ]);
        }

        $number->fill($input)->save();

        return response()->json(['data' => $this->row($number->fresh())]);
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
            'after_hours_number' => $number->after_hours_number,
            'ring_group_id' => $number->ring_group_id,
            'hours_schedule_id' => $number->hours_schedule_id,
            'language' => $number->language,
            'sms_outbound_allowed' => (bool) $number->sms_outbound_allowed,
        ];
    }
}
