<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneCall;
use App\Support\Phone\PhoneNumberAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Call records for granted numbers (spec §11, Task 10).
 */
class CallsController extends Controller
{
    public function __construct(
        private readonly PhoneNumberAccess $access,
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
            'created_at' => $call->created_at?->toIso8601String(),
        ];
    }
}
