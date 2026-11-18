<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Leads\ExplainsRefusal;
use App\Support\Leads\LeadReplyChannel;
use App\Support\Leads\LeadThread;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\GoogleToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fetches a tenant's own Local Services enquiries and writes them down.
 *
 * ── Why the rows are copied rather than read live ────────────
 *
 * Email deliberately stores nothing and reads a mailbox while somebody is
 * looking at it. This is the opposite decision for a reason that is not
 * inconsistency: a lead is acted on over days by several people, gets a status
 * nobody but this organization knows, and has to survive the customer
 * disconnecting the advertising account it came from. None of that is true of
 * a message in an inbox.
 *
 * ── One writer, and it never overwrites a decision ───────────
 *
 * The unique key is (organization, source, remote_id), so a retry, an
 * overlapping run, or a cursor replayed from the beginning updates the same row
 * instead of creating a second copy of one enquiry. On an existing row it
 * refreshes only what the SOURCE owns — Google's own status, the charge state,
 * the contact details as given. `status` is EzBizi's column and is never
 * touched here; a sweep that reconciled the two would silently revert every
 * manual decision the next time it ran.
 */
class LocalServicesLeads implements ExplainsRefusal, LeadReplyChannel
{
    /**
     * Said in the refusal when Google names the limit as the account's own.
     *
     * Read by SyncLeads to decide whether a 429 is evidence about the whole
     * developer token or about one customer. Matched as text rather than given
     * an exception class of its own because there is exactly one thrower and
     * one reader; give it a class the day a third appears.
     */
    public const ACCOUNT_SCOPED = "This account's own rate limit.";

    private const TIMEOUT = 20;

    /**
     * How far back a sync looks when the tenant has no leads yet.
     *
     * Not "everything". A first sync that walked years of history would spend a
     * new customer's entire daily quota before they had seen a single row, and
     * the enquiries that matter on day one are the recent ones. Later syncs
     * start from the newest lead already held.
     */
    private const FIRST_RUN_DAYS = 30;

    /**
     * A ceiling on the paging loop, so a token Google keeps re-issuing cannot
     * spin a request forever spending the day's quota. googleAds:search pages
     * at ten thousand rows, so this is two million leads - a backstop, not a
     * limit anybody reaches.
     */
    private const MAX_PAGES = 200;

    public function __construct(
        private readonly GoogleAdsOAuth $oauth,
        private readonly EventEmitter $events,
        private readonly LeadThread $threads,
    ) {}

