<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Thumbtack telling us something happened.
 *
 * ── This is the only way a Thumbtack lead ever arrives ───────────
 *
 * Their supply-side API can fetch a negotiation by id and cannot list them. So
 * this endpoint is not a faster path to something we could also poll for — it
 * is the entire delivery mechanism, and an outage here loses enquiries that
 * nothing can go back and find.
 *
 * ── How it knows the caller is Thumbtack ─────────────────────────
 *
 * Thumbtack does not sign these. It sends back a credential we chose and gave
 * them at registration, in a header we named, and that header is the whole of
 * the authentication — the body carries no proof of origin whatsoever.
 *
 * The consequence, and the reason the checks below are ordered the way they
 * are: anybody can POST here. A body claiming a businessID is a claim, not a
 * fact, so the connection is found by that id and the presented secret is
 * checked against THAT connection's own secret. Trusting the body first and
 * checking afterwards would let a caller nominate which tenant to be.
 *
 * ── Why it answers 200 to things it does not act on ──────────────
 *
 * An event type we do not handle, or a business we no longer have connected,
 * is not an error on Thumbtack's part and answering 4xx would make them retry
 * something that will never succeed, then eventually disable the webhook —
 * which would silently stop lead delivery for every event, including the ones
 * that do work. Refusals are reserved for a caller that failed to authenticate.
 */
class ThumbtackWebhookController extends Controller
{
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        $businessId = $this->businessIdFrom($request);

        if ($businessId === null) {
            // Nothing to authenticate against. Not 401: a body we cannot read
            // is not a credential failure, and telling an unauthenticated
            // caller which of the two it was is free information.
            return response()->json(['data' => ['handled' => false]], Status::HTTP_OK);
        }

        $connection = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Thumbtack->value)
            ->where('remote_id', $businessId)
            ->first();

        if ($connection === null) {
            return response()->json(['data' => ['handled' => false]], Status::HTTP_OK);
        }

        // THE authentication. Constant-time, against this connection's own
        // secret rather than a global one, so a credential leaked from one
        // tenant cannot be replayed against another.
        abort_unless(
            ThumbtackWebhooks::for($connection)->accepts($request->header(ThumbtackWebhooks::AUTH_HEADER)),
            Status::HTTP_UNAUTHORIZED,
        );

        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($connection->organization_id);

        if ($organization === null) {
            return response()->json(['data' => ['handled' => false]], Status::HTTP_OK);
        }

        $event = (string) ($request->input('eventType') ?? $request->input('type') ?? '');

        $handled = $tenant->run(
            $organization,
            fn () => $this->handle($event, $request->all(), $connection),
        );

        return response()->json(['data' => ['handled' => $handled]]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(string $event, array $payload, SocialConnection $connection): bool
    {
        $connection->forceFill(['last_reached_at' => now()])->save();

        return match ($event) {
            // Deliberately not implemented yet, and answered honestly rather
            // than with a silent true. Turning a negotiation into an EzBizi
            // Lead row is the next slice; what this endpoint guarantees today
            // is that the event ARRIVED and was authenticated, which is the
            // part that cannot be reconstructed later.
            'NegotiationCreatedV4', 'MessageCreatedV4', 'ReviewCreatedV4' => false,
            default => false,
        };
    }

    /**
     * The business this event is about, from wherever they put it.
     *
     * Their payloads nest it differently per event type, and an event whose id
     * we cannot find is one we cannot authenticate — so this reads broadly and
     * the caller treats null as "not for us".
     */
    private function businessIdFrom(Request $request): ?string
    {
        foreach ([
            'businessID',
            'business.businessID',
            'data.businessID',
            'data.business.businessID',
            'negotiation.business.businessID',
        ] as $key) {
            $value = $request->input($key);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
