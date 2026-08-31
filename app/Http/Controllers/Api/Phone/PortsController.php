<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\PhoneConnection;
use App\Models\PhonePortRequest;
use App\Support\Phone\TwilioGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Start a Twilio port-in for numbers onto a connection (spec §6).
 */
class PortsController extends Controller
{
    public function __construct(
        private readonly TwilioGateway $twilio,
    ) {}

    /**
     * GET /api/v1/phone/ports
     *
     * Ports started on the phone accounts this person can reach.
     *
     * There was no way to read these. A port could be started, the carrier
     * webhook updated its status for weeks, and nobody could see any of it —
     * a port-in takes days to complete and the only surface reporting on it
     * was a row nothing selected. Moving a business's main number is the
     * single most anxious thing this module does, so it needs a screen more
     * than most of what has one.
     */
    public function index(Request $request): JsonResponse
    {
        $connections = PhoneConnection::query()->get()
            ->filter(fn (PhoneConnection $c) => $request->user()->can('view', $c))
            ->pluck('id');

        if ($connections->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $rows = PhonePortRequest::query()
            ->whereIn('connection_id', $connections)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PhonePortRequest $row) => $this->row($row))
            ->all();

        return response()->json(['data' => $rows]);
    }

    /** POST /api/v1/phone/ports */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate([
            'connection_id' => ['required', 'uuid'],
            'e164s' => ['required', 'array', 'min:1'],
            'e164s.*' => ['required', 'string', 'max:16'],
            'documents' => ['sometimes', 'array'],
            'losing_carrier' => ['sometimes', 'array'],
            'notification_emails' => ['sometimes', 'array'],
        ]);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);

        $this->authorize('update', $connection);

        $e164s = array_values($input['e164s']);
        $params = array_filter([
            'documents' => $input['documents'] ?? null,
            'losing_carrier' => $input['losing_carrier'] ?? null,
            'notification_emails' => $input['notification_emails'] ?? null,
        ], fn ($v) => $v !== null);

        $started = $this->twilio->startPort($connection, $e164s, $params);

        $row = PhonePortRequest::query()->create([
            'connection_id' => $connection->id,
            'e164s' => $e164s,
            'twilio_sid' => $started['sid'],
            'status' => $started['status'],
        ]);

        return response()->json(['data' => $this->row($row)], Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
    private function row(PhonePortRequest $row): array
    {
        return [
            'id' => $row->id,
            'connection_id' => $row->connection_id,
            'e164s' => $row->e164s,
            'twilio_sid' => $row->twilio_sid,
            'status' => $row->status,
            'status_reason' => $row->status_reason,
            'created_at' => $row->created_at?->toIso8601String(),

            // Said by the server rather than worked out on screen, because
            // the same list is read by the web client and the phone app and a
            // port that looks finished in one and pending in the other is
            // worse than either answer alone.
            'settled' => in_array(
                strtolower((string) $row->status),
                ['completed', 'canceled', 'cancelled', 'failed', 'expired'],
                true,
            ),
        ];
    }
}
