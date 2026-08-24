<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Support\Phone\TwilioWebhook;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Public Twilio Real-Time Transcription callback.
 *
 * Live transcription-content events are not stored utterance-by-utterance.
 * Finals concatenate onto phone_calls.transcript; TranscriptionSid is kept.
 */
class TranscriptionWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * POST /api/v1/phone/transcription/{organization}/{connection}
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
        $event = (string) $request->input('TranscriptionEvent', '');
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
