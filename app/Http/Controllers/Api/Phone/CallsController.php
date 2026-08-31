<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Customer;
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

        $query = PhoneCall::query()
            ->whereIn('number_id', $numberIds)
            ->orderByDesc('created_at');

        // The desk asks for this the moment a call ends, to know which row to
        // ask about. Only THIS person's calls: two people hang up at once and
        // otherwise each could be handed the other's.
        if ($request->boolean('untagged')) {
            $query->whereNull('tag_id')->where('user_id', $request->user()->id);
        }

        $perPage = (int) $request->query('per_page', 0);
        if ($perPage > 0) {
            $query->limit(min($perPage, 200));
        }

        $calls = $query->get();

        $customerIds = $this->customerIdsByPhone($calls);

        $rows = $calls
            ->map(fn (PhoneCall $call) => $this->row($call, $customerIds))
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

    /**
     * @param  \Illuminate\Support\Collection<int, PhoneCall>  $calls
     * @return array<string, string>
     */
    private function customerIdsByPhone($calls): array
    {
        $phones = [];
        foreach ($calls as $call) {
            $outside = $this->outsideNumber($call);
            if ($outside !== '') {
                $phones[] = $outside;
            }
        }

        if ($phones === []) {
            return [];
        }

        return Customer::query()
            ->whereIn('phone', array_values(array_unique($phones)))
            ->pluck('id', 'phone')
            ->all();
    }

    private function outsideNumber(PhoneCall $call): string
    {
        return $call->direction === PhoneCall::DIRECTION_INBOUND
            ? (string) $call->from_e164
            : (string) $call->to_e164;
    }

    /**
     * @param  array<string, string>  $customerIds
     * @return array<string, mixed>
     */
    private function row(PhoneCall $call, array $customerIds = []): array
    {
        $outside = $this->outsideNumber($call);

        return [
            'tag_id' => $call->tag_id,
            'tag' => $call->tag?->name,

            // Sent with the name so a call list can be read at a glance. A
            // colour set in Settings and invisible everywhere else would be
            // decoration, which is the shape this module keeps producing.
            'tag_color' => $call->tag?->color,
            'id' => $call->id,
            'number_id' => $call->number_id,
            'connection_id' => $call->connection_id,
            'twilio_sid' => $call->twilio_sid,
            'direction' => $call->direction,
            'from_e164' => $call->from_e164,
            'to_e164' => $call->to_e164,
            'status' => $call->status,
            'duration_seconds' => $call->duration_seconds,
            'voicemail' => (bool) $call->voicemail,
            'recording_sid' => $call->recording_sid,
            'transcript' => $call->transcript,
            'customer_id' => $customerIds[$outside] ?? null,
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
