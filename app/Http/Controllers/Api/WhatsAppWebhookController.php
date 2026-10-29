<?php

namespace App\Http\Controllers\Api;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\Organization;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppNumber;
use App\Support\Leads\LeadThread;
use App\Support\Social\WhatsAppCloud;
use App\Support\Social\WhatsAppGroups;
use App\Support\Social\WhatsAppPeriodReports;
use App\Support\Social\WhatsAppStaffBot;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
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
    public function __construct(
        private readonly WhatsAppStaffBot $bot,
        private readonly WhatsAppGroups $groups,
        private readonly WhatsAppPeriodReports $periodReports,
    ) {}

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
            // A tapped menu row arrives as `interactive`, carrying the row's id
            // and title. The id is what the bot acts on; the title is what the
            // person saw, so it is what the history records as their words.
            $picked = $message['interactive']['list_reply']
                ?? $message['interactive']['button_reply']
                ?? null;

            $row = WhatsAppMessage::updateOrCreate(
                ['wamid' => $wamid],
                [
                    'organization_id' => $organizationId,
                    'phone_number_id' => $phoneNumberId,
                    'direction' => WhatsAppMessage::DIRECTION_IN,
                    'counterparty' => (string) ($message['from'] ?? ''),
                    'status' => WhatsAppMessage::STATUS_RECEIVED,
                    'kind' => (string) ($message['type'] ?? 'text'),
                    // Only text and menu taps are read. An image or a location
                    // arrives as a row with a null body and its real `kind`,
                    // which is honest - better than an empty string that reads
                    // as an empty message somebody sent.
                    'body' => $message['text']['body'] ?? ($picked['title'] ?? ($message['image']['caption'] ?? $message['document']['caption'] ?? null)),
                    // An image or document's caption is its words; its id is how it is fetched.
                    'media_id' => $message['image']['id'] ?? $message['document']['id'] ?? null,
                ],
            );

            $handled++;

            /*
             * ONLY A MESSAGE WE HAD NOT SEEN BEFORE IS ANSWERED.
             *
             * Meta retries any delivery it did not get a 200 for, carrying the
             * same wamid. updateOrCreate makes that retry harmless for the
             * record; `wasRecentlyCreated` is what makes it harmless for the
             * REPLY. Without it a slow response here - which a reply that calls
             * Meta makes more likely - earns a retry, and the retry earns the
             * person a second copy of every answer.
             */
            // A report held for them (WhatsAppGroups) goes as soon as they
            // write; a "Show report" tap is answered by that and nothing else.
            $flushed = $row->wasRecentlyCreated ? $this->flush($phoneNumberId, (string) $row->counterparty) : 0;

            if ($flushed > 0 && ($message['type'] ?? null) === 'button') {
                continue;
            }

            // The bookkeeper's APPROVE / REFRESH for the weekly and monthly PDFs.
            if ($row->wasRecentlyCreated && $this->approval($phoneNumberId, $row)) {
                continue;
            }

            // A tenant's own number that does not answer (coexistence) still
            // routes every sender to Leads; only the bot number answers.
            if ($row->wasRecentlyCreated && ($this->bot->handles($phoneNumberId)
                || WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->where('auto_reply', false)->exists())) {
                $this->answer($row, $phoneNumberId, isset($picked['id']) ? (string) $picked['id'] : null);
            }
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

            /*
             * STATUS ONLY MOVES FORWARD, because Meta does not send them in order.
             *
             * Measured 2026-09-24 on a real message to +1 786 776-2666: the row
             * came to rest with `delivered_at` set and `status` = `sent`.
             * `delivered_at` is written only when a `delivered` status is
             * absorbed, so `delivered` arrived and a LATE `sent` then overwrote
             * it. Every reader of `status` - a run log, a report, a list - was
             * told a delivered message had not arrived, while the timestamp
             * beside it said otherwise.
             *
             * So a status replaces the stored one only if it is further along.
             * `failed` ranks above `sent` and below `delivered`: it can end a
             * message that never arrived, and a stray `failed` must not unwind
             * one the person has already opened.
             *
             * The timestamps are stamped regardless of the ladder: a `read`
             * arriving before its own `delivered` still means it was delivered.
             */
            $rank = [
                WhatsAppMessage::STATUS_QUEUED => 0,
                WhatsAppMessage::STATUS_SENT => 1,
                WhatsAppMessage::STATUS_FAILED => 2,
                WhatsAppMessage::STATUS_DELIVERED => 3,
                WhatsAppMessage::STATUS_READ => 4,
            ];

            // A status Meta invents later ranks level with `sent`. Ranked at
            // the top it would be written once and then block every later
            // `delivered` and `read` for ever; ranked below everything it
            // would regress a delivered message. Level with `sent` it can
            // replace `queued`, can be replaced by any real progress, and can
            // do neither of those things.
            $incoming = $rank[$state] ?? 1;
            $current = $rank[$row->status] ?? 1;
            $advances = $incoming > $current;

            $row->forceFill(array_filter([
                'status' => $advances ? $state : null,
                'status_reason' => $advances ? ($status['errors'][0]['title'] ?? null) : null,
                'delivered_at' => in_array($state, [WhatsAppMessage::STATUS_DELIVERED, WhatsAppMessage::STATUS_READ], true)
                    ? ($row->delivered_at ?? now())
                    : null,
                'read_at' => $state === WhatsAppMessage::STATUS_READ ? now() : null,
            ], fn ($v) => $v !== null))->save();

            $handled++;
        }

        return $handled + $this->people($value, $phoneNumberId, $organizationId);
    }

    /**
     * Everything WhatsApp says about a person beyond the number (Jose,
     * 2026-10-29), written AFTER the messages so intake has made the contact:
     *
     *  - `contacts[].profile.name`: the name on their own WhatsApp profile;
     *  - `messages[].referral`: the Click-to-WhatsApp ad or post they came from;
     *  - `state_sync[]` (field smb_app_state_sync, coexistence only): a contact
     *    the business saved in the WhatsApp Business app, added or removed;
     *  - `message_echoes[]` (field smb_message_echoes, coexistence only): what
     *    staff sent from the WhatsApp Business app, so the lead shows it was
     *    answered.
     *
     * A lead with no name gets the saved name, else the profile name. A name
     * staff typed is never replaced.
     *
     * @param  array<string, mixed>  $value
     */
    private function people(array $value, string $phoneNumberId, string $organizationId): int
    {
        $handled = 0;
        $touched = [];
        $contact = fn (string $who) => WhatsAppContact::query()
            ->where('phone_number_id', $phoneNumberId)->where('counterparty', $who)->first();

        foreach ((array) ($value['contacts'] ?? []) as $c) {
            $row = $contact((string) ($c['wa_id'] ?? ''));
            $name = trim((string) ($c['profile']['name'] ?? ''));
            if ($row !== null && $name !== '') {
                $row->forceFill(['profile_name' => mb_substr($name, 0, 255)])->save();
                $touched[$row->getKey()] = $row;
            }
        }

        foreach ((array) ($value['messages'] ?? []) as $m) {
            if (isset($m['referral']) && ($row = $contact((string) ($m['from'] ?? ''))) !== null) {
                $row->forceFill(['referral' => $m['referral']])->save();
            }
        }

        // Saved contacts and echoes exist only for a number that is also on
        // the WhatsApp Business app; nothing else should create contacts.
        $coexists = WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->where('auto_reply', false)->exists();

        foreach ($coexists ? (array) ($value['state_sync'] ?? []) : [] as $sync) {
            $who = preg_replace('/\D+/', '', (string) ($sync['contact']['phone_number'] ?? '')) ?? '';
            if (($sync['type'] ?? null) !== 'contact' || $who === '') {
                continue;
            }
            $name = ($sync['action'] ?? 'add') === 'remove' ? null
                : (trim((string) ($sync['contact']['full_name'] ?? $sync['contact']['first_name'] ?? '')) ?: null);
            $row = WhatsAppContact::firstOrCreate(
                ['phone_number_id' => $phoneNumberId, 'counterparty' => $who],
                ['organization_id' => $organizationId, 'kind' => WhatsAppContact::KIND_UNKNOWN],
            );
            $row->forceFill(['saved_name' => $name !== null ? mb_substr($name, 0, 255) : null])->save();
            $touched[$row->getKey()] = $row;
            $handled++;
        }

        foreach ($coexists ? (array) ($value['message_echoes'] ?? []) : [] as $echo) {
            $row = $contact((string) ($echo['to'] ?? ''));
            $lead = $row?->lead_id !== null ? Lead::query()->find($row->lead_id) : null;
            $text = $echo['text']['body'] ?? null;
            if ($lead === null || ! is_string($text) || $text === '' || ($echo['id'] ?? '') === '') {
                continue;
            }
            app(LeadThread::class)->store($lead, (string) $echo['id'], [
                'channel' => LeadConversation::CHANNEL_MESSAGE,
                'participant' => LeadConversation::FROM_ADVERTISER,
                'occurred_at' => isset($echo['timestamp']) ? Carbon::createFromTimestamp((int) $echo['timestamp']) : now(),
                'text' => $text,
            ]);
            app(LeadThread::class)->refresh($lead);
            $handled++;
        }

        foreach ($touched as $row) {
            self::nameLead($row);
        }

        return $handled;
    }

    /** Fill a nameless lead from its WhatsApp contact. Public for the backfill. */
    public static function nameLead(WhatsAppContact $contact): void
    {
        $lead = $contact->lead_id !== null ? Lead::query()->find($contact->lead_id) : null;
        // Blank, or the profile name we filled earlier: the saved name wins.
        $ours = $lead !== null && ($lead->contact_name === null || $lead->contact_name === $contact->profile_name);
        if ($ours && ($name = $contact->name()) !== null && $name !== $lead->contact_name) {
            $lead->forceFill(['contact_name' => $name])->save();
        }
    }

    /**
     * Let the staff bot reply, without letting a failed reply cost the record.
     *
     * Caught here rather than left to the caller's catch, because that catch
     * would count the whole change as unabsorbed - and the inbound message IS
     * absorbed; only our answer to it failed. The person's words stay in the
     * history either way, and the failure is logged where it can be read.
     */
    private function answer(WhatsAppMessage $inbound, string $phoneNumberId, ?string $choice): void
    {
        $number = WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->first();

        if ($number === null) {
            return;
        }

        try {
            $this->bot->respond($number, $inbound, $choice);
        } catch (Throwable $e) {
            Log::warning('The WhatsApp staff bot could not reply.', [
                'phone_number_id' => $phoneNumberId,
                'inbound' => $inbound->getKey(),
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function approval(string $phoneNumberId, WhatsAppMessage $row): bool
    {
        $number = WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->first();

        try {
            return $number !== null && $this->periodReports->reply($number, (string) $row->counterparty, $row->body);
        } catch (Throwable $e) {
            Log::warning('A WhatsApp report approval could not be handled.', [
                'phone_number_id' => $phoneNumberId,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function flush(string $phoneNumberId, string $counterparty): int
    {
        $number = WhatsAppNumber::query()->where('phone_number_id', $phoneNumberId)->first();

        try {
            return $number === null ? 0 : $this->groups->flushPending($number, $counterparty);
        } catch (Throwable $e) {
            Log::warning('A held WhatsApp report could not be sent.', [
                'phone_number_id' => $phoneNumberId,
                'reason' => $e->getMessage(),
            ]);

            return 0;
        }
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
