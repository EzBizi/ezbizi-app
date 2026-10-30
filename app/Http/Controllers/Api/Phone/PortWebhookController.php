<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Http\Controllers\Api\Phone\Concerns\OpensTwilioWebhook;
use App\Models\PhoneConnection;
use App\Models\PhonePortRequest;
use App\Support\Phone\HttpTwilioGateway;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Public Twilio port-in status webhook. Signature is auth; path names the tenant.
 */
class PortWebhookController extends Controller
{
    use OpensTwilioWebhook;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    private function handle(Request $request, PhoneConnection $connection): Response
    {
        $sid = (string) $request->input('port_in_request_sid', '');
        if ($sid === '') {
            return response('', Status::HTTP_OK);
        }

        $row = PhonePortRequest::query()
            ->where('connection_id', $connection->id)
            ->where('twilio_sid', $sid)
            ->first();

        if ($row === null) {
            return response('', Status::HTTP_OK);
        }

        $status = (string) $request->input('status', $row->status);
        $row->status = HttpTwilioGateway::normalizePortStatus($status);

        $reason = $request->input('rejection_reason');
        if (is_string($reason) && $reason !== '') {
            $row->status_reason = $reason;
        } else {
            $notPortable = $request->input('not_portable_reason');
            if (is_string($notPortable) && $notPortable !== '') {
                $row->status_reason = $notPortable;
            }
        }

        $row->save();

        return response('', Status::HTTP_OK);
    }
}