    /**
     * Pull everything new for one connected Google Ads account.
     *
     * @return array{fetched: int, created: int, updated: int}
     */
    /**
     * @param  int|null  $days  Re-read this many days back instead of the
     *                          incremental window. Existing rows are refreshed,
     *                          which is how a stored value that was wrong for
     *                          every row - the clock, 2026-09-08 - gets put
     *                          right without a migration guessing at it.
     */
    public function sync(SocialConnection $connection, ?int $days = null, bool $withConversations = true): array
    {
        $token = GoogleToken::forConnection($connection, 'google_ads');

        // Before deciding what to ask Google for, take back what is already
        // here. This has to run first: `since()` reads its watermark from the
        // leads this connection owns, and a reconnected account owns none until
        // the reclaim runs - so without it every reconnect re-reads from
        // FIRST_RUN_DAYS and pays for leads it already holds.
        $reclaimed = $this->reclaim($connection);

        $since = $days !== null ? now()->subDays($days) : $this->since($connection);

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $pageToken = null;
        $pages = 0;

        do {
            $page = $this->page($connection, $token, $since, $pageToken);

            foreach ($page['leads'] as $raw) {
                $fetched++;

                if ($this->absorb($connection, $raw)['created']) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            $pageToken = $page['next'];
        } while ($pageToken !== null && ++$pages < self::MAX_PAGES);

        $conversations = $withConversations
            ? $this->syncConversations($connection, $token, $days)
            : ['fetched' => 0, 'created' => 0, 'leads_recovered' => 0];

        $connection->forceFill(['last_reached_at' => now()])->save();

        return [
            'fetched' => $fetched,
            'created' => $created,
            'updated' => $updated,
            'reclaimed' => $reclaimed,
            'conversations' => $conversations,
        ];
    }

    /**
     * Re-attach enquiries this account already produced.
     *
     * Disconnecting sets `leads.connection_id` to NULL through the foreign key
     * rather than deleting the enquiry - correct, because these are the
     * business's own customers and some are already jobs. Reconnecting then
     * creates a NEW connection row, and nothing carried the leads across.
     *
     * Until `remote_customer_id` existed the only way back was to make Google
     * send each lead again, which costs quota, is bounded by a date window, and
     * leaves everything outside that window detached permanently. On
     * 2026-09-14 that played out three times in one afternoon: a disconnect
     * orphaned 41,006 leads, two forced re-reads reclaimed 5,430, and a second
     * reconnect eight minutes later dropped them to 2,547 because the
     * connection rows had been replaced underneath.
     *
     * Google's customer id is the fact that survives all of it. Matching on it
     * is exact rather than inferred - which matters, because the obvious
     * alternative was matching by company, and A-1 Chimney has 37 accounts
     * under one company. A wrong match sends somebody's reply through a
     * stranger's account.
     *
     * Scoped to the organization as well as the customer id. The id is Google's
     * and is unique to them, but a query that trusts one tenant's column to be
     * unique across all tenants is a scope hole waiting for the day it is not.
     *
     * @return int how many were taken back
     */
    private function reclaim(SocialConnection $connection): int
    {
        $customerId = (string) ($connection->remote_id ?? '');

        if ($customerId === '') {
            return 0;
        }

        return Lead::query()
            ->where('organization_id', $connection->organization_id)
            ->where('source', Lead::SOURCE_LOCAL_SERVICES)
            ->where('remote_customer_id', $customerId)
            ->whereNull('connection_id')
            ->update(['connection_id' => $connection->getKey()]);
    }

    /**
     * The conversations alone, for a lead read that has already happened.
     *
     * Exists so the scheduler can put every account's LEAD read ahead of any
     * account's conversation read. Both halves cost the same quota and they
     * are not worth the same: a lead is somebody the business has not spoken
     * to yet, and the messages on it are detail that the next tick will fetch
     * just as well.
     */
    public function conversationsFor(SocialConnection $connection, ?int $days = null): array
    {
        $token = GoogleToken::forConnection($connection, 'google_ads');

        $result = $this->syncConversations($connection, $token, $days);

        $connection->forceFill(['last_reached_at' => now()])->save();

        return $result;
    }

    /**
     * Store one lead and, if it is new, say so.
     *
     * Outside the transaction on purpose: emitting inside one means an
     * automation running against a row the emitter has not committed yet.
     * `emitAfterCommit` exists for the same reason, and `created` is what
     * tells us whether to raise anything.
     *
     * @param  array<string, mixed>  $raw
     * @return array{lead: Lead, created: bool}
     */
    private function absorb(SocialConnection $connection, array $raw): array
    {
        $result = $this->store($connection, $raw);

        if ($result['created']) {
            $this->events->emitAfterCommit('lead.received', $result['lead'], [
                'after' => $result['lead']->only([
                    'source', 'contact_name', 'phone', 'category', 'received_at',
                ]) + ['kind' => $result['lead']->kind()],
            ]);
        }

        return $result;
    }

    /**
     * What was said: every call and message since the last one we hold.
     *
     * A lead row is a header. The substance - the enquirer's message, the
     * call's length and recording, the business's replies - is a second
     * resource, `local_services_lead_conversation`, that this platform never
     * read. Rodrigo's a1leads pulled it from the start, which is why its list
     * showed "LS Text" and the last message under each name while ours showed
     * "unnamed enquiry" and nothing. Measured live on 2026-09-08 against
     * 4941850746 ("A1 Chimney - Cleveland"): eleven rows for three days, with
     * durations and recording URLs.
     *
     * Only for accounts that have produced a lead. Measured on 2026-09-08
     * across A-1 Chimney's twenty-two accounts: thirteen hold leads (23,875
     * lifetime between them) and nine hold none, and at one search per
     * account per five-minute tick this second read would have spent 2,592
     * of the day's 15,000 operations asking those nine about conversations
     * on leads they do not have. With it, a tick is 22 lead reads plus 13
     * conversation reads: 10,080 operations a day, under the quota, with the
     * remainder left for manual Fetch clicks and connects.
     *
     * The window is on the conversation's own time, not the lead's. A message
     * this week on a lead from two months ago is inside it while the lead is
     * outside ours; those leads are fetched by id afterwards, so an enquiry
     * that is still alive is never dropped for being old.
     *
     * @return array{fetched: int, created: int, leads_recovered: int}
     */
    private function syncConversations(SocialConnection $connection, string $token, ?int $days = null): array
    {
        $none = ['fetched' => 0, 'created' => 0, 'leads_recovered' => 0];

        if (! Lead::query()->where('connection_id', $connection->getKey())->exists()) {
            return $none;
        }

        $since = $days !== null ? now()->subDays($days) : $this->conversationsSince($connection);
        $fetched = 0;
        $created = 0;
        $pending = [];   // remote lead id => conversations whose lead we do not hold yet
        $touched = [];   // lead id => Lead, for the preview refresh at the end
        $pageToken = null;
        $pages = 0;

        $query = sprintf(
            'SELECT local_services_lead.id, local_services_lead_conversation.id, '
            .'local_services_lead_conversation.lead, '
            .'local_services_lead_conversation.conversation_channel, '
            .'local_services_lead_conversation.participant_type, '
            .'local_services_lead_conversation.event_date_time, '
            .'local_services_lead_conversation.phone_call_details.call_duration_millis, '
            .'local_services_lead_conversation.phone_call_details.call_recording_url, '
            .'local_services_lead_conversation.message_details.text, '
            .'local_services_lead_conversation.message_details.attachment_urls, '
            .'customer.time_zone '
            .'FROM local_services_lead_conversation '
            ."WHERE local_services_lead_conversation.event_date_time >= '%s'",
            $since->utc()->format('Y-m-d H:i:s'),
        );

        do {
            $found = $this->search($connection, $token, $query, $pageToken, 'conversation');

            foreach ($found['results'] as $row) {
                $raw = $this->conversationFromRow(is_array($row) ? $row : []);

                if ($raw === null) {
                    continue;
                }

                $fetched++;

                $lead = Lead::query()
                    ->where('organization_id', $connection->organization_id)
                    ->where('source', Lead::SOURCE_LOCAL_SERVICES)
                    ->where('remote_id', $raw['leadId'])
                    ->first();

                if ($lead === null) {
                    $pending[$raw['leadId']][] = $raw;

                    continue;
                }

                if ($this->storeConversation($lead, $raw)) {
                    $created++;
                }

                $touched[$lead->getKey()] = $lead;
            }

            $pageToken = $found['next'];
        } while ($pageToken !== null && ++$pages < self::MAX_PAGES);

        $recovered = 0;

        foreach (array_chunk(array_keys($pending), 100) as $ids) {
            $ids = array_filter(array_map(fn ($id) => preg_replace('/\D/', '', (string) $id), $ids));

            if ($ids === []) {
                continue;
            }

            $found = $this->search(
                $connection,
                $token,
                self::LEAD_FIELDS.' WHERE local_services_lead.id IN ('.implode(', ', $ids).')',
                null,
                'lead',
            );

            foreach ($found['results'] as $row) {
                $rawLead = $this->fromSearchRow(is_array($row) ? $row : []);

                if ($rawLead === null) {
                    continue;
                }

                $result = $this->absorb($connection, $rawLead);

                if ($result['created']) {
                    $recovered++;
                }

                foreach ($pending[$rawLead['leadId']] ?? [] as $raw) {
                    if ($this->storeConversation($result['lead'], $raw)) {
                        $created++;
                    }
                }

                $touched[$result['lead']->getKey()] = $result['lead'];
            }
        }

        foreach ($touched as $lead) {
            $this->refreshLastContact($lead);
        }

        return ['fetched' => $fetched, 'created' => $created, 'leads_recovered' => $recovered];
    }

    /**
     * Send a reply to the enquirer, through Google, into the chat they wrote in.
     *
     * The one write this module makes to Google, and it is a person's message
     * in the customer's name: it goes because somebody pressed Send, never
     * from a rule. `localServices:appendLeadConversation` is the call a1leads
     * has used since it started; Google accepts it for MESSAGE leads only,
     * so a call lead is refused here before anything is sent.
     *
     * No automatic retry. An ambiguous answer - a timeout after Google has
     * already accepted the message - must never become the same sentence
     * arriving twice at a stranger's phone. The screen says it failed and the
     * person decides.
     *
     * @throws RuntimeException with a sentence for the screen
     */
    public function source(): string
    {
        return Lead::SOURCE_LOCAL_SERVICES;
    }

    /**
     * Yes, since 2026-09-29. It was no because EzBizi had told Google in
     * writing that its only write is a reply a member of staff typed. Jose, 2026-09-29, deciding it: "flip the block now, there's no need to tell
     * google about it" - so an automation (the auto reply that replaces Rodrigo's
     * bot) may answer a Google text enquiry.
     * The reply is recorded as automatic, so the thread shows the bot mark and
     * the lead stays on the unreplied list. See
     * LeadReplyChannel::allowsUnattendedReply().
     */
    public function allowsUnattendedReply(): bool
    {
        return true;
    }

    /** Google accepts replies for text enquiries only, while the account is connected. */
    public function canReply(Lead $lead): bool
    {
        return $lead->lead_type === 'MESSAGE' && $lead->connection_id !== null;
    }

    /**
     * The sentence the thread used to hard-code for every source.
     *
     * It lives here now because it is TRUE here: Google's
     * appendLeadConversation takes text only against a message enquiry, and a
     * call is answered by ringing back.
     */
    public function whyNot(Lead $lead): string
    {
        if ($lead->connection_id === null) {
            return 'The Google Ads account this enquiry came through is no longer connected.';
        }

        return 'Google only accepts replies to text enquiries. This one came in as a call, '
            .'so it is answered by calling back.';
    }

    public function send(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        return $this->reply($lead, $text, $automatic);
    }

    /** @param  bool  $automatic  a rule sent it, not a person - the row says so */
    public function reply(Lead $lead, string $text, bool $automatic = false): LeadConversation
    {
        $connection = $lead->connection;

        if ($connection === null) {
            throw new RuntimeException(
                'The account this enquiry came through is no longer connected, so a reply cannot be sent.',
            );
        }

        if ($lead->lead_type !== 'MESSAGE') {
            throw new RuntimeException(
                'Google only accepts replies to text enquiries. This one came in as a call.',
            );
        }

        $token = GoogleToken::forConnection($connection, 'google_ads');
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $connection->login_customer_id ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    GoogleAdsOAuth::endpoint()."/customers/{$customerId}/localServices:appendLeadConversation",
                    ['conversations' => [[
                        'localServicesLead' => "customers/{$customerId}/localServicesLeads/{$lead->remote_id}",
                        'text' => $text,
                    ]]],
                );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer. The reply was not sent.');
        }

