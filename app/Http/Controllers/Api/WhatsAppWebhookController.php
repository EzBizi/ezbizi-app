<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\WhatsAppMessage;
use App\Support\Social\WhatsAppCloud;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;

/**
 * Meta telling us a message arrived, or what became of one we sent.
 *
 * ── Two endpoints, and the first one is a handshake ──────────────
 *
 * `verify()` answers the GET Meta makes when somebody types the callback URL
 * into the app dashboard. It must echo `hub.challenge` AS PLAIN TEXT with a 200
 * and nothing else — a JSON body, a wrapped envelope or a 201 all read as
 * failure and the URL cannot be saved. That is the whole of its job.
 *
 * ── The signature is over the raw body ───────────────────────────
 *
 * `$request->getContent()`, never a re-encoded array. Hashing `json_encode` of
 * the decoded payload gives a different digest for the same message and every
 * delivery is then rejected as forged — silently, because a rejected webhook
 * looks exactly like Meta not sending one.
 *
 * ── Why it answers 200 to things it does not act on ──────────────
 *
 * Same reason as the Thumbtack webhook next door. A field we do not handle is
 * not Meta's mistake, and a 4xx makes them retry and then eventually disable
 * the subscription — which stops delivery for the events that DO work. A
 * refusal is reserved for a body that failed to authenticate.
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * GET — the subscription handshake.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.meta.whatsapp_verify_token');

        /*
         * `hub_mode`, not `hub.mode`, and this looks wrong until you know why.
         *
         * Meta sends `hub.mode`, `hub.verify_token` and `hub.challenge` with
         * DOTS. PHP turns a dot in a query-string key into an underscore while
         * parsing, before any of this runs, so the dotted names never reach
         * Laravel and reading them returns null - which fails the handshake
         * with no error anywhere.
         */

        // A token we have not configured must not accept an empty one. Without
        // this, an unconfigured deployment verifies ANY caller's subscription.
        if ($expected === '' || $request->query('hub_mode') !== 'subscribe') {
            return response('', Status::HTTP_FORBIDDEN);
        }

        if (! hash_equals($expected, (string) $request->query('hub_verify_token'))) {
            return response('', Status::HTTP_FORBIDDEN);
        }

        // Plain text, echoed exactly. Meta compares the body byte for byte.
        return response((string) $request->query('hub_challenge'), Status::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * POST — messages in, and statuses for messages out.
     */
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        if (! WhatsAppCloud::signed($request->getContent(), $request->header('X-Hub-Signature-256'))) {
            // The one case that is a refusal: somebody who is not Meta.
            return response()->json(['data' => ['handled' => false]], Status::HTTP_UNAUTHORIZED);
        }

        $handled = 0;

        foreach ((array) $request->input('entry', []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                $phoneNumberId = (string) ($value['metadata']['phone_number_id'] ?? '');

                if ($phoneNumberId === '') {
                    continue;
                }

                /*
                 * Which tenant this belongs to is decided by the phone number
                 * id, which is OURS - we registered it - and never by anything
                 * in the message. A body naming an organization would be a
                 * claim by the caller about which tenant to write to.
                 *
                 * An id we do not recognise is answered 200 and dropped: it is
                 * a number registered on the app that this deployment does not
                 * own, which is Meta's business and not an error.
                 */
                $organization = $this->organizationFor($phoneNumberId);

                if ($organization === null) {
                    continue;
                }

                try {
                    $handled += $tenant->run(
                        $organization,
                        fn () => $this->absorb($value, $phoneNumberId, $organization->getKey()),
                    );
                } catch (Throwable $e) {
                    // Logged and swallowed. One malformed change must not cost
                    // the other changes in the same delivery, and a 500 makes
                    // Meta retry the whole batch including the parts that
                    // worked.
                    Log::warning('A WhatsApp webhook change could not be absorbed.', [
                        'phone_number_id' => $phoneNumberId,
                        'reason' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['data' => ['handled' => $handled]], Status::HTTP_OK);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function absorb(array $value, string $phoneNumberId, string $organizationId): int
    {
        $handled = 0;

        foreach ((array) ($value['messages'] ?? []) as $message) {
            $wamid = (string) ($message['id'] ?? '');

            if ($wamid === '') {
                continue;
            }

            /*
             * updateOrCreate on the wamid, not create.
             *
             * Meta retries a delivery it did not get a 200 for, and a retry
             * carries the SAME message id. Creating blindly turns one customer
             * message into three in the list - and the unique index would throw
             * on the second rather than politely doing nothing.
             */
            WhatsAppMessage::updateOrCreate(
                ['wamid' => $wamid],
                [
                    'organization_id' => $organizationId,
                    'phone_number_id' => $phoneNumberId,
                    'direction' => WhatsAppMessage::DIRECTION_IN,
                    'counterparty' => (string) ($message['from'] ?? ''),
                    'status' => WhatsAppMessage::STATUS_RECEIVED,
                    'kind' => (string) ($message['type'] ?? 'text'),
                    // Only text is read. An image or a location arrives as a
                    // row with a null body and its real `kind`, which is
                    // honest - better than an empty string that reads as an
                    // empty message somebody sent.
                    'body' => $message['text']['body'] ?? null,
                ],
            );

            $handled++;
        }

        foreach ((array) ($value['statuses'] ?? []) as $status) {
            $wamid = (string) ($status['id'] ?? '');
            $state = (string) ($status['status'] ?? '');

            if ($wamid === '' || $state === '') {
                continue;
            }

            $row = WhatsAppMessage::query()->where('wamid', $wamid)->first();

            if ($row === null) {
                // A status for a message this deployment did not send. Not an
                // error - the same number can be used by another tool - and
                // inventing a row for it would put a message in the list that
                // nobody here sent.
                continue;
            }

            $row->forceFill(array_filter([
                'status' => $state,
                'status_reason' => $status['errors'][0]['title'] ?? null,
                'delivered_at' => $state === WhatsAppMessage::STATUS_DELIVERED ? now() : null,
                'read_at' => $state === WhatsAppMessage::STATUS_READ ? now() : null,
            ], fn ($v) => $v !== null))->save();

            $handled++;
        }

        return $handled;
    }

    private function organizationFor(string $phoneNumberId): ?Organization
    {
        /*
         * DB::table, and withoutGlobalScopes on the model read, because there
         * is no tenant in context yet - that is the thing being worked out.
         * `DB::table` is not tenant-scoped, which is a hazard everywhere else
         * and is exactly what is wanted here.
         */
        $organizationId = DB::table('whatsapp_numbers')
            ->where('phone_number_id', $phoneNumberId)
            ->value('organization_id');

        if ($organizationId === null) {
            return null;
        }

        return Organization::query()->withoutGlobalScopes()->find($organizationId);
    }
}
