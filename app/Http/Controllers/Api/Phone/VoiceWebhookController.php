<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\TwilioWebhook;
use App\Support\Phone\TwimlRenderer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;
use Twilio\TwiML\VoiceResponse;

/**
 * Public Twilio Voice URL. Signature is auth; path names the tenant.
 *
 * Inbound: TwiML from TwimlRenderer, insert a ringing PhoneCall, emit
 * phone.call.started. Dial action requests the next batch or voicemail.
 */
class VoiceWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly TwimlRenderer $renderer,
        private readonly EventEmitter $events,
    ) {}

    /**
     * POST /api/v1/phone/voice/{organization}/{connection}
     *
     * Path ids are plain strings — route model binding would need a tenant
     * before the connection can be read, and this request has none yet.
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
        $outbound = $this->isOutbound($request);
        $number = $this->line($request, $connection, $outbound);
        $batch = max(0, (int) $request->query('batch', 0));
        $dialStatus = $request->input('DialCallStatus');
        $dialStatus = is_string($dialStatus) ? $dialStatus : null;

        $context = $this->context($request, $connection, $outbound, $batch);

        if ($dialStatus !== null || $batch > 0) {
            return $this->continueDial($request, $number, $context, $dialStatus);
        }

        $xml = $number === null
            ? (string) new VoiceResponse
            : $this->renderer->inbound($number, $context);

        $this->recordStart(
            $request,
            $connection,
            $number,
            $outbound,
            miss: $this->isClosedDocument($xml) || $this->isVoicemailDocument($xml),
            voicemail: $this->isVoicemailDocument($xml),
        );

        return $this->twiml($xml);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function continueDial(
        Request $request,
        ?PhoneNumber $number,
        array $context,
        ?string $dialStatus,
    ): Response {
        $xml = $number === null || $dialStatus === 'completed'
            ? (string) new VoiceResponse
            : $this->renderer->afterDial($number, $context + ['dial_status' => (string) $dialStatus]);

        $call = $this->findCall($request);
        if ($call !== null && $this->unanswered($dialStatus)
            && ($this->isVoicemailDocument($xml) || $this->isClosedDocument($xml))) {
            DB::transaction(function () use ($call, $xml) {
                $this->markMissed($call, voicemail: $this->isVoicemailDocument($xml));
            });
        }

        return $this->twiml($xml);
    }

    private function recordStart(
        Request $request,
        PhoneConnection $connection,
        ?PhoneNumber $number,
        bool $outbound,
        bool $miss = false,
        bool $voicemail = false,
    ): void {
        $sid = (string) $request->input('CallSid', '');
        if ($sid === '') {
            return;
        }

        $from = $this->fromE164($request, $outbound);
        $to = $this->toE164($request);

        DB::transaction(function () use ($request, $connection, $number, $outbound, $sid, $from, $to, $miss, $voicemail) {
            $call = PhoneCall::query()->where('twilio_sid', $sid)->first();
            if ($call === null) {
                $call = PhoneCall::query()->create([
                    'connection_id' => $connection->id,
                    'number_id' => $number?->id,
                    'twilio_sid' => $sid,
                    'parent_sid' => $this->optionalSid($request->input('ParentCallSid')),
                    'direction' => $outbound ? PhoneCall::DIRECTION_OUTBOUND : PhoneCall::DIRECTION_INBOUND,
                    'from_e164' => $from,
                    'to_e164' => $to,
                    'status' => PhoneCall::STATUS_RINGING,
                ]);

                $this->events->emit('phone.call.started', $call, $this->payload($call));
            }

            if ($miss) {
                $this->markMissed($call, voicemail: $voicemail);
            }
        });
    }

    private function markMissed(PhoneCall $call, bool $voicemail = false): void
    {
        $alreadyMissed = in_array($call->status, [
            PhoneCall::STATUS_NO_ANSWER,
            PhoneCall::STATUS_BUSY,
            PhoneCall::STATUS_FAILED,
            PhoneCall::STATUS_CANCELED,
        ], true);

        if ($alreadyMissed) {
            if ($voicemail && ! $call->voicemail) {
                $call->voicemail = true;
                $call->save();
            }

            return;
        }

        if ($call->status !== PhoneCall::STATUS_RINGING) {
            return;
        }

        $call->status = PhoneCall::STATUS_NO_ANSWER;
        if ($voicemail) {
            $call->voicemail = true;
        }
        $call->save();

        $this->events->emit('phone.call.missed', $call, $this->payload($call));
    }

    private function findCall(Request $request): ?PhoneCall
    {
        $sid = (string) $request->input('CallSid', '');
        if ($sid === '') {
            return null;
        }

        return PhoneCall::query()->where('twilio_sid', $sid)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function context(
        Request $request,
        PhoneConnection $connection,
        bool $outbound,
        int $batch,
    ): array {
        $context = [
            'outbound' => $outbound,
            'batch' => $batch,
            'skip_menu' => $request->request->has('Digits'),
            'dial_action' => $this->voiceAction($connection, $batch + 1),
        ];

        if ($outbound) {
            $to = (string) $request->input('To', '');
            if ($to !== '') {
                $context['dial_to'] = $to;
            }
            $callerId = $this->callerId($request);
            if ($callerId !== '') {
                $context['caller_id'] = $callerId;
            }
        }

        return $context;
    }

    private function line(Request $request, PhoneConnection $connection, bool $outbound): ?PhoneNumber
    {
        $e164 = $outbound ? $this->callerId($request) : (string) $request->input('To', '');
        if ($e164 === '') {
            return PhoneNumber::query()
                ->where('connection_id', $connection->id)
                ->orderBy('e164')
                ->first();
        }

        return PhoneNumber::query()
            ->where('connection_id', $connection->id)
            ->where('e164', $e164)
            ->first();
    }

    private function isOutbound(Request $request): bool
    {
        $direction = (string) $request->input('Direction', '');
        $from = (string) $request->input('From', '');

        return $direction === 'outbound-api' || str_starts_with($from, 'client:');
    }

    private function callerId(Request $request): string
    {
        foreach (['CallerId', 'From', 'Caller'] as $key) {
            $value = (string) $request->input($key, '');
            if (str_starts_with($value, '+')) {
                return $value;
            }
        }

        return '';
    }

    private function fromE164(Request $request, bool $outbound): string
    {
        if ($outbound) {
            $callerId = $this->callerId($request);
            if ($callerId !== '') {
                return $this->clip($callerId);
            }
        }

        return $this->clip((string) $request->input('From', ''));
    }

    private function toE164(Request $request): string
    {
        return $this->clip((string) $request->input('To', ''));
    }

    private function clip(string $value): string
    {
        if (str_starts_with($value, 'client:')) {
            return substr($value, strlen('client:'), 32);
        }

        return substr($value, 0, 32);
    }

    private function optionalSid(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function unanswered(?string $dialStatus): bool
    {
        return ! in_array($dialStatus, ['completed', 'answered'], true);
    }

    private function isVoicemailDocument(string $xml): bool
    {
        return str_contains($xml, '<Record');
    }

    private function isClosedDocument(string $xml): bool
    {
        return str_contains($xml, 'This line is closed.');
    }

    private function voiceAction(PhoneConnection $connection, int $batch): string
    {
        return url('/api/v1/phone/voice/'.$connection->organization_id.'/'.$connection->id)
            .'?batch='.$batch;
    }

    /** @return array<string, mixed> */
    private function payload(PhoneCall $call): array
    {
        return ['after' => [
            'id' => $call->id,
            'twilio_sid' => $call->twilio_sid,
            'direction' => $call->direction,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,
            'status' => $call->status,
            'duration_seconds' => $call->duration_seconds,
            'number_id' => $call->number_id,
        ]];
    }

    private function twiml(string $xml): Response
    {
        return response($xml, Status::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }
}
