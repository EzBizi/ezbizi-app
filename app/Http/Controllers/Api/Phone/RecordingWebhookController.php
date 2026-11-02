<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\OpensTwilioWebhook;
use App\Models\AutomationEvent;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Public Twilio recording status callback. Signature is auth; path names tenant.
 *
 * Stores recording_sid on the call. A Record-verb (voicemail) recording also
 * sets voicemail and raises phone.voicemail.left once.
 */
class RecordingWebhookController extends Controller
{
    use OpensTwilioWebhook;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly EventEmitter $events,
    ) {}

    private function handle(Request $request, PhoneConnection $connection): Response
    {
        $sid = (string) $request->input('CallSid', '');
        $recordingSid = (string) $request->input('RecordingSid', '');
        $status = (string) $request->input('RecordingStatus', '');

        if ($sid === '' || $recordingSid === '' || $status !== 'completed') {
            return response('', Status::HTTP_OK);
        }

        DB::transaction(function () use ($request, $sid, $recordingSid) {
            $call = PhoneCall::query()->where('twilio_sid', $sid)->lockForUpdate()->first();
            if ($call === null) {
                return;
            }

            $call->recording_sid = $recordingSid;

            $voicemail = (string) $request->input('RecordingSource', '') === 'RecordVerb';
            if ($voicemail) {
                $call->voicemail = true;

                // The group's or person's mailbox it was left in, when it was
                // not the line's (TwimlRenderer::voicemail adds `box`). Only a
                // box on this call's account is taken.
                $box = (string) $request->query('box', '');
                if ($box !== '' && \App\Models\PhoneVoicemailBox::query()
                    ->where('connection_id', $call->connection_id)->whereKey($box)->exists()) {
                    $call->voicemail_box_id = $box;
                }
            }

            $call->save();

            if ($voicemail && ! $this->alreadyLeft($call)) {
                $this->events->emit('phone.voicemail.left', $call, $this->payload($call));
            }
        });

        return response('', Status::HTTP_OK);
    }

    private function alreadyLeft(PhoneCall $call): bool
    {
        return AutomationEvent::query()
            ->where('event_key', 'phone.voicemail.left')
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
            'recording_sid' => $call->recording_sid,
            'voicemail' => $call->voicemail,
            'number_id' => $call->number_id,
        ]];
    }
}
