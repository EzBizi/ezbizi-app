<?php

namespace App\Http\Controllers\Api;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Social\MetaLeadForms;
use App\Support\Social\MetaMessages;
use App\Support\Social\WhatsAppCloud;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as Status;
use Throwable;

/**
 * Meta telling us somebody messaged a Page or an Instagram account.
 *
 * ── The same shape as the WhatsApp webhook, deliberately ─────────
 *
 * Same handshake, same signature rule, same "200 to things we do not act on".
 * It is a separate controller rather than a branch inside that one because the
 * two answer different products with different payload shapes, and the WhatsApp
 * one is live - a change there to accommodate this would put a working inbound
 * path at risk for a feature that has none yet.
 *
 * The signature check is SHARED, through `WhatsAppCloud::signed`. Both
 * deliveries are signed with the one app secret, and a second copy of an HMAC
 * comparison is a second place for somebody to write `===` instead of
 * `hash_equals`.
 *
 * ── Why it answers 200 to a Page it does not know ────────────────
 *
 * An id we have no connection for is not an error: the app can be installed on
 * a Page this deployment does not hold, and a 4xx makes Meta retry and then
 * disable the subscription - which stops delivery for the Pages that DO work.
 */
class MetaMessagesWebhookController extends Controller
{
    public function __construct(
        private readonly MetaMessages $messages,
        private readonly MetaLeadForms $forms,
    ) {}

    /**
     * GET - the subscription handshake.
     *
     * `hub_mode` and not `hub.mode`: PHP turns a dot in a query-string key into
     * an underscore while parsing, before any of this runs, so the dotted names
     * never reach Laravel and reading them returns null - which fails the
     * handshake with no error anywhere.
     */
    public function verify(Request $request): Response
    {
        $expected = (string) config('services.meta.messages_verify_token');

        // An unconfigured deployment must verify NOBODY. Without this, an empty
        // expected token accepts an empty supplied one and anybody can point
        // their own Meta app's webhook at this deployment.
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
     * POST - messages in.
     */
    public function __invoke(Request $request, TenantContext $tenant): JsonResponse
    {
        // Instagram Login deliveries are signed with the INSTAGRAM app's secret,
        // not the Facebook app's (Meta's two app ids, one app). Either of ours
        // proves the sender; before 2026-09-28 every Instagram message was 401.
        $signature = $request->header('X-Hub-Signature-256');
        $instagramSecret = (string) config('services.meta.instagram_secret');

        if (! WhatsAppCloud::signed($request->getContent(), $signature)
            && ! ($instagramSecret !== '' && WhatsAppCloud::signed($request->getContent(), $signature, $instagramSecret))) {
            return response()->json(['data' => ['handled' => false]], Status::HTTP_UNAUTHORIZED);
        }

        $object = (string) $request->input('object', '');
        $source = $this->messages->sourceFor($object);

        if ($source === null) {
            // A field this app is subscribed to and this controller does not
            // read. Acknowledged rather than refused.
            return response()->json(['data' => ['handled' => 0]], Status::HTTP_OK);
        }

        $handled = 0;

        foreach ((array) $request->input('entry', []) as $entry) {
            /*
             * `messaging` for a Page, `messaging` for Instagram too - but
             * Instagram also delivers under `standby` when another app holds
             * the conversation. Standby is deliberately NOT read: acting on it
             * would answer on behalf of a business whose inbox somebody else is
             * currently handling.
             */
            foreach ((array) ($entry['messaging'] ?? []) as $event) {
                $connection = $this->messages->connectionFor($object, (string) ($event['recipient']['id'] ?? ''));

                $handled += $this->within($tenant, $connection, $object,
                    fn () => $this->messages->absorb($event, $connection, $source) ? 1 : 0);
            }

            // A lead-ad form, submitted (MetaLeadForms). Only a Page delivers these.
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                if ($object !== 'page' || ($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }

                $value = (array) ($change['value'] ?? []);
                $connection = $this->messages->connectionFor($object, (string) ($value['page_id'] ?? ''));

                $handled += $this->within($tenant, $connection, $object,
                    fn () => $this->forms->absorb($value, $connection) ? 1 : 0);
            }
        }

        return response()->json(['data' => ['handled' => $handled]], Status::HTTP_OK);
    }

    /**
     * Run one event as the connection's organization; 0 when it is not ours.
     */
    private function within(TenantContext $tenant, ?SocialConnection $connection, string $object, Closure $work): int
    {
        if ($connection === null) {
            return 0;
        }

        $organization = Organization::query()
            ->withoutGlobalScopes()
            ->find($connection->organization_id);

        if ($organization === null) {
            return 0;
        }

        try {
            return $tenant->run($organization, $work);
        } catch (Throwable $e) {
            // Logged and swallowed. One malformed event must not cost
            // the others in the same delivery, and a 500 makes Meta
            // retry the whole batch including the parts that worked.
            Log::warning('A Meta message could not be absorbed.', [
                'object' => $object,
                'connection' => $connection->getKey(),
                'reason' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
