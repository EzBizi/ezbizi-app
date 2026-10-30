<?php

namespace App\Http\Controllers\Api\Phone\Concerns;

use App\Models\PhoneConnection;
use App\Support\Phone\TwilioWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The `__invoke` of a Twilio callback addressed by organization and
 * connection: open it through {@see TwilioWebhook::open} and hand the
 * connection to the controller's own `handle`. The using controller holds a
 * `TenantContext $tenant`.
 *
 * Path ids are plain strings — route model binding would need a tenant
 * before the connection can be read, and this request has none yet.
 */
trait OpensTwilioWebhook
{
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
}
