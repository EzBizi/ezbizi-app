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

            // What the losing carrier has on file (Jose, 2026-10-05: port in
            // "showing pin codes and verification"). Twilio's own fields.
            'losing_carrier.customer_type' => ['required_with:losing_carrier', 'in:Individual,Business'],
            'losing_carrier.customer_name' => ['required_with:losing_carrier', 'string', 'max:191'],
            'losing_carrier.account_number' => ['nullable', 'string', 'max:64'],
            'losing_carrier.account_telephone_number' => ['nullable', 'string', 'max:20'],
            'losing_carrier.authorized_representative' => ['required_with:losing_carrier', 'string', 'max:191'],
            'losing_carrier.authorized_representative_email' => ['required_with:losing_carrier', 'email', 'max:191'],
            'losing_carrier.address' => ['sometimes', 'array'],
            'losing_carrier.address.street' => ['required_with:losing_carrier.address', 'string', 'max:191'],
            'losing_carrier.address.street_2' => ['nullable', 'string', 'max:191'],
            'losing_carrier.address.city' => ['required_with:losing_carrier.address', 'string', 'max:96'],
            'losing_carrier.address.state' => ['required_with:losing_carrier.address', 'string', 'max:64'],
            'losing_carrier.address.zip' => ['required_with:losing_carrier.address', 'string', 'max:16'],
            'losing_carrier.address.country' => ['nullable', 'string', 'max:64'],

            // Required by US mobile carriers, for every number in the port.
            'pin' => ['nullable', 'string', 'max:20'],
            'notification_emails' => ['sometimes', 'array'],
        ]);

        $connection = PhoneConnection::query()->find($input['connection_id']);
        abort_if($connection === null, Response::HTTP_NOT_FOUND);

        $this->authorize('update', $connection);

        $e164s = array_values($input['e164s']);
        $params = array_filter([
            'documents' => $input['documents'] ?? null,
            'losing_carrier' => $input['losing_carrier'] ?? null,
            'pin' => $input['pin'] ?? null,
            'notification_emails' => $input['notification_emails'] ?? null,
        ], fn ($v) => $v !== null);

        $started = $this->twilio->startPort($connection, $e164s, $params);

        $row = PhonePortRequest::query()->create([
            'connection_id' => $connection->id,
            'e164s' => $e164s,
            'twilio_sid' => $started['sid'],
            'status' => $started['status'],
            'carrier' => ($input['losing_carrier'] ?? null) === null && ($input['pin'] ?? null) === null
                ? null
                : ['losing_carrier' => $input['losing_carrier'] ?? null, 'pin' => $input['pin'] ?? null],
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

            // What the losing carrier was told, PIN included: this list is
            // behind the Settings tab, and phone.edit is what starts a port.
            'carrier' => $row->carrier,

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
