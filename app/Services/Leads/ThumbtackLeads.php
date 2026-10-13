<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\ExplainsRefusal;
use App\Support\Leads\LeadReplyChannel;
use App\Support\Leads\LeadThread;
use App\Support\Social\ThumbtackNegotiations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * A Thumbtack enquiry, turned into a Lead row.
 *
 * ── Push, not pull, and that is forced on us ─────────────────────
 *
 * LocalServicesLeads next door SYNCS: it asks Google for everything since a
 * window and reconciles. Nothing here can work that way, because Thumbtack's
 * supply API has no endpoint that lists negotiations — only
 * `GET /v4/negotiations/{id}`. An id arrives by webhook or it does not arrive.
 *
 * Three consequences worth stating once:
 *
 *   NOTHING BACKFILLS. Enquiries from before the webhook was registered are
 *   unreachable forever. There is no catch-up run to write later.
 *
 *   A DROPPED CALLBACK IS A LOST LEAD. With Google a missed page is picked up
 *   by the next sweep. Here the only repair is Thumbtack's own retry.
 *
 *   THE ENTRY POINT IS A PUBLIC ENDPOINT. Everything below is reached from an
 *   unauthenticated route, so it is written to be safe when handed nonsense:
 *   ids that do not resolve, payloads missing halves, the same event twice.
 *
 * ── Idempotent by construction ───────────────────────────────────
 *
 * Thumbtack retries on any non-2xx and can deliver the same event more than
 * once. Leads are keyed on (organization, source, remote_id) and messages on
 * the source's own id through LeadThread, so a replay refreshes rows instead of
 * duplicating them. That is what lets the controller answer 200 to work it has
 * already done, rather than trying to remember what it has seen.
 */
class ThumbtackLeads implements ExplainsRefusal, LeadReplyChannel
{
    public function source(): string
    {
        return Lead::SOURCE_THUMBTACK;
    }

    /**
     * YES, since 2026-09-24 — argued up exactly the way this used to say it
     * should be.
     *
     * It was a choice rather than a filing: nothing EzBizi has told Thumbtack
     * forbids an automatic reply, and the channel arrived false because nobody
     * had asked. Jose asked: *"specifically for the reply to the lead, it
     * should be for any lead, i don't know why you set a restriction on it."*
     *
     * Google is the one that stays false, and for a different reason — a
     * written filing, not a preference. See LocalServicesLeads.
     */
    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    /**
     * A negotiation IS a message thread, so unlike Google there is no
     * call/message split to respect: what stops a reply is the account being
     * gone, or there being nobody at the other end.
     */
    public function canReply(Lead $lead): bool
    {
        return $lead->connection_id !== null
            && ! $lead->is_test
            && trim((string) $lead->remote_id) !== '';
    }

    public function whyNot(Lead $lead): string
    {
        if ($lead->is_test) {
            // Thumbtack's own words for the specimen: its ids "will not refer
            // to any real entities". A reply would 404 against nobody, and the
            // screen should say that rather than relay their HTTP status.
            return 'This is a test enquiry from Thumbtack, so there is nobody to reply to.';
        }

        return 'The Thumbtack account this enquiry came through is no longer connected.';
    }

    /**
     * Say it on the negotiation, and file what we said.
     *
     * The row is written from OUR text rather than from their echo: the reply
     * has been accepted by the time this returns, and a thread that draws
     * nothing because the response was shaped differently reads as a reply
     * that never sent. `messageID` is taken when it is there, because it is
     * the id the next MessageCreatedV4 carries and it is what stops the same
     * sentence arriving twice.
     */
    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        $connection = $lead->connection;

        if ($connection === null) {
            throw new RuntimeException(
                'The account this enquiry came through is no longer connected, so a reply cannot be sent.',
            );
        }

        $sent = ThumbtackNegotiations::for($connection)->reply((string) $lead->remote_id, $text);

        $remoteId = trim((string) ($sent['messageID'] ?? '')) ?: ('sent-'.now()->format('YmdHisv'));

