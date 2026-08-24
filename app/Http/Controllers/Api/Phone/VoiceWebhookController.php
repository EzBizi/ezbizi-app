<?php

namespace App\Http\Controllers\Api\Phone;

use App\Http\Controllers\Api\Controller;
use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Support\Phone\TwilioSignature;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as Status;
use Twilio\TwiML\VoiceResponse;

/**
 * Public Twilio Voice URL. Signature is auth; path names the tenant.
 *
 * Stub until Task 10: validate, then empty <Response/>.
 */
class VoiceWebhookController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * POST /api/v1/phone/voice/{organization}/{connection}
     *
     * Path ids are plain strings — route model binding would need a tenant
     * before the connection can be read, and this request has none yet.
     */
    public function __invoke(Request $request, string $organization, string $connection): Response
    {
        $org = Organization::query()->find($organization);
        if ($org === null) {
            abort(Status::HTTP_NOT_FOUND);
        }

        return $this->tenant->run($org, function () use ($request, $connection) {
            $row = PhoneConnection::query()->find($connection);
            if ($row === null) {
                abort(Status::HTTP_NOT_FOUND);
            }

            $url = $request->fullUrl();
            $params = $request->request->all();
            $header = $request->header('X-Twilio-Signature');

            if (! TwilioSignature::valid($row, $url, $params, is_string($header) ? $header : null)) {
                abort(Status::HTTP_FORBIDDEN, 'This request was not from Twilio.');
            }

            $twiml = new VoiceResponse;

            return response((string) $twiml, Status::HTTP_OK)
                ->header('Content-Type', 'text/xml');
        });
    }
}
