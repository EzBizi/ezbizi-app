<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\PhoneLineHealth;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;
use Twilio\TwiML\VoiceResponse;

/**
 * Public Twilio status callback. Signature is auth; path names the tenant.
 *
 * Updates duration. Completed raises phone.call.ended. A miss or voicemail
 * already on the row is kept — Twilio completed after <Record> does not
 * promote it. Terminal no-answer without an earlier miss still raises
 * phone.call.missed.
 */
class StatusWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly PhoneLineHealth $health,
    ) {}

    /**
     * POST /api/v1/phone/status/{organization}/{connection}
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
        $sid = (string) $request->input('CallSid', '');
        if ($sid === '') {
            return $this->twiml();
        }

        DB::transaction(function () use ($request, $connection, $sid) {
            $call = PhoneCall::query()->where('twilio_sid', $sid)->first();
            if ($call === null) {
                return;
            }

            $status = (string) $request->input('CallStatus', $call->status);
            $duration = $request->input('CallDuration');
            if (is_numeric($duration)) {
                $call->duration_seconds = (int) $duration;
            }

            $parent = $request->input('ParentCallSid');
            if (is_string($parent) && $parent !== '') {
                $call->parent_sid = $parent;
            }

            $previous = $call->status;
            $wasMiss = $this->isMiss($previous) || $call->voicemail;
            $incoming = $this->storedStatus($status);

            // Voicemail is a recording on the missed call (spec §11). Twilio
            // still posts completed after <Record>; that must not clear the miss.
            if (! $wasMiss) {
                $call->status = $incoming;
            }
            $call->save();

            if ($incoming === PhoneCall::STATUS_COMPLETED && ! $this->alreadyEnded($call)) {
                $this->events->emit('phone.call.ended', $call, $this->payload($call));
            }

            if ($this->isMiss($call->status) && $previous === PhoneCall::STATUS_RINGING) {
                $this->events->emit('phone.call.missed', $call, $this->payload($call));
            }

            if ($this->lineBroke($request, $incoming)) {
                $number = $call->number_id !== null
                    ? PhoneNumber::query()->find($call->number_id)
                    : null;
                $this->health->reportBroken(
                    $connection,
                    $this->breakReason($request),
                    $number,
                );
            }
        });

        return $this->twiml();
    }

    /**
     * Account / number faults from the status callback — not ordinary no-answer.
     */
    private function lineBroke(Request $request, string $incoming): bool
    {
        if ($incoming !== PhoneCall::STATUS_FAILED) {
            return false;
        }

        $code = (string) $request->input('ErrorCode', '');

        // 20003 auth, 20404 unknown resource, 21211/21214 bad or unreachable To,
        // 21606 number not owned / not SMS-capable on this account.
        return in_array($code, ['20003', '20404', '21211', '21214', '21606'], true);
    }

    private function breakReason(Request $request): string
    {
        $message = trim((string) $request->input('ErrorMessage', ''));
        if ($message !== '') {
            return $message;
        }

        $code = (string) $request->input('ErrorCode', '');

        return $code !== ''
            ? "Twilio reported error {$code} on this phone line."
            : 'Twilio reported that this phone line failed.';
    }

    private function storedStatus(string $status): string
    {
        return match ($status) {
            'completed' => PhoneCall::STATUS_COMPLETED,
            'no-answer' => PhoneCall::STATUS_NO_ANSWER,
            'busy' => PhoneCall::STATUS_BUSY,
            'failed' => PhoneCall::STATUS_FAILED,
            'canceled', 'cancelled' => PhoneCall::STATUS_CANCELED,
            'in-progress' => PhoneCall::STATUS_IN_PROGRESS,
            'ringing', 'queued' => PhoneCall::STATUS_RINGING,
            default => $status !== '' ? $status : PhoneCall::STATUS_RINGING,
        };
    }

    private function isMiss(string $status): bool
    {
        return in_array($status, [
            PhoneCall::STATUS_NO_ANSWER,
            PhoneCall::STATUS_BUSY,
            PhoneCall::STATUS_FAILED,
            PhoneCall::STATUS_CANCELED,
        ], true);
    }

    private function alreadyEnded(PhoneCall $call): bool
    {
        return AutomationEvent::query()
            ->where('event_key', 'phone.call.ended')
            ->where('subject_id', $call->id)
            ->exists();
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

    private function twiml(): Response
    {
        return response((string) new VoiceResponse, Status::HTTP_OK)
            ->header('Content-Type', 'text/xml');
    }
}