        $row = LeadConversation::create([
            'organization_id' => $lead->organization_id,
            'lead_id'         => $lead->getKey(),
            'remote_id'       => $remoteId,
            'channel'         => LeadConversation::CHANNEL_MESSAGE,
            'participant'     => LeadConversation::FROM_ADVERTISER,
            'occurred_at'     => self::when($sent['sentAt'] ?? null) ?? now(),
            'text'            => $text,
            // SOFTWARE WROTE THIS, and the row has to say so: an unmarked reply
            // reads as a person having answered and takes the enquiry off the
            // Unreplied list, which is the list somebody works from.
            'is_automatic'    => $automatic,
        ]);

        // The list's preview line is a cache of the newest row; without this
        // the reply is in the thread and the list still shows the customer's
        // last message as the latest thing said.
        $this->threads->refresh($lead);

        return $row;
    }

    public function __construct(
        private readonly EventEmitter $events,
        private readonly LeadThread $threads,
    ) {}

    /**
     * File one negotiation, with everything said on it so far.
     *
     * @param  array<string, mixed>  $carried  the event's own `data` block, when
     *                                         the caller has one
     * @return array{lead: Lead, created: bool, messages: int}
     */
    public function absorb(SocialConnection $connection, string $negotiationId, array $carried = []): array
    {
        $negotiationId = trim($negotiationId);

        if ($negotiationId === '') {
            throw new RuntimeException('Thumbtack sent an event with no negotiation id.');
        }

        /*
         * THE EVENT ALREADY CONTAINS THE ENQUIRY. Use it, and only call them
         * when it does not.
         *
         * NegotiationCreatedV4 carries the whole negotiation in `data` —
         * negotiationID, createdAt, the customer's name and phone, the business
         * and the requested category. Fetching it again by id was a round trip
         * for something already in our hands.
         *
         * It was also the reason nothing could ever be verified. Thumbtack's
         * own words for the test-negotiation endpoint: "All information in the
         * test negotiation payload will be synthetic/dummy data ... IDs in the
         * payload will be randomly generated and will not refer to any real
         * entities in the Thumbtack system." So a specimen event can never be
         * fetched back — the fetch 404s by design, and every attempt to prove
         * this integration end to end died there rather than on anything real.
         *
         * And in production it is a lost lead waiting to happen: their API has
         * no way to list negotiations, so an enquiry whose fetch fails once is
         * gone for good. The payload cannot fail.
         *
         * The fetch stays as the fallback for an event that carries no detail —
         * MessageCreatedV4 names its negotiation without describing it — where
         * a round trip is the only way to learn who is asking.
         */
        $raw = $this->looksComplete($carried)
            ? $carried
            : ThumbtackNegotiations::for($connection)->find($negotiationId);

        $result = $this->store($connection, $negotiationId, $raw);

        // Outside the transaction, and only when the row is new. An automation
        // firing inside the write would run against a row nothing has committed
        // yet; `emitAfterCommit` exists for that reason.
        if ($result['created']) {
            $this->events->emitAfterCommit('lead.received', $result['lead'], [
                'after' => $result['lead']->only([
                    'source', 'contact_name', 'phone', 'category', 'received_at',
                ]) + ['kind' => $result['lead']->kind()],
            ]);
        }

        /*
         * The thread is worth having and never worth losing the lead over.
         *
         * By this point the enquiry is COMMITTED — who is asking, their number,
         * what they want. Reading the conversation is a second call that can
         * fail on its own: a synthetic id from a specimen (their test payload's
         * ids "will not refer to any real entities"), a thread not readable
         * yet, a timeout. Letting that throw would roll the caller into a 500,
         * Thumbtack would retry, and on the retry the lead is already stored
         * and the same fetch fails again — a permanent 500 on a lead we hold.
         *
         * So it is reported and swallowed. The lead stands with no messages
         * rather than the enquiry being lost to a failure in its footnotes.
         */
        $messages = 0;

        try {
            $messages = $this->messages($connection, $result['lead'], $negotiationId);
        } catch (Throwable $e) {
            report($e);
        }

        return [...$result, 'messages' => $messages];
    }

    /**
     * Re-attach this business's enquiries when it is connected again.
     *
     * Disconnecting nulls `connection_id` and the leads drop out of Leads.
     * Google's come back through LocalServicesLeads::reclaim() on the next
     * sync; Thumbtack cannot list negotiations, so until 2026-09-30 an orphan
     * stayed hidden until a webhook happened to name it again (three did, that
     * morning). The business id on the lead is the exact match.
     *
     * @return int how many were taken back
     */
    public function reclaim(SocialConnection $connection): int
    {
        $businessId = (string) ($connection->remote_id ?? '');

        if ($businessId === '') {
            return 0;
        }

        return Lead::query()->withoutGlobalScopes()
            ->where('organization_id', $connection->organization_id)
            ->where('source', Lead::SOURCE_THUMBTACK)
            ->where('remote_customer_id', $businessId)
            ->whereNull('connection_id')
            ->update(['connection_id' => $connection->getKey()]);
    }

    /**
     * The thread on a negotiation we already hold.
     *
     * Called for its own event too: a `MessageCreatedV4` for an enquiry that
     * arrived before the connection existed would otherwise have nowhere to go,
     * so the caller absorbs the negotiation first and this fills the thread.
     *
     * @return int  how many rows were new
     */
    public function messages(SocialConnection $connection, Lead $lead, string $negotiationId): int
    {
        $new = 0;

        foreach (ThumbtackNegotiations::for($connection)->messages($negotiationId) as $message) {
            $remoteId = trim((string) ($message['messageID'] ?? ''));

            if ($remoteId === '') {
                continue;
            }

            $attachments = [];

            foreach ((array) ($message['attachments'] ?? []) as $attachment) {
                $url = trim((string) ($attachment['url'] ?? ''));

                if ($url !== '') {
                    $attachments[] = $url;
                }
            }

            $stored = $this->threads->store($lead, $remoteId, [
                'channel' => LeadConversation::CHANNEL_MESSAGE,
                /*
                 * Their vocabulary is Business/Customer; ours is
                 * ADVERTISER/CONSUMER. Getting this backwards would make the
                 * business's own replies emit `lead.contact_received` and alert
                 * somebody that the customer had written, when it was them.
                 */
                'participant' => ($message['from'] ?? null) === 'Customer'
                    ? LeadConversation::FROM_CONSUMER
                    : LeadConversation::FROM_ADVERTISER,
                'occurred_at'  => self::when($message['sentAt'] ?? null),
                'text'         => trim((string) ($message['text'] ?? '')) ?: null,
                'attachments'  => $attachments !== [] ? $attachments : null,
            ]);

            $new += $stored ? 1 : 0;
        }

        // The list's preview line is a cache rebuilt from the thread, never a
        // record. Skipping this leaves a lead showing an older message than the
        // one that just arrived.
        $this->threads->refresh($lead);

        return $new;
    }

    /**
     * Enough of a negotiation to file without asking them again.
     *
     * The customer is the test: a lead with no name and no phone is a row
     * nobody can act on, and that is the one case worth spending a round trip
     * to fix. A MessageCreatedV4 body names its negotiation and describes
     * nothing, so it falls through to the fetch and is unaffected.
     *
     * @param  array<string, mixed>  $carried
     */
    private function looksComplete(array $carried): bool
    {
        return isset($carried['negotiationID'])
            && is_array($carried['customer'] ?? null)
            && trim((string) ($carried['customer']['firstName'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{lead: Lead, created: bool}
     */
    private function store(SocialConnection $connection, string $remoteId, array $raw): array
    {
        $customer = (array) ($raw['customer'] ?? []);
        $request  = (array) ($raw['request'] ?? []);
        $category = (array) ($request['category'] ?? []);

        $name = trim(implode(' ', array_filter([
            trim((string) ($customer['firstName'] ?? '')),
            trim((string) ($customer['lastName'] ?? '')),
        ])));

        $phone = Lead::e164($customer['phone'] ?? null);

        return DB::transaction(function () use ($connection, $remoteId, $raw, $name, $phone, $category) {
            $lead = Lead::query()
                ->where('organization_id', $connection->organization_id)
                ->where('source', Lead::SOURCE_THUMBTACK)
                ->where('remote_id', $remoteId)
                ->first();

            $created = $lead === null;

            $fromSource = [
                'contact_name' => $name !== '' ? $name : null,
                'phone'        => $phone,
                'phone_digits' => Lead::digits($phone),
                'category'     => trim((string) ($category['name'] ?? '')) ?: null,
                'received_at'  => self::when($raw['createdAt'] ?? null),
                'synced_at'    => now(),
            ];

            // What the lead cost, when the payload says (a fetched negotiation
            // does; the webhook's may not, and ezbizi:thumbtack-lead-costs
            // fills it in). Never blanked by a payload that omits it.
            $fromSource += array_filter(self::cost($raw), fn ($v) => $v !== null);

            if ($created) {
                $lead = new Lead([
                    'organization_id' => $connection->organization_id,
                    'company_id'      => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null,
                    // Placement first, then scope — the same two-column split
                    // LocalServicesLeads explains: which franchise OWNS these
                    // enquiries is a different fact from who can SEE the
                    // connection.
                    'franchise_id' => $connection->leads_franchise_id
                        ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                            ? $connection->scope_id
                            : null),
                    'connection_id' => $connection->getKey(),
                    'remote_customer_id' => $connection->remote_id,
                    'source'        => Lead::SOURCE_THUMBTACK,
                    'remote_id'     => $remoteId,
                    'status'        => Lead::STATUS_NEW,
                    ...$fromSource,
                ]);
            } else {
                // `status` is deliberately absent from $fromSource. A replayed
                // webhook must not drag a lead somebody archived back to NEW.
                $lead->fill($fromSource);

                /*
                 * AN ORPHAN IS ADOPTED BY THE ACCOUNT THAT ANSWERS FOR IT.
                 *
                 * Disconnecting deletes the connection row and the lead's
                 * `connection_id` goes null with it, which is silent and
                 * permanent: no token to fetch the thread, no token to reply,
                 * and the thread says the account is no longer connected. On
                 * 2026-09-10 Jose disconnected Thumbtack and connected it
                 * again under A-1 Chimney — the right thing to do, and it left
                 * a real customer's enquiry belonging to nothing.
                 *
                 * Only when the lead has NO connection. A lead already
                 * attached is left alone: two businesses can appear on one
                 * negotiation, and the account that first filed it is the one
                 * whose token has worked.
                 *
                 * Placement rides along, because a lead with no company is a
                 * lead that shows in every view (LeadsController::index) — so
                 * an orphan is not just unrepliable, it is also filed as
                 * nobody's.
                 */
                $lead->remote_customer_id ??= $connection->remote_id;

                if ($lead->connection_id === null) {
                    $lead->connection_id = $connection->getKey();
                    $lead->company_id ??= $connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null;
                    $lead->franchise_id ??= $connection->leads_franchise_id
                        ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                            ? $connection->scope_id
                            : null);
                }
            }

            $lead->save();

            return ['lead' => $lead, 'created' => $created];
        });
    }

    /**
     * "$14.90" and "Charged" as cents and the charge_status Google's leads use.
     *
     * And where the work is: the request's state and ZIP.
     *
     * @return array{lead_cost_minor: ?int, lead_cost_currency: ?string, charge_status: ?string, service_state: ?string, postal_code: ?string}
     */
    public static function cost(array $raw): array
    {
        $price = (string) ($raw['leadPrice'] ?? '');
        $state = strtolower(trim((string) ($raw['chargeState'] ?? '')));
        $where = (array) (((array) ($raw['request'] ?? []))['location'] ?? []);
        $region = strtoupper(trim((string) ($where['state'] ?? '')));
        $zip = trim((string) ($where['zipCode'] ?? ''));

        return [
            'lead_cost_minor' => preg_match('/^\s*\$?\s*([\d,]+)(?:\.(\d{1,2}))?\s*$/', $price, $m) === 1
                ? (int) str_replace(',', '', $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0')
                : null,
            'lead_cost_currency' => $price !== '' && str_contains($price, '$') ? 'USD' : null,
            'charge_status' => match (true) {
                $state === '' => null,
                str_contains($state, 'refund') || str_contains($state, 'credit') => 'CREDITED',
                $state === 'charged' => 'CHARGED',
                default => strtoupper(str_replace(' ', '_', $state)),
            },
            'service_state' => preg_match('/^[A-Z]{2}$/', $region) === 1 ? $region : null,
            'postal_code' => preg_match('/^\d{5}$/', $zip) === 1 ? $zip : null,
        ];
    }

    /**
     * Their timestamps are ISO-8601 with an offset, so they carry their own
     * zone and need no account clock — unlike Google's, which do not and cost
     * a day of wrong times to find out.
     */
    private static function when(mixed $value): Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return now();
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A timestamp we cannot read is not a reason to lose the lead.
            return now();
        }
    }
}
