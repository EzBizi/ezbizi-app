<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\PhoneLineHealth;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\SmsCompliance;
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

        if (! $this->compliance->allowsOutbound($number)) {
            return response()->json(['message' => SmsCompliance::REFUSAL], Status::HTTP_FORBIDDEN);
        }

        $connection = $number->connection;
        if ($connection === null) {
            abort(Status::HTTP_NOT_FOUND);
        }

        $to = (string) $input['to'];
        $body = (string) $input['body'];
        $from = (string) $number->e164;

        try {
            $sent = $this->twilio->sendMessage($connection, $from, $to, $body);
        } catch (Throwable $e) {
            $reason = $e->getMessage() !== ''
                ? $e->getMessage()
                : 'Twilio refused to send from this phone line.';
            $this->health->reportBroken($connection, $reason, $number);

            return response()->json(['message' => $reason], Status::HTTP_BAD_GATEWAY);
        }

        $message = DB::transaction(function () use ($number, $connection, $sent, $from, $to, $body) {
            $message = PhoneMessage::query()->create([
                'connection_id' => $connection->id,
                'number_id' => $number->id,
                'twilio_sid' => $sent['sid'],
                'direction' => PhoneMessage::DIRECTION_OUTBOUND,
                'from_e164' => $from,
                'to_e164' => $to,
                'body' => $body,
                'customer_id' => $this->matchCustomer($to),
            ]);

            $this->events->emit('phone.sms.sent', $message, $this->payload($message));

            return $message;
        });

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
