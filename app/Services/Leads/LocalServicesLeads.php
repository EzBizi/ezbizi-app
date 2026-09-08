<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
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
class LocalServicesLeads
{
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
    ) {}

    /**
     * Pull everything new for one connected Google Ads account.
     *
     * @return array{fetched: int, created: int, updated: int}
     */
    public function sync(SocialConnection $connection): array
    {
        $token = GoogleToken::forConnection($connection, 'google_ads');
        $since = $this->since($connection);

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

        $conversations = $this->syncConversations($connection, $token);

        $connection->forceFill(['last_reached_at' => now()])->save();

        return [
            'fetched'       => $fetched,
            'created'       => $created,
            'updated'       => $updated,
            'conversations' => $conversations,
        ];
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
                ]),
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
    private function syncConversations(SocialConnection $connection, string $token): array
    {
        $none = ['fetched' => 0, 'created' => 0, 'leads_recovered' => 0];

        if (! Lead::query()->where('connection_id', $connection->getKey())->exists()) {
            return $none;
        }

        $since   = $this->conversationsSince($connection);
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
            .'local_services_lead_conversation.message_details.attachment_urls '
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
        $c  = (array) ($row['localServicesLeadConversation'] ?? []);
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
        $msg  = (array) ($c['messageDetails'] ?? []);

        return [
            'id'           => $id,
            'leadId'       => $leadId,
            'channel'      => trim((string) ($c['conversationChannel'] ?? '')) ?: 'UNKNOWN',
            'participant'  => trim((string) ($c['participantType'] ?? '')) ?: 'UNKNOWN',
            'occurredAt'   => $c['eventDateTime'] ?? null,
            'durationMs'   => isset($call['callDurationMillis']) && is_numeric($call['callDurationMillis'])
                ? (int) $call['callDurationMillis']
                : null,
            'recordingUrl' => trim((string) ($call['callRecordingUrl'] ?? '')) ?: null,
            'text'         => trim((string) ($msg['text'] ?? '')) ?: null,
            'attachments'  => isset($msg['attachmentUrls']) && is_array($msg['attachmentUrls']) && $msg['attachmentUrls'] !== []
                ? array_values(array_map('strval', $msg['attachmentUrls']))
                : null,
        ];
    }

    /**
     * True when the row is new. An existing row is refreshed - Google can
     * attach a recording URL a little after the call itself appears.
     *
     * @param  array<string, mixed>  $raw
     */
    private function storeConversation(Lead $lead, array $raw): bool
    {
        $fields = [
            'channel'       => $raw['channel'],
            'participant'   => $raw['participant'],
            'occurred_at'   => $raw['occurredAt'] !== null ? Carbon::parse((string) $raw['occurredAt']) : now(),
            'duration_ms'   => $raw['durationMs'],
            'recording_url' => $raw['recordingUrl'],
            'text'          => $raw['text'],
            'attachments'   => $raw['attachments'],
        ];

        $existing = LeadConversation::query()
            ->where('organization_id', $lead->organization_id)
            ->where('remote_id', $raw['id'])
            ->first();

        if ($existing !== null) {
            $existing->fill($fields)->save();

            return false;
        }

        $row = LeadConversation::create([
            'organization_id' => $lead->organization_id,
            'lead_id'         => $lead->getKey(),
            'remote_id'       => $raw['id'],
            ...$fields,
        ]);

        // The enquirer reached out - called, or wrote. The business's own
        // replies also land here, but they are not something to be told about.
        if ($row->participant === LeadConversation::FROM_CONSUMER) {
            $this->events->emitAfterCommit('lead.contact_received', $lead, [
                'after' => [
                    'channel'     => $row->channel,
                    'occurred_at' => $row->occurred_at,
                    'preview'     => self::preview($row),
                    'contact_name'=> $lead->contact_name,
                    'phone'       => $lead->phone,
                ],
            ]);
        }

        return true;
    }

    /** The list's preview line, rewritten from the newest row every sync. */
    private function refreshLastContact(Lead $lead): void
    {
        $last = LeadConversation::query()
            ->where('lead_id', $lead->getKey())
            ->orderByDesc('occurred_at')
            ->first();

        $lead->forceFill([
            'last_contact_at'      => $last?->occurred_at,
            'last_contact_channel' => $last?->channel,
            'last_contact_from'    => $last?->participant,
            'last_contact_preview' => $last !== null ? self::preview($last) : null,
        ])->save();
    }

    /**
     * One line. A message is its own first line; a call is its length, because
     * "Call · 0:00" and "Call · 4:32" are the two things a person wants to
     * know about it from the list.
     */
    public static function preview(LeadConversation $row): ?string
    {
        if ($row->text !== null) {
            $line = trim((string) preg_replace('/\s+/', ' ', $row->text));

            return mb_substr($line, 0, 255);
        }

        if ($row->channel === LeadConversation::CHANNEL_PHONE_CALL) {
            $seconds = intdiv((int) ($row->duration_ms ?? 0), 1000);

            return sprintf('Call · %d:%02d', intdiv($seconds, 60), $seconds % 60);
        }

        return null;
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
            'next'  => $found['next'],
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
        .'local_services_lead.creation_date_time, local_services_lead.locale '
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
            'query'     => $query,
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
                    'login-customer-id' => $customerId,
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
            $error  = (array) $response->json('error', []);
            $detail = is_string($error['message'] ?? null) ? (string) $error['message'] : '';

            Log::warning("Google refused a Local Services {$what} fetch.", [
                'http_status'   => $response->status(),
                'google_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
                'detail'        => mb_substr($detail, 0, 300),
            ]);

            throw new RuntimeException(sprintf(
                'Google refused the %s request (HTTP %d).',
                $what,
                $response->status(),
            ));
        }

        $next = $response->json('nextPageToken');

        return [
            'results' => (array) $response->json('results', []),
            'next'    => is_string($next) && $next !== '' ? $next : null,
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
        $id   = trim((string) ($lead['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        $contact = (array) ($lead['contactDetails'] ?? []);
        $credit  = (array) ($lead['creditDetails'] ?? []);

        return [
            'leadId'          => $id,
            'leadType'        => $lead['leadType'] ?? null,
            'leadCategory'    => $lead['categoryId'] ?? null,
            'leadStatus'      => $lead['leadStatus'] ?? null,
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
            'chargeStatus'    => $credit['creditState']
                ?? ((isset($lead['leadCharged']) && $lead['leadCharged']) ? 'CHARGED' : null),
            'creationDateTime'=> $lead['creationDateTime'] ?? null,
            'service'         => $lead['serviceId'] ?? null,
            'locale'          => $lead['locale'] ?? null,
            'contactDetails'  => [
                'phoneNumber'  => $contact['phoneNumber'] ?? null,
                'consumerName' => $contact['consumerName'] ?? null,
                'email'        => $contact['email'] ?? null,
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
        $phone   = trim((string) ($contact['phoneNumber'] ?? '')) ?: null;

        return DB::transaction(function () use ($connection, $raw, $remoteId, $contact, $phone) {
            $lead = Lead::query()
                ->where('organization_id', $connection->organization_id)
                ->where('source', Lead::SOURCE_LOCAL_SERVICES)
                ->where('remote_id', $remoteId)
                ->first();

            $created = $lead === null;

            $fromSource = [
                'contact_name'  => trim((string) ($contact['consumerName'] ?? '')) ?: null,
                'phone'         => $phone,
                'phone_digits'  => Lead::digits($phone),
                'email'         => trim((string) ($contact['email'] ?? '')) ?: null,
                'lead_type'     => trim((string) ($raw['leadType'] ?? '')) ?: null,
                'category'      => trim((string) ($raw['leadCategory'] ?? '')) ?: null,
                'service'       => trim((string) ($raw['service'] ?? '')) ?: null,
                'locale'        => trim((string) ($raw['locale'] ?? '')) ?: null,
                'source_status' => trim((string) ($raw['leadStatus'] ?? '')) ?: null,
                'charge_status' => trim((string) ($raw['chargeStatus'] ?? '')) ?: null,
                'received_at'   => isset($raw['creationDateTime'])
                    ? Carbon::parse((string) $raw['creationDateTime'])
                    : now(),
                'synced_at'     => now(),
            ];

            if ($created) {
                $lead = new Lead([
                    'organization_id' => $connection->organization_id,
                    'company_id'      => $connection->scope_type === SocialConnection::SCOPE_COMPANY
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
                    'franchise_id'    => $connection->leads_franchise_id
                        ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                            ? $connection->scope_id
                            : null),
                    'connection_id'   => $connection->getKey(),
                    'source'          => Lead::SOURCE_LOCAL_SERVICES,
                    'remote_id'       => $remoteId,
                    'status'          => Lead::STATUS_NEW,
                    ...$fromSource,
                ]);
            } else {
                // `status` is absent from $fromSource, which is the point.
                $lead->fill($fromSource);
            }

            $lead->save();

            return ['lead' => $lead, 'created' => $created];
        });
    }


}
