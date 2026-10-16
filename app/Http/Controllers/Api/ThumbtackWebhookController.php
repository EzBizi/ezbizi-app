<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Services\Leads\ThumbtackLeads;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackWebhooks;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;

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

        /*
         * ALL of them, because a businessID does not name one connection.
         *
         * `social_connections` is unique on (organization_id, provider,
         * remote_id) — so two organizations may each connect the SAME Thumbtack
         * business, and that is correct: they are separate tenants who both
         * have a relationship with it.
         *
         * This used to take ->first() and check that one's secret. With two
         * tenants on one business it picked arbitrarily, so roughly half of a
         * legitimate tenant's events were answered 401. Thumbtack retries a
         * 401, then disables the webhook — and their API cannot list
         * negotiations, so everything missed in between is gone for good. The
         * bug would only ever have appeared once a second organization
         * connected, which is to say in front of a customer.
         */
        $candidates = SocialConnection::query()
            ->withoutGlobalScopes()
            ->where('provider', SocialProvider::Thumbtack->value)
            ->where('remote_id', $businessId)
            ->get();

        $presented = $request->header(ThumbtackWebhooks::AUTH_HEADER);

        /*
         * THE authentication, and also the disambiguation.
         *
         * The secret is per connection, so the one that accepts this header IS
         * the tenant the event belongs to. Matching on it does both jobs at
         * once and cannot cross tenants: a credential leaked from one
         * organization matches only its own row.
         *
         * Constant-time inside accepts(). Checking every candidate is not a
         * timing leak worth worrying about — the count is the number of tenants
         * sharing one Thumbtack business, which is one.
         */
        $connection = $candidates->first(
            fn (SocialConnection $c) => ThumbtackWebhooks::for($c)->accepts($presented),
        );

        if ($connection === null) {
            // Nothing matched. If there were candidates, this is a bad
            // credential and must say so; if there were none, the business is
            // not connected here and 200 keeps Thumbtack from disabling a
            // webhook over an account we simply do not have.
            abort_if($candidates->isNotEmpty(), Status::HTTP_UNAUTHORIZED);

            return response()->json(['data' => ['handled' => false]], Status::HTTP_OK);
        }

        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($connection->organization_id);

        if ($organization === null) {
            return response()->json(['data' => ['handled' => false]], Status::HTTP_OK);
        }

        /*
         * `event.eventType`, which is where Thumbtack actually puts it.
         *
         * This read `eventType` and `type` at the top level until 2026-09-09,
         * and neither exists. Their real body is an ENVELOPE:
         *
         *     { "event": { "eventType": "NegotiationCreatedV4", "webhookID": …,
         *                  "triggeredAt": … },
         *       "data":  { "negotiationID": …, "customer": …,
         *                  "business": { "businessID": … } } }
         *
         * So $event came out as an empty string, fell past both lead branches,
         * and returned handled:false. The businessID and negotiationID keys
         * were guessed correctly — `data.business.businessID` and
         * `data.negotiationID` are both in the lists below — which is why every
         * event authenticated perfectly and then did nothing at all. Two real
         * enquiries were lost that way, and their API has no list endpoint to
         * go back for them.
         *
         * The shape is documented, in the `webhooks` section of their own
         * OpenAPI 3.1 document (api.thumbtack.com/docs/thumbtack_api_latest.json)
         * — a top-level key beside `paths`, which is why reading the endpoint
         * reference never showed it.
         *
         * The flat forms are kept behind it. They cost one array lookup and
         * they are the difference between a changed envelope losing every
         * enquiry and it losing none.
         */
        $event = (string) (
            $request->input('event.eventType')
            ?? $request->input('eventType')
            ?? $request->input('type')
            ?? ''
        );

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

        // Both lead events go through the same path. A message can be the
        // FIRST thing we hear about an enquiry — the negotiation event can be
        // missed, or predate the connection — so absorbing the negotiation
        // first means a message never arrives with nowhere to be filed.
        if ($event === 'NegotiationCreatedV4' || $event === 'MessageCreatedV4') {
            $negotiationId = $this->negotiationIdFrom($payload);

            if ($negotiationId === null) {
                /*
                 * An authenticated lead event that produced nothing, which is
                 * the worst thing this endpoint can do quietly.
                 *
                 * On 2026-09-09 Thumbtack sent two real events to a connected
                 * business, both authenticated, both answered 200 with
                 * handled:false, and nothing was written or logged. From the
                 * outside that is indistinguishable from Thumbtack never having
                 * sent anything — and their API cannot list negotiations, so
                 * there is no second way to find what was missed.
                 *
                 * The keys are logged, never the values: a negotiation payload
                 * carries a customer's name and phone number, and this is a
                 * diagnostic, not a copy of the lead.
                 */
                Log::warning('Thumbtack sent a lead event with no negotiation id.', [
                    'event' => $event,
                    'connection' => $connection->getKey(),
                    'business' => $connection->remote_id,
                    'payload_keys' => $this->shapeOf($payload),
                ]);

                return false;
            }

            try {
                app(ThumbtackLeads::class)->absorb(
                    $connection,
                    $negotiationId,
                    // The event's own detail. NegotiationCreatedV4 carries the
                    // whole enquiry here, so this saves a round trip and works
                    // for a specimen, whose ids refer to nothing fetchable.
                    carried: (array) ($payload['data'] ?? []),
                );
            } catch (Throwable $e) {
                /*
                 * Reported, then RE-THROWN so this answers 500 and Thumbtack
                 * retries — which is the opposite of what the rest of this
                 * class does, and deliberate.
                 *
                 * Everywhere else, a 4xx/5xx risks Thumbtack disabling the
                 * webhook and stopping delivery entirely. Here the alternative
                 * is worse: their API has no way to list negotiations, so an
                 * enquiry we acknowledge and fail to store is gone permanently,
                 * with nothing able to find it again. A disabled webhook is
                 * recoverable by reconnecting; a lost lead is not.
                 *
                 * The report is what makes the retry visible — otherwise a
                 * failing fetch is a silent 500 nobody reads.
                 */
                report($e);

                throw $e;
            }

            return true;
        }

        /*
         * ReviewCreatedV4 is subscribed to and deliberately not acted on here.
         *
         * A review is not a lead, and Reviews is its own module that connects
         * its own sources. Filing one as a Lead row would put it in the wrong
         * list under a contact name that is not an enquirer. The subscription
         * stays so the event is not lost when that module grows a Thumbtack
         * source; answering false says plainly that nothing was written.
         */
        if ($event !== 'ReviewCreatedV4') {
            /*
             * An event type we did not expect at all.
             *
             * Reviews reaching here is normal and silent. Anything else means
             * either Thumbtack sends a type absent from their own webhook enum,
             * or the type sits under a key we do not read — and both look
             * exactly like a working integration receiving nothing, because
             * that is what this endpoint reports.
             *
             * Logged as a warning rather than dropped, because it is the first
             * thing worth reading when somebody says "I connected it and no
             * leads are showing up".
             */
            Log::warning('Thumbtack sent an event type EzBizi does not handle.', [
                'event' => $event === '' ? '(none found in payload)' : $event,
                'connection' => $connection->getKey(),
                'business' => $connection->remote_id,
                'payload_keys' => $this->shapeOf($payload),
            ]);
        }

        return false;
    }

    /**
     * The nesting of a payload, without any of its values.
     *
     * "negotiation.business.businessID" rather than the customer's phone
     * number. It is the shape that is wrong when an id cannot be found, and the
     * shape is the part that is safe to write down.
     *
     * @param  array<string, mixed>  $payload
     * @return string[]
     */
    private function shapeOf(array $payload, string $prefix = '', int $depth = 0): array
    {
        $keys = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            // Three deep is past where any documented id sits; deeper than that
            // is a message body or an attachment list, and listing it would be
            // noise in a log somebody reads in a hurry.
            if (is_array($value) && $depth < 3) {
                $keys = [...$keys, ...$this->shapeOf($value, $path, $depth + 1)];

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }

    /**
     * The negotiation this event is about, from wherever they nest it.
     *
     * Same defensive read as the business id, and for the same reason: their
     * payload shape differs per event type, and an id we cannot find is work
     * we cannot do.
     *
     * @param  array<string, mixed>  $payload
     */
    private function negotiationIdFrom(array $payload): ?string
    {
        foreach ([
            'negotiationID',
            'negotiation.negotiationID',
            'data.negotiationID',
            'data.negotiation.negotiationID',
            'message.negotiationID',
            'data.message.negotiationID',
        ] as $key) {
            $value = data_get($payload, $key);

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
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
