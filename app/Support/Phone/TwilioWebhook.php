<?php

namespace App\Support\Phone;

use App\Models\Organization;
use App\Models\PhoneConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Shared gate for public Twilio POSTs: path names the tenant, signature is auth.
 *
 * @template T
 */
final class TwilioWebhook
{
    /**
     * @template T
     * @param  callable(PhoneConnection): T  $then
     * @return T
     */
    public static function open(
        Request $request,
        string $organization,
        string $connection,
        TenantContext $tenant,
        callable $then,
    ): mixed {
        $org = Organization::query()->find($organization);
        if ($org === null) {
            abort(Status::HTTP_NOT_FOUND);
        }

        return $tenant->run($org, function () use ($request, $connection, $then) {
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

            return $then($row);
        });
    }
}