        if ($response->failed()) {
            $error = (array) $response->json('error', []);
            $detail = is_string($error['message'] ?? null) ? (string) $error['message'] : '';

            Log::warning('Google refused a Local Services reply.', [
                'http_status' => $response->status(),
                'google_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
                'detail' => mb_substr($detail, 0, 300),
            ]);

            throw new RuntimeException(sprintf(
                'Google refused the reply (HTTP %d). It was not sent.',
                $response->status(),
            ));
        }

        // "customers/123/localServicesLeadConversations/456": the id is the
        // last segment, and it is the same id the next sync will bring back,
        // which is what stops the sync storing this message a second time.
        $name = (string) ($response->json('responses.0.localServicesLeadConversation') ?? '');
        $remoteId = trim((string) substr(strrchr($name, '/') ?: '', 1));
        if ($remoteId === '') {
            // Sent, but Google named no conversation: a placeholder the next
            // sync swaps for Google's id (storeConversation). What Google did
            // answer, to learn why (desk, 2026-11-18: 40 in 7 days).
            Log::warning('Google accepted a Local Services reply without naming it.', [
                'lead_id' => $lead->getKey(),
                'answer' => mb_substr((string) $response->body(), 0, 500),
            ]);
            // Measured 2026-11-18: all 69 such sends repeated words Google
            // already held on the lead. Its copy is the message: hand that
            // back, so the sender is named on it, rather than a second row.
            $copy = self::sameWords($lead, $text, now());
            if ($copy !== null && ! str_starts_with((string) $copy->remote_id, self::PLACEHOLDER)) {
                $this->refreshLastContact($lead);

                return $copy;
            }
            $remoteId = self::PLACEHOLDER.now()->format('YmdHisv');
        }

