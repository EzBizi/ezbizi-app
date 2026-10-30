<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\OpensTwilioWebhook;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Twilio's transcription callbacks — both of them.
 *
 * ── Two kinds of transcript arrive here, and they are not the same ──
 *
 * Real-Time Transcription (`<Start><Transcription>`) streams the LIVE call:
 * one POST per utterance, `TranscriptionEvent=transcription-content`, both
 * tracks. Finals concatenate onto `phone_calls.transcript`.
 *
 * `<Record transcribe="true">` transcribes the VOICEMAIL — one POST, once,
 * long after everyone has hung up, carrying `TranscriptionText` and no
 * `TranscriptionEvent` at all. This endpoint used to require that field and
 * return 200, so every voicemail transcription Twilio produced was accepted
 * and thrown away. The screen said "No transcript. Twilio returns nothing for
 * audio it cannot make out", which was a confident explanation of our own
 * fault.
 *
 * They go to separate columns because a single call can produce both, and a
 * voicemail screen showing the live transcript shows the caller the IVR
 * prompt, the recording notice and their own hold music read back to them.
 */
class TranscriptionWebhookController extends Controller
{
    use OpensTwilioWebhook;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    private function handle(Request $request, PhoneConnection $connection): Response
    {
        $event = (string) $request->input('TranscriptionEvent', '');

        // No event field means the recording callback, not the live stream.
        if ($event === '' && $request->has('TranscriptionText')) {
            return $this->recorded($request);
        }

        if ($event !== 'transcription-content') {
            return response('', Status::HTTP_OK);
        }

        if (! $this->isFinal($request->input('Final'))) {
            return response('', Status::HTTP_OK);
        }

        $callSid = (string) $request->input('CallSid', '');
        $text = $this->utterance($request->input('TranscriptionData'));
        if ($callSid === '' || $text === '') {
            return response('', Status::HTTP_OK);
        }

        $transcriptionSid = (string) $request->input('TranscriptionSid', '');

        DB::transaction(function () use ($callSid, $text, $transcriptionSid) {
            $call = PhoneCall::query()->where('twilio_sid', $callSid)->first();
            if ($call === null) {
                return;
            }

            if ($transcriptionSid !== '') {
                $call->transcription_sid = $transcriptionSid;
            }

            $existing = is_string($call->transcript) ? trim($call->transcript) : '';
            $call->transcript = $existing === '' ? $text : $existing."\n".$text;
            $call->save();
        });

        return response('', Status::HTTP_OK);
    }

    /**
     * The message somebody left, once Twilio has written it down.
     *
     * `RecordingSid` rather than `CallSid` would be the tighter match, but the
     * callback carries both and the recording is already on the row — CallSid
     * finds the call whether or not the recording callback landed first, and
     * the two race.
     */
    private function recorded(Request $request): Response
    {
        $callSid = (string) $request->input('CallSid', '');
        $text = trim((string) $request->input('TranscriptionText', ''));
        $status = (string) $request->input('TranscriptionStatus', '');

        // "failed" is Twilio saying it could not make the audio out. Storing
        // its empty string would be indistinguishable from never having asked,
        // and the recording is still the record either way.
        if ($callSid === '' || $text === '' || $status === 'failed') {
            return response('', Status::HTTP_OK);
        }

        DB::transaction(function () use ($callSid, $text, $request) {
            $call = PhoneCall::query()->where('twilio_sid', $callSid)->first();
            if ($call === null) {
                return;
            }

            $sid = (string) $request->input('TranscriptionSid', '');
            if ($sid !== '' && $call->transcription_sid === null) {
                $call->transcription_sid = $sid;
            }

            // Replaced, not appended. Twilio sends this once per recording;
            // a retry must not leave the message written down twice.
            $call->voicemail_transcript = $text;
            $call->save();
        });

        return response('', Status::HTTP_OK);
    }

    private function isFinal(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function utterance(mixed $data): string
    {
        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                return trim($data);
            }
        }

        if (! is_array($data)) {
            return '';
        }

        $text = $data['transcript'] ?? $data['Transcript'] ?? '';

        return is_string($text) ? trim($text) : '';
    }
}
