<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Phone\TwilioGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Call records for granted numbers (spec §11). Playback streams Twilio media
 * through EzBizi with the connection credentials — never a bare Twilio URL.
 */
class CallsController extends Controller
{
    public function __construct(
        private readonly PhoneNumberAccess $access,
        private readonly TwilioGateway $twilio,
    ) {}

    /** GET /api/v1/phone/calls */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PhoneCall::class);

        $numberIds = $this->access->readable($request->user())->pluck('id')->all();

        $rows = PhoneCall::query()
            ->whereIn('number_id', $numberIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PhoneCall $call) => $this->row($call))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** GET /api/v1/phone/calls/{call}/recording */
    public function recording(PhoneCall $call): Response
    {
        $this->authorize('view', $call);

        $recordingSid = (string) ($call->recording_sid ?? '');
        if ($recordingSid === '') {
            abort(Status::HTTP_NOT_FOUND);
        }

        $connection = $call->connection;
        if ($connection === null) {
            abort(Status::HTTP_NOT_FOUND);
        }

        $bytes = $this->twilio->recordingMedia($connection, $recordingSid);

        return response($bytes, Status::HTTP_OK)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Content-Disposition', 'inline; filename="recording.mp3"')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** @return array<string, mixed> */
    private function row(PhoneCall $call): array
    {
        return [
            'id' => $call->id,
            'number_id' => $call->number_id,
            'connection_id' => $call->connection_id,
            'twilio_sid' => $call->twilio_sid,
            'direction' => $call->direction,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,
            'status' => $call->status,
            'duration_seconds' => $call->duration_seconds,
            'voicemail' => $call->voicemail,
            'recording_sid' => $call->recording_sid,
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
