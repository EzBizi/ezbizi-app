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
        ];
    }
}
