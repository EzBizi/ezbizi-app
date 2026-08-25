<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Social\GoogleAdsOAuth;
use App\Support\Social\SocialOAuthGrant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    /** Google caps this itself; asking for more is refused rather than clamped. */
    private const PAGE_SIZE = 100;

    /**
     * How far back a sync looks when the tenant has no leads yet.
     *
     * Not "everything". A first sync that walked years of history would spend a
     * new customer's entire daily quota before they had seen a single row, and
     * the enquiries that matter on day one are the recent ones. Later syncs
     * start from the newest lead already held.
     */
    private const FIRST_RUN_DAYS = 30;

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
        $token = $this->accessToken($connection);
        $since = $this->since($connection);

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $pageToken = null;

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
        } while ($pageToken !== null);

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

        $query = array_filter([
            'pageSize'  => self::PAGE_SIZE,
            'pageToken' => $pageToken,
            'query'     => sprintf(
                "SELECT lead_id, lead_type, lead_category, contact_details, lead_status, "
                ."charge_status, creation_date_time WHERE creation_date_time >= '%s'",
                $since->toIso8601String(),
            ),
        ], fn ($v) => $v !== null);

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'developer-token' => (string) config('services.google_ads.developer_token'),
                ])
                ->timeout(self::TIMEOUT)
                ->get(
                    GoogleAdsOAuth::endpoint()."/customers/{$customerId}/localServices:searchDetailedLeadReports",
                    $query,
                );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while fetching leads.');
        }

        if ($response->failed()) {
            // The message Google sends is for us, not for a tenant's screen —
            // it names quotas and resource paths. The caller turns this into a
            // sentence somebody can act on.
            throw new RuntimeException(sprintf(
                'Google refused the lead request (HTTP %d).',
                $response->status(),
            ));
        }

        $next = $response->json('nextPageToken');

        return [
            'leads' => (array) $response->json('detailedLeadReports', []),
            'next'  => is_string($next) && $next !== '' ? $next : null,
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

    /**
     * A fresh access token from the stored refresh token.
     *
     * Access tokens last an hour and a sync may run at any time, so there is no
     * useful case where the stored one is still good. Refreshing every run is
     * one extra call and removes a whole class of "worked this morning".
     */
    private function accessToken(SocialConnection $connection): string
    {
        $refresh = (string) (($connection->credentials['refresh_token'] ?? '') ?: '');

        if ($refresh === '') {
            throw new RuntimeException(
                'This Google Ads connection has no lasting permission stored, so it cannot be '
                .'refreshed. It needs connecting again.'
            );
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://oauth2.googleapis.com/token',
                [
                    'client_id'     => (string) config('services.google_ads.client_id'),
                    'client_secret' => (string) config('services.google_ads.client_secret'),
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
                ],
            );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while renewing the connection.');
        }

        $access = $response->json('access_token');

        if ($response->failed() || ! is_string($access) || $access === '') {
            // Google says a refresh token is dead the same way whether the
            // customer revoked it or the grant simply expired, so the row is
            // marked broken and the screen says "connect it again" rather than
            // guessing which happened.
            $connection->forceFill([
                'status'        => SocialConnection::STATUS_BROKEN,
                'status_reason' => 'Google would not renew this connection. It needs connecting again.',
            ])->save();

            throw new RuntimeException(
                'Google would not renew this connection. It needs connecting again.'
            );
        }

        return $access;
    }

    /** Kept so a caller can hand in a grant it already has, in tests. */
    public function tokenFromGrant(SocialOAuthGrant $grant): string
    {
        return $grant->accessToken;
    }
}
