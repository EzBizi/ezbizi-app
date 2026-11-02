<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\OpensTwilioWebhook;
use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Support\Phone\Attestation;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Support\Automations\EventEmitter;
use App\Support\Phone\PhoneLineHealth;
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
    use OpensTwilioWebhook;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
        private readonly PhoneLineHealth $health,
    ) {}

    private function handle(Request $request, PhoneConnection $connection): Response
    {
        $sid = (string) $request->input('CallSid', '');
        if ($sid === '') {
            return $this->twiml();
        }

        DB::transaction(function () use ($request, $connection, $sid) {
            $call = PhoneCall::query()->where('twilio_sid', $sid)->lockForUpdate()->first();
            if ($call === null) {
                return;
            }

            $status = (string) $request->input('CallStatus', $call->status);
            $duration = $request->input('CallDuration');
            if (is_numeric($duration)) {
                $call->duration_seconds = max((int) $call->duration_seconds, (int) $duration);
            }

            $parent = $request->input('ParentCallSid');
            if (is_string($parent) && $parent !== '') {
                $call->parent_sid = $parent;
            }

            // Outbound attestation arrives here rather than on the voice
            // webhook — Twilio reports the grade it assigned once the call has
            // gone to the network. Only ever written, never cleared: a later
            // callback for the same call carries no StirStatus, and blanking
            // it would erase the grade the first callback told us.
            $grade = Attestation::fromWebhook(
                $request->input('StirVerstat'),
                $request->input('StirStatus'),
            );

            if ($grade !== null) {
                $call->attestation = $grade;
            }

            // WHY IT FAILED, KEPT.
            //
            // This was already read a few lines further down, and used only to
            // decide whether the whole LINE was broken. A caller-ID fault is
            // not a line fault, so the one code that explains why an outbound
            // call never connected was the one guaranteed to be discarded.
            //
            // Only ever written, never cleared: a later callback for the same
            // call carries no ErrorCode, and blanking would erase the reason
            // the first one gave — the same rule attestation follows above.
            $errorCode = trim((string) $request->input('ErrorCode', ''));
            if ($errorCode !== '') {
                $call->error_code = $errorCode;
                $call->error_message = trim((string) $request->input('ErrorMessage', ''))
                    ?: null;
            }

            $previous = $call->status;
            $wasMiss = $this->isMiss($previous) || $call->voicemail;
            $incoming = $this->storedStatus($status);

            // Voicemail is a recording on the missed call (spec §11). Twilio
            // still posts completed after <Record>; that must not clear the miss.
            if (! $wasMiss && $previous !== PhoneCall::STATUS_COMPLETED
                && ! ($previous === PhoneCall::STATUS_IN_PROGRESS && $incoming === PhoneCall::STATUS_RINGING)) {
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
     * Account / From faults from the status callback — not ordinary no-answer
     * and not a bad or unreachable To.
     */
    private function lineBroke(Request $request, string $incoming): bool
    {
        if ($incoming !== PhoneCall::STATUS_FAILED) {
            return false;
        }

        return PhoneLineHealth::isLineBreakingFault(
            (string) $request->input('ErrorCode', ''),
        );
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