        $row = LeadConversation::create([
            'organization_id' => $lead->organization_id,
            'lead_id' => $lead->getKey(),
            'remote_id' => $remoteId,
            'channel' => LeadConversation::CHANNEL_MESSAGE,
            'participant' => LeadConversation::FROM_ADVERTISER,
            'occurred_at' => now(),
            'text' => $text,
            'is_automatic' => $automatic,
        ]);

        $this->refreshLastContact($lead);

        return $row;
    }

    /**
     * Ask Google to credit a charged lead (Jose, 2026-10-13, Rodrigo's LSA
     * triage): lead feedback, VERY_DISSATISFIED with Google's own reason.
     * Google answers with its creditIssuanceDecision (SUCCESS_REACHED_THRESHOLD,
     * FAIL_NOT_ELIGIBLE ...), which is returned as Google wrote it. Once per
     * lead: Google refuses a second feedback.
     *
     * @throws RuntimeException with a sentence for the screen
     */
    public function requestCredit(Lead $lead, string $reason, string $comment = ''): string
    {
        $connection = $lead->connection
            ?? throw new RuntimeException('The account this enquiry came through is no longer connected.');

        $token = GoogleToken::forConnection($connection, 'google_ads');
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                    'login-customer-id' => $connection->login_customer_id ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    GoogleAdsOAuth::endpoint()."/customers/{$customerId}/localServicesLeads/{$lead->remote_id}:provideLeadFeedback",
                    ['surveyAnswer' => 'VERY_DISSATISFIED', 'surveyDissatisfied' => array_filter([
                        'surveyDissatisfiedReason' => $reason,
                        'otherReasonComment' => mb_substr($comment, 0, 1000),
                    ])],
                );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer. The credit was not requested.');
        }

        if ($response->failed()) {
            $detail = (string) ($response->json('error.message') ?? '');
            Log::warning('Google refused a Local Services credit request.', [
                'http_status' => $response->status(),
                'detail' => mb_substr($detail, 0, 300),
            ]);

            throw new RuntimeException(sprintf('Google refused the credit request (HTTP %d)%s', $response->status(),
                $detail !== '' ? ': '.mb_substr($detail, 0, 200) : '.'));
        }

        return (string) ($response->json('creditIssuanceDecision') ?? 'UNSPECIFIED');
    }

    private function conversationsSince(SocialConnection $connection): Carbon
    {
        $newest = LeadConversation::query()
            ->where('organization_id', $connection->organization_id)
            ->whereIn('lead_id', Lead::query()
                ->where('connection_id', $connection->getKey())
                ->select('id'))
            ->max('occurred_at');

        return $newest !== null
            ? Carbon::parse($newest)->subDay()   // one day of overlap; the unique key absorbs it
            : now()->subDays(self::FIRST_RUN_DAYS);
    }

    /**
     * googleAds:search nests both resources under their own camelCase key. The
     * lead id is read from the attributed `local_services_lead` when Google
     * sends it and from the conversation's own `lead` resource name when it
     * does not; a row naming neither cannot be filed and is dropped.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function conversationFromRow(array $row): ?array
    {
        $c = (array) ($row['localServicesLeadConversation'] ?? []);
        $id = trim((string) ($c['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $leadId = trim((string) (((array) ($row['localServicesLead'] ?? []))['id'] ?? ''));

        if ($leadId === '' && is_string($c['lead'] ?? null)) {
            $leadId = trim((string) substr(strrchr($c['lead'], '/') ?: '', 1));
        }

        if ($leadId === '') {
            return null;
        }

        $call = (array) ($c['phoneCallDetails'] ?? []);
        $msg = (array) ($c['messageDetails'] ?? []);

        return [
            'id' => $id,
            'leadId' => $leadId,
            'timeZone' => ((array) ($row['customer'] ?? []))['timeZone'] ?? null,
            'channel' => trim((string) ($c['conversationChannel'] ?? '')) ?: 'UNKNOWN',
            'participant' => trim((string) ($c['participantType'] ?? '')) ?: 'UNKNOWN',
            'occurredAt' => $c['eventDateTime'] ?? null,
            'durationMs' => isset($call['callDurationMillis']) && is_numeric($call['callDurationMillis'])
                ? (int) $call['callDurationMillis']
                : null,
            'recordingUrl' => trim((string) ($call['callRecordingUrl'] ?? '')) ?: null,
            'text' => trim((string) ($msg['text'] ?? '')) ?: null,
            'attachments' => isset($msg['attachmentUrls']) && is_array($msg['attachmentUrls']) && $msg['attachmentUrls'] !== []
                ? array_values(array_map('strval', $msg['attachmentUrls']))
                : null,
        ];
    }

    /**
     * Google's row, in the thread's shape. True when it is new.
     *
     * @param  array<string, mixed>  $raw
     */
    private function storeConversation(Lead $lead, array $raw): bool
    {
        if ($this->adoptOwnReply($lead, $raw)) {
            return false;
        }

        return $this->threads->store($lead, $raw['id'], [
            'channel' => $raw['channel'],
            'participant' => $raw['participant'],
            'occurred_at' => $raw['occurredAt'] !== null
                ? self::whenGoogleSaid((string) $raw['occurredAt'], $raw['timeZone'] ?? null)
                : now(),
            'duration_ms' => $raw['durationMs'],
            'recording_url' => $raw['recordingUrl'],
            'text' => $raw['text'],
            'attachments' => $raw['attachments'],
        ]);
    }

    /** Our own reply's id until Google's arrives (reply() when Google names none). */
    public const PLACEHOLDER = 'sent-';

    /**
     * Google's copy of a reply we sent but could not name: the same words, by
     * us, on this lead, within ten minutes. Our row keeps its sender and takes
     * Google's id, instead of a second "Sent outside EzBizi" row beside it
     * (desk, 2026-11-18: Ara's reply showed twice; 40 in 7 days).
     *
     * @param  array<string, mixed>  $raw
     */
    private function adoptOwnReply(Lead $lead, array $raw): bool
    {
        if ($raw['participant'] !== LeadConversation::FROM_ADVERTISER || $raw['text'] === null
            || LeadConversation::query()->where('organization_id', $lead->organization_id)->where('remote_id', $raw['id'])->exists()) {
            return false;
        }
        $at = $raw['occurredAt'] !== null ? self::whenGoogleSaid((string) $raw['occurredAt'], $raw['timeZone'] ?? null) : now();
        $mine = self::sameWords($lead, (string) $raw['text'], $at, placeholder: true);
        if ($mine === null) {
            return false;
        }
        $mine->forceFill(['remote_id' => $raw['id']])->save();

        return true;
    }

    /**
     * Our side's message on this lead with these words within an hour of $at:
     * a placeholder of ours, or (placeholder: false) any reply.
     */
    private static function sameWords(Lead $lead, string $text, \DateTimeInterface $at, bool $placeholder = false): ?LeadConversation
    {
        $at = Carbon::instance($at);

        return LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->where('participant', LeadConversation::FROM_ADVERTISER)
            ->when($placeholder, fn ($q) => $q->where('remote_id', 'like', self::PLACEHOLDER.'%'))
            ->whereBetween('occurred_at', [$at->copy()->subHour(), $at->copy()->addHour()])
            ->get()
            ->sortBy(fn (LeadConversation $c) => abs($c->occurred_at->getTimestamp() - $at->getTimestamp()))
            ->first(fn (LeadConversation $c) => trim((string) $c->text) === trim($text));
    }

    private function refreshLastContact(Lead $lead): void
    {
        $this->threads->refresh($lead);
    }

    /**
     * Google's timestamps are in the ACCOUNT's time zone, with no offset.
     *
     * `creation_date_time` and `event_date_time` come back as
     * "2026-09-08 13:43:01.118153" and mean 13:43 in `customer.time_zone` -
     * America/New_York for every A-1 Chimney account. Read as UTC, which is
     * what Carbon::parse does with a bare string, every lead and every
     * message was stamped four hours too old. Jose saw it on 2026-09-08: the
     * newest lead showed "4h ago" beside a1leads showing the same person at
     * "45m ago", and "Nothing new" looked like a fetch that had stopped.
     *
     * The zone rides on every row - `customer.time_zone` is attributable
     * from both resources, verified live - so the parse needs no stored
     * setting. A row without one is read as UTC and is wrong the old way,
     * which is the least surprising fallback.
     */
    public static function whenGoogleSaid(string $stamp, ?string $timeZone): Carbon
    {
        return Carbon::parse($stamp, $timeZone ?: 'UTC')->utc();
    }

    /**
     * The newest lead we already hold, or a bounded window on the first run.
     *
     * Deliberately the newest RECEIVED time rather than a stored cursor: a
     * cursor is a second source of truth that can disagree with the rows, and
     * when it does the disagreement is silent. The rows cannot lie about what
     * they contain.
     */
    private function since(SocialConnection $connection): Carbon
    {
        $newest = Lead::query()
            ->where('organization_id', $connection->organization_id)
            ->where('connection_id', $connection->getKey())
            ->max('received_at');

        return $newest !== null
            ? Carbon::parse($newest)->subDay()   // one day of overlap; the unique key absorbs it
            : now()->subDays(self::FIRST_RUN_DAYS);
    }

    /**
     * @return array{leads: array<int, array<string, mixed>>, next: ?string}
     */
    private function page(
        SocialConnection $connection,
        string $token,
        Carbon $since,
        ?string $pageToken,
    ): array {
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        /*
         * GAQL against local_services_lead, not the invented REST method
         * `localServices:searchDetailedLeadReports`.
         *
         * That path is not on googleads.googleapis.com. Production proved it
         * on 2026-09-03: connect stored the Ads account, Fetch returned 502,
         * and nothing was written to the log because the catch below used to
         * throw a status number and swallow Google's body. The Ads API's
         * read for Local Services leads is googleAds:search. Date literals
         * are `Y-m-d H:i:s`, not ISO-8601 — the resource rejects the latter.
         *
         * And no pageSize. googleAds:search refuses one outright:
         *
         *   PAGE_SIZE_NOT_SUPPORTED — "Setting the page size is not supported.
         *   Search Responses will have fixed page size of '10000' rows."
         *
         * Measured against A-1 Chimney's live account on 2026-09-03, checking
         * this fix before it shipped: with pageSize the call is HTTP 400,
         * without it HTTP 200. On the Leads screen a 400 looks exactly like the
         * 404 the invented URL produced, so it would have read as "the fix did
         * not work" rather than as a second, different fault.
         *
         * pageToken stays: the response still pages, it just sizes its own.
         */
        $found = $this->search(
            $connection,
            $token,
            sprintf(
                self::LEAD_FIELDS." WHERE local_services_lead.creation_date_time >= '%s'",
                $since->utc()->format('Y-m-d H:i:s'),
            ),
            $pageToken,
            'lead',
        );

        return [
            'leads' => array_values(array_filter(array_map(
                fn ($row) => $this->fromSearchRow(is_array($row) ? $row : []),
                $found['results'],
            ))),
            'next' => $found['next'],
        ];
    }

    /**
     * Everything Google will hand over on a lead. Shared by the window read
     * and the by-id recovery.
     *
     * Not `note`: measured on 2026-09-08 against the live account, GAQL
     * answers 400 PROHIBITED_FIELD_IN_SELECT_CLAUSE for it - "The following
     * field may not be used in SELECT clause" - and one refused field fails
     * the whole request, so every lead fetch would have failed on deploy.
     * `service_id` and `locale` were verified in the same probe.
     */
    private const LEAD_FIELDS = 'SELECT local_services_lead.id, local_services_lead.lead_type, '
        .'local_services_lead.category_id, local_services_lead.service_id, '
        .'local_services_lead.contact_details, local_services_lead.lead_status, '
        .'local_services_lead.lead_charged, local_services_lead.credit_details.credit_state, '
        .'local_services_lead.creation_date_time, local_services_lead.locale, '
        .'customer.time_zone '
        .'FROM local_services_lead';

    /**
     * One googleAds:search call. `$what` is only for the log and the message.
     *
     * @return array{results: array<int, mixed>, next: ?string}
     */
    private function search(
        SocialConnection $connection,
        string $token,
        string $query,
        ?string $pageToken,
        string $what,
    ): array {
        $customerId = preg_replace('/\D/', '', (string) $connection->remote_id) ?? '';

        $body = array_filter([
            'pageToken' => $pageToken,
            'query' => $query,
        ], fn ($v) => $v !== null);

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),

                    /*
                     * The account being read is its own login customer.
                     *
                     * This was an installation-wide
                     * `services.google_ads.login_customer_id`, and could not be
                     * one: the manager an account sits under is a fact about
                     * that connection, not about this deployment. Setting it to
                     * fix a customer reached through EzBizi's MCC would send
                     * that same id on every other tenant's request, and Google
                     * answers PERMISSION_DENIED for any account that manager
                     * does not manage - one customer fixed, the rest broken,
                     * and nothing on screen saying why.
                     *
                     * listAccessibleCustomers returns accounts the signed-in
                     * user reaches DIRECTLY and each is stored as its own
                     * connection, so the customer id is the right login customer
                     * for every row we hold. Manager-mediated access, if it is
                     * ever needed, belongs in a column on the connection - put
                     * there by the flow that knows which manager was used.
                     */
                    'login-customer-id' => $connection->login_customer_id ?: $customerId,
                ])
                ->timeout(self::TIMEOUT)
                ->post(
                    GoogleAdsOAuth::endpoint()."/customers/{$customerId}/googleAds:search",
                    $body,
                );
        } catch (ConnectionException) {
            throw new RuntimeException("Google did not answer while fetching {$what}s.");
        }

        if ($response->failed()) {
            $error = (array) $response->json('error', []);
            $detail = is_string($error['message'] ?? null) ? (string) $error['message'] : '';

            /*
             * rateScope, because without it a night of 429s cannot be read.
             *
             * 2026-09-16: 2,033 refusals in three hours and nothing in the log
             * said whether the limit was on one account or on the developer
             * token - which is the difference between "click a different
             * account" and "the day is spent". The scope had to be recovered
             * by holding a mutex and having somebody load a screen by hand.
             *
             * Google sends it. It costs one line to keep, and it answers the
             * question at 3am with nobody watching.
             */
            Log::warning("Google refused a Local Services {$what} fetch.", [
                'http_status' => $response->status(),
                'google_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
                'rate_scope' => data_get($error, 'details.0.errors.0.details.quotaErrorDetails.rateScope'),
                'retry_delay' => data_get($error, 'details.0.errors.0.details.quotaErrorDetails.retryDelay'),
                'detail' => mb_substr($detail, 0, 300),
            ]);

            /*
             * The scope travels with the refusal, not just into the log.
             *
             * SyncLeads stops a run after three 429s in a row, on the reasoning
             * that three DIFFERENT accounts refused back to back is a fact
             * about the developer token. That reasoning fails if each of the
             * three is hitting its OWN limit - then stopping starves the
             * forty-eight behind them, every run, silently.
             *
             * Google is the only one who knows which it is, so the sentence
             * carries it. Absent means count it: that keeps the brake working
             * on a response that says nothing, which is the outage this was
             * written during.
             */
            $accountScoped = data_get(
                $error,
                'details.0.errors.0.details.quotaErrorDetails.rateScope'
            ) === 'ACCOUNT';

            throw new RuntimeException(sprintf(
                'Google refused the %s request (HTTP %d).%s',
                $what,
                $response->status(),
                $accountScoped ? ' '.self::ACCOUNT_SCOPED : '',
            ));
        }

        $next = $response->json('nextPageToken');

        return [
            'results' => (array) $response->json('results', []),
            'next' => is_string($next) && $next !== '' ? $next : null,
        ];
    }

    /**
     * googleAds:search nests the resource. store() reads the flat names the
     * old detailed-lead-report payload used, so this is a reshape, not a
     * second writer.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function fromSearchRow(array $row): ?array
    {
        $lead = (array) ($row['localServicesLead'] ?? []);
        $id = trim((string) ($lead['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $contact = (array) ($lead['contactDetails'] ?? []);
        $credit = (array) ($lead['creditDetails'] ?? []);

        return [
            'leadId' => $id,
            'timeZone' => ((array) ($row['customer'] ?? []))['timeZone'] ?? null,
            'leadType' => $lead['leadType'] ?? null,
            'leadCategory' => $lead['categoryId'] ?? null,
            'leadStatus' => $lead['leadStatus'] ?? null,
            /*
             * CHARGED and CREDITED are opposite facts, not two names for one.
             *
             * `lead_charged` means Google billed for the lead.
             * `credit_details.credit_state` = CREDITED means that charge was
             * given back. Reading the first as the second marked every lead the
             * customer paid for as refunded — and charge_status is handed to
             * the client verbatim, so the wrong word reached a screen in the
             * one module whose purpose is matching spend to the work it bought.
             *
             * The credit state wins when Google sends one, because a lead can
             * be charged and later credited and the later fact is the answer.
             */
            'chargeStatus' => $credit['creditState']
                ?? ((isset($lead['leadCharged']) && $lead['leadCharged']) ? 'CHARGED' : null),
            'creationDateTime' => $lead['creationDateTime'] ?? null,
            'service' => $lead['serviceId'] ?? null,
            'locale' => $lead['locale'] ?? null,
            'contactDetails' => [
                'phoneNumber' => $contact['phoneNumber'] ?? null,
                'consumerName' => $contact['consumerName'] ?? null,
                'email' => $contact['email'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{lead: Lead, created: bool}
     */
    private function store(SocialConnection $connection, array $raw): array
    {
        $remoteId = trim((string) ($raw['leadId'] ?? ''));

        if ($remoteId === '') {
            throw new RuntimeException('Google returned a lead with no id.');
        }

        $contact = (array) ($raw['contactDetails'] ?? []);
        $phone = trim((string) ($contact['phoneNumber'] ?? '')) ?: null;

        return DB::transaction(function () use ($connection, $raw, $remoteId, $contact, $phone) {
            $lead = Lead::query()
                ->where('organization_id', $connection->organization_id)
                ->where('source', Lead::SOURCE_LOCAL_SERVICES)
                ->where('remote_id', $remoteId)
                ->first();

            $created = $lead === null;

            $fromSource = [
                'contact_name' => trim((string) ($contact['consumerName'] ?? '')) ?: null,
                'phone' => $phone,
                'phone_digits' => Lead::digits($phone),
                'email' => trim((string) ($contact['email'] ?? '')) ?: null,
                'lead_type' => trim((string) ($raw['leadType'] ?? '')) ?: null,
                'category' => trim((string) ($raw['leadCategory'] ?? '')) ?: null,
                'service' => trim((string) ($raw['service'] ?? '')) ?: null,
                'locale' => trim((string) ($raw['locale'] ?? '')) ?: null,
                'source_status' => trim((string) ($raw['leadStatus'] ?? '')) ?: null,
                'charge_status' => trim((string) ($raw['chargeStatus'] ?? '')) ?: null,
                'received_at' => isset($raw['creationDateTime'])
                    ? self::whenGoogleSaid((string) $raw['creationDateTime'], $raw['timeZone'] ?? null)
                    : now(),
                'synced_at' => now(),
            ];

            if ($created) {
                $lead = new Lead([
                    'organization_id' => $connection->organization_id,
                    'company_id' => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null,
                    /*
                     * Placement first, then scope.
                     *
                     * `leads_franchise_id` says which franchise OWNS the
                     * enquiries this account produces, and is set by somebody
                     * choosing it. It is a different fact from scope_id, which
                     * says who can SEE the connection — the company keeps
                     * seeing it either way, which is the whole point of the two
                     * columns being separate.
                     */
                    'franchise_id' => $connection->leads_franchise_id
                        ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                            ? $connection->scope_id
                            : null),
                    'connection_id' => $connection->getKey(),
                    // Google's own id for the account. It survives a disconnect,
                    // which connection_id does not, and is what lets a reconnect
                    // re-link this row without asking Google for it again.
                    'remote_customer_id' => $connection->remote_id,
                    'source' => Lead::SOURCE_LOCAL_SERVICES,
                    'remote_id' => $remoteId,
                    'status' => Lead::STATUS_NEW,
                    ...$fromSource,
                ]);
            } else {
                /*
                 * Adopt a lead that lost its connection.
                 *
                 * Disconnecting deletes the social_connections row and the
                 * foreign key sets `leads.connection_id` to NULL, so the
                 * enquiries survive but stop belonging to anything. Until now
                 * only the CREATE branch set the column, so a reconnected
                 * account re-read its own leads, updated them, and left every
                 * one of them orphaned for good.
                 *
                 * That is not cosmetic. `canReply()` requires a connection, so
                 * an orphaned MESSAGE enquiry cannot be answered even once the
                 * account is back, and `since()` reads its watermark from leads
                 * carrying the connection id — with none, every reconnect starts
                 * from FIRST_RUN_DAYS instead of from where it left off.
                 *
                 * Jose's "Disconnect all" on 2026-09-14 orphaned 41,006 of
                 * 41,015 leads this way. ThumbtackLeads::store() has carried the
                 * same adoption since the Thumbtack incident on 2026-09-10; this
                 * is the identical hole in the other source, which that fix did
                 * not reach.
                 *
                 * `??=` on the placement columns deliberately: Google says where
                 * the lead came from, but where its work is FILED is a choice
                 * somebody made in the app, and re-seeing the lead is not a
                 * reason to overrule it.
                 */
                // Stamped whether or not it is an orphan: rows written before
                // this column existed have it null and are re-seen here.
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

                // Google sends no name or number for a booking (and often
                // none for a call): its silence must not blank what EzBizi
                // filled from Workiz, a booking email or Grok. Before
                // 2026-10-13 every resync of a booking wiped them.
                foreach (['contact_name', 'phone', 'phone_digits', 'email'] as $field) {
                    if ($fromSource[$field] === null) {
                        unset($fromSource[$field]);
                    }
                }

                // `status` is absent from $fromSource, which is the point.
                $lead->fill($fromSource);
            }

            $lead->save();

            return ['lead' => $lead, 'created' => $created];
        });
    }
}
