<?php

namespace App\Services\Leads;

use App\Models\Lead;
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

                // Outside the transaction on purpose: emitting inside one means
                // an automation running against a row the emitter has not
                // committed yet. `emitAfterCommit` exists for the same reason,
                // and `wasCreated` is what tells us which to raise.
                $result = $this->store($connection, $raw);

                if ($result['created']) {
                    $created++;
                    $this->events->emitAfterCommit('lead.received', $result['lead'], [
                        'after' => $result['lead']->only([
                            'source', 'contact_name', 'phone', 'category', 'received_at',
                        ]),
                    ]);
                } else {
                    $updated++;
                }
            }

            $pageToken = $page['next'];
        } while ($pageToken !== null && ++$pages < self::MAX_PAGES);

        $connection->forceFill(['last_reached_at' => now()])->save();

        return ['fetched' => $fetched, 'created' => $created, 'updated' => $updated];
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
        $body = array_filter([
            'pageToken' => $pageToken,
            'query'     => sprintf(
                'SELECT local_services_lead.id, local_services_lead.lead_type, '
                .'local_services_lead.category_id, local_services_lead.contact_details, '
                .'local_services_lead.lead_status, local_services_lead.lead_charged, '
                .'local_services_lead.credit_details.credit_state, '
                .'local_services_lead.creation_date_time '
                .'FROM local_services_lead '
                ."WHERE local_services_lead.creation_date_time >= '%s'",
                $since->utc()->format('Y-m-d H:i:s'),
            ),
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
            throw new RuntimeException('Google did not answer while fetching leads.');
        }

        if ($response->failed()) {
            $error  = (array) $response->json('error', []);
            $detail = is_string($error['message'] ?? null) ? (string) $error['message'] : '';

            Log::warning('Google refused a Local Services lead fetch.', [
                'http_status'   => $response->status(),
                'google_status' => is_string($error['status'] ?? null) ? $error['status'] : null,
                'detail'        => mb_substr($detail, 0, 300),
            ]);

            throw new RuntimeException(sprintf(
                'Google refused the lead request (HTTP %d).',
                $response->status(),
            ));
        }

        $next = $response->json('nextPageToken');

        return [
            'leads' => array_values(array_filter(array_map(
                fn ($row) => $this->fromSearchRow(is_array($row) ? $row : []),
                (array) $response->json('results', []),
            ))),
            'next'  => is_string($next) && $next !== '' ? $next : null,
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
                    'franchise_id'    => $connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                        ? $connection->scope_id
                        : null,
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
