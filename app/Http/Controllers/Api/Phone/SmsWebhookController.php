<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
use App\Models\PhoneConnection;
use App\Models\PhoneMessage;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\LeadTexts;
use App\Support\Phone\TwilioGateway;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;

/**
 * Public Twilio SMS URL. Signature is auth; path names the tenant.
 *
 * Stores the body, matches customers.phone, raises phone.sms.received.
 * Inbound is kept even when the line cannot send yet (10DLC).
 */
class SmsWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly TwilioGateway $twilio,
    ) {}

    /**
     * POST /api/v1/phone/sms/{organization}/{connection}
     */
    public function __invoke(Request $request, string $organization, string $connection): Response
    {
        return TwilioWebhook::open(
            $request,
            $organization,
            $connection,
            $this->tenant,
            fn (PhoneConnection $row) => $this->handle($request, $row),
        );
    }

    private function handle(Request $request, PhoneConnection $connection): Response
    {
        $sid = (string) $request->input('MessageSid', '');
        if ($sid === '') {
            return response('', Status::HTTP_OK);
        }

        $from = substr((string) $request->input('From', ''), 0, 32);
        $to = substr((string) $request->input('To', ''), 0, 32);
        $body = (string) $request->input('Body', '');

        DB::transaction(function () use ($connection, $sid, $from, $to, $body) {
            if (PhoneMessage::query()->where('twilio_sid', $sid)->exists()) {
                return;
            }

            $number = $this->line($connection, $to);
            $this->lookupQuietly($from);
            $customerId = $this->matchCustomer($from);

            $message = PhoneMessage::query()->create([
                'connection_id' => $connection->id,
                'number_id' => $number?->id,
                'twilio_sid' => $sid,
                'direction' => PhoneMessage::DIRECTION_INBOUND,
                'from_e164' => $from,
                'to_e164' => $to,
                'body' => $body,
                'customer_id' => $customerId,
            ]);

            $this->events->emit('phone.sms.received', $message, $this->payload($message));

            // An answer to a text we sent about a lead goes on that lead's thread (Leads, 2026-10-13).
            // Never at the cost of the text itself: a failure here must not roll back
            // the PhoneMessage above (desk, 2026-10-13).
            try {
                app(LeadTexts::class)->absorbInbound($from, $body, $sid);
            } catch (Throwable $e) {
                report($e);
            }
        });

        return response('', Status::HTTP_OK);
    }

    private function line(PhoneConnection $connection, string $to): ?PhoneNumber
    {
        if ($to === '') {
            return null;
        }

        return PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('e164', $to)
            ->first();
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

    /** Lookup is optional. Miss / not enabled must not 500 the inbound store. */
    private function lookupQuietly(string $e164): void
    {
        if ($e164 === '') {
            return;
        }

        try {
            $this->twilio->lookup($e164);
        } catch (Throwable) {
            // Show the number unmatched; never fail the webhook.
        }
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
