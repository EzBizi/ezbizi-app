<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\NumberNames;
use App\Support\Phone\PhoneLineHealth;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\PhoneTexts;
use App\Support\Phone\SmsCompliance;
use App\Support\Phone\TextRefused;
use App\Support\Phone\TwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;

/**
 * Outbound SMS from the Call desk (spec §9).
 *
 * Needs phone.view and a grant on the line. SmsCompliance refuses until the
 * number's campaign (or toll-free verification) is approved.
 */
class MessagesController extends Controller
{
    public function __construct(
        private readonly PhoneNumberAccess $access,
        private readonly TwilioGateway $twilio,
        private readonly SmsCompliance $compliance,
        private readonly EventEmitter $events,
        private readonly TenantContext $tenant,
        private readonly PhoneLineHealth $health,
    ) {}

    /** GET /api/v1/phone/messages */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneMessage::class);

        $input = $request->validate([
            'number_id' => [
                'sometimes',
                'uuid',
                Rule::exists('phone_numbers', 'id')
                    ->where('organization_id', $this->tenant->idOrFail()),
            ],
            'peer' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $user = $request->user();
        $readable = $this->access->readable($user)->pluck('id')->all();

        if (isset($input['number_id'])) {
            $number = PhoneNumber::query()->findOrFail($input['number_id']);
            if (! $this->access->mayView($user, $number)) {
                abort(Status::HTTP_FORBIDDEN);
            }
            $readable = [$number->id];
        }

        $query = PhoneMessage::query()
            ->whereIn('number_id', $readable)
            ->orderByDesc('created_at');

        if (filled($input['peer'] ?? null)) {
            $peer = (string) $input['peer'];
            $query->where(fn ($q) => $q
                ->where('from_e164', $peer)
                ->orWhere('to_e164', $peer));
        }

        $messages = $query->get();

        // The customer's NAME beside the id, so a conversation row can say who
        // rather than a number - the same thing the call list sends (Mia,
        // 2026-10-02, Messages as tiles over a table). One query for the page,
        // not one per row.
        $ids = $messages->pluck('customer_id')->filter()->unique()->values()->all();
        $names = $ids === []
            ? []
            : Customer::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        // Jose, 2026-10-09: a thread that is not filed against a customer still
        // has somebody at the other end. Named by the number itself: a
        // customer with that phone first, then a colleague.
        $peerNames = app(NumberNames::class)->for($messages
            ->filter(fn (PhoneMessage $m) => ! isset($names[(string) $m->customer_id]))
            ->map(fn (PhoneMessage $m) => $m->direction === 'outbound' ? $m->to_e164 : $m->from_e164)
            ->unique()
            ->values()
            ->all());

        $rows = $messages
            ->map(fn (PhoneMessage $message) => $this->row($message) + [
                'customer_name' => $names[(string) $message->customer_id] ?? null,
                'peer_name' => $peerNames[$message->direction === 'outbound' ? $message->to_e164 : $message->from_e164]['name'] ?? null,
            ])
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/messages */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PhoneMessage::class);

        $input = $request->validate([
            'number_id' => [
                'required',
                'uuid',
                Rule::exists('phone_numbers', 'id')
                    ->where('organization_id', $this->tenant->idOrFail()),
            ],
            'to' => ['required', 'string', 'max:32'],
            'body' => ['required', 'string', 'max:1600'],
        ]);

        $number = PhoneNumber::query()->findOrFail($input['number_id']);
        $user = $request->user();

        if (! $this->access->mayView($user, $number)) {
            abort(Status::HTTP_FORBIDDEN);
        }

        try {
            $message = app(PhoneTexts::class)->send($number, (string) $input['to'], (string) $input['body']);
        } catch (TextRefused $refused) {
            return response()->json(['message' => $refused->getMessage()], $refused->getCode());
        }

        return response()->json(['data' => $this->row($message)], Status::HTTP_CREATED);
    }

    private function matchCustomer(string $e164): ?string
    {
        if ($e164 === '') {
            return null;
        }

        return Customer::query()
            ->where('phone', $e164)
            ->value('id');
    }

    /** @return array<string, mixed> */
    private function row(PhoneMessage $message): array
    {
        return [
            'id' => $message->id,
            'number_id' => $message->number_id,
            'connection_id' => $message->connection_id,
            'twilio_sid' => $message->twilio_sid,
            'direction' => $message->direction,
            'from_e164' => $message->from_e164,
            'to_e164' => $message->to_e164,
            'body' => $message->body,
            'customer_id' => $message->customer_id,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function payload(PhoneMessage $message): array
    {
        return ['after' => [
            'id' => $message->id,
            'twilio_sid' => $message->twilio_sid,
            'direction' => $message->direction,
            'from_e164' => $message->from_e164,
            'to_e164' => $message->to_e164,
            'body' => $message->body,
            'number_id' => $message->number_id,
            'customer_id' => $message->customer_id,
        ]];
    }
}
