<?php

namespace App\Services\Leads;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Support\Automations\EventEmitter;
use App\Support\Social\GoogleToken;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches the listings on a connected Business Profile and writes them down.
 *
 * ── This will fail until Google grants quota, and that is expected ──
 *
 * The Business Profile APIs ship at 0 QPM and stay there until an access
 * application is approved — an application that cannot even be filed until the
 * applicant has managed a verified profile for sixty days. Enabling the APIs in
 * the console does NOT grant quota; they are separate gates, and a project with
 * all four enabled still fails every call.
 *
 * So `sync()` here is complete and correct and returns a quota refusal today.
 * That refusal is reported as a sentence somebody can act on rather than as a
 * generic failure, because "we are waiting on Google" and "something is broken"
 * are different problems and only one of them is worth ringing somebody about.
 *
 * ── What it does NOT do ──────────────────────────────────────────
 *
 * Write anything back. Hours, description, categories and phone are all
 * writable through this API, and none of it is written here: changing what the
 * public sees about a business is not something a sync should do as a side
 * effect of reading. When that arrives it will be an explicit, audited act with
 * its own permission.
 */
class BusinessProfileLocations
{
    private const TIMEOUT = 20;

    private const PAGE_SIZE = 100;

    /**
     * The fields worth asking for.
     *
     * Google requires an explicit read mask and refuses the request without
     * one — there is no "everything". Listing them here rather than asking for
     * the widest possible set keeps the response small and makes the columns
     * this model holds the same list somebody can read in one place.
     */
    private const READ_MASK = 'name,title,phoneNumbers,storefrontAddress,websiteUri,'
        .'regularHours,categories,metadata,openInfo,serviceArea';

    public function __construct(private readonly EventEmitter $events) {}

    /**
     * @return array{fetched: int, created: int, updated: int}
     */
    public function sync(SocialConnection $connection): array
    {
        $token = GoogleToken::forConnection($connection, 'business_profile');

        $fetched = 0;
        $created = 0;
        $updated = 0;
        $pageToken = null;
        $seen = [];

        do {
            $page = $this->page($connection, $token, $pageToken);

            foreach ($page['locations'] as $raw) {
                $fetched++;

                $result = $this->store($connection, $raw);
                $seen[] = $result['location']->getKey();

                $result['created'] ? $created++ : $updated++;

                // Only when Google's own state moved, and only after commit.
                // A listing quietly going SUSPENDED is the one thing here worth
                // interrupting somebody about; a phone number being re-read
                // unchanged is not.
                if (! $result['created'] && $result['stateChanged']) {
                    $this->events->emitAfterCommit(
                        'business_location.changed_on_google',
                        $result['location'],
                        ['before' => $result['before'], 'after' => $result['after']],
                    );
                }
            }

            $pageToken = $page['next'];
        } while ($pageToken !== null);

        $this->markListing($connection, $seen);

        $connection->forceFill(['last_reached_at' => now()])->save();

        return ['fetched' => $fetched, 'created' => $created, 'updated' => $updated];
    }

    /**
     * The complete list said which of this connection's locations Google still
     * returns. One it stopped returning is "not listed" from now, and one that
     * comes back is listed again; either is raised as a change on Google with
     * `listed` in the fields that moved. Never "removed": a profile leaves an
     * account's list when access moves as well as when it is gone.
     *
     * @param  list<string>  $seen
     */
    private function markListing(SocialConnection $connection, array $seen): void
    {
        $mine = BusinessLocation::query()->where('connection_id', $connection->getKey());

        (clone $mine)->whereNotIn('id', $seen)->whereNull('not_listed_since')->get()
            ->each(function (BusinessLocation $location) {
                $location->forceFill(['not_listed_since' => now()])->save();
                $this->events->emitAfterCommit('business_location.changed_on_google', $location,
                    ['before' => ['listed' => true], 'after' => ['listed' => false]]);
            });

        (clone $mine)->whereIn('id', $seen)->whereNotNull('not_listed_since')->get()
            ->each(function (BusinessLocation $location) {
                $location->forceFill(['not_listed_since' => null])->save();
                $this->events->emitAfterCommit('business_location.changed_on_google', $location,
                    ['before' => ['listed' => false], 'after' => ['listed' => true]]);
            });
    }

    /**
     * @return array{locations: array<int, array<string, mixed>>, next: ?string}
     */
    private function page(SocialConnection $connection, string $token, ?string $pageToken): array
    {
        $account = trim((string) $connection->remote_id);

        if (! str_starts_with($account, 'accounts/')) {
            $account = 'accounts/'.$account;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get('https://mybusinessbusinessinformation.googleapis.com/v1/'.$account.'/locations',
                    array_filter([
                        'readMask' => self::READ_MASK,
                        'pageSize' => self::PAGE_SIZE,
                        'pageToken' => $pageToken,
                    ], fn ($v) => $v !== null));
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while fetching locations.');
        }

        if ($response->status() === 429 || $response->status() === 403) {
            // The expected answer today, and a different problem from a fault.
            // Said in the words somebody can act on: there is nothing to fix
            // at this end and no amount of retrying will help.
            throw new RuntimeException(
                'Google has not granted this installation permission to read Business Profiles '
                .'yet, so there is nothing to fetch. The application for it is pending; nothing '
                .'is wrong with the connected profile.'
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Google refused the location request (HTTP %d).',
                $response->status(),
            ));
        }

        $next = $response->json('nextPageToken');

        return [
            'locations' => (array) $response->json('locations', []),
            'next' => is_string($next) && $next !== '' ? $next : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{location: BusinessLocation, created: bool, stateChanged: bool, before: array, after: array}
     */
    private function store(SocialConnection $connection, array $raw): array
    {
        $remote = trim((string) ($raw['name'] ?? ''));

        if ($remote === '') {
            throw new RuntimeException('Google returned a location with no name.');
        }

        return DB::transaction(function () use ($connection, $raw, $remote) {
            $location = BusinessLocation::query()
                ->where('organization_id', $connection->organization_id)
                ->where('remote_name', $remote)
                ->first();

            $created = $location === null;
            $address = (array) ($raw['storefrontAddress'] ?? []);
            $lines = (array) ($address['addressLines'] ?? []);
            $metadata = (array) ($raw['metadata'] ?? []);
            $openInfo = (array) ($raw['openInfo'] ?? []);

            $fields = [
                'title' => trim((string) ($raw['title'] ?? '')) ?: $remote,
                'phone' => trim((string) (((array) ($raw['phoneNumbers'] ?? []))['primaryPhone'] ?? '')) ?: null,
                'website' => trim((string) ($raw['websiteUri'] ?? '')) ?: null,

                'address_line1' => trim((string) ($lines[0] ?? '')) ?: null,
                'address_line2' => trim((string) ($lines[1] ?? '')) ?: null,
                'city' => trim((string) ($address['locality'] ?? '')) ?: null,
                'state' => trim((string) ($address['administrativeArea'] ?? '')) ?: null,
                'postal_code' => trim((string) ($address['postalCode'] ?? '')) ?: null,
                'country' => trim((string) ($address['regionCode'] ?? '')) ?: null,

                'primary_category' => trim((string) (
                    ((array) (((array) ($raw['categories'] ?? []))['primaryCategory'] ?? []))['displayName'] ?? ''
                )) ?: null,

                // `hasVoiceOfMerchant` is Google's own answer to "is this
                // listing actually working" — it is false for suspended,
                // duplicate and unverified listings alike, which is the state
                // somebody needs to see.
                'verification_state' => ($metadata['hasVoiceOfMerchant'] ?? null) === true
                    ? BusinessLocation::VERIFIED
                    : BusinessLocation::UNVERIFIED,

                'is_published' => ($openInfo['status'] ?? null) === 'OPEN',
                'hours' => $raw['regularHours'] ?? null,
                // Where a service-area listing (no storefront) says it works:
                // its only "where" (Scheduling › Locations, 2026-11-05).
                'service_places' => array_values(array_filter(array_map(
                    fn ($p) => trim((string) ($p['placeName'] ?? '')),
                    (array) (((array) ($raw['serviceArea'] ?? []))['places']['placeInfos'] ?? []),
                ))) ?: null,
                'synced_at' => now(),
            ];

            // What a person would notice changing on their own listing without
            // anybody here touching it. A re-read that returns identical values
            // must not raise an event, or the rule fires nightly forever.
            $watched = ['title', 'phone', 'website', 'address_line1', 'city', 'postal_code',
                'primary_category', 'verification_state', 'is_published'];

            $before = [];
            $after = [];

            if (! $created) {
                foreach ($watched as $key) {
                    if ($location->{$key} !== $fields[$key]) {
                        $before[$key] = $location->{$key};
                        $after[$key] = $fields[$key];
                    }
                }
            }

            if ($created) {
                $location = new BusinessLocation([
                    'organization_id' => $connection->organization_id,
                    'company_id' => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null,
                    'franchise_id' => $connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                        ? $connection->scope_id
                        : null,
                    'connection_id' => $connection->getKey(),
                    'remote_name' => $remote,
                    'account_name' => trim((string) $connection->remote_id) ?: null,
                    ...$fields,
                ]);
            } else {
                $location->fill($fields);

                // A listing left behind by a disconnected account (its
                // connection_id nulled) belongs to the account that lists it
                // now (2026-10-29: A-1 reconnected on EzBizi's own client and
                // all 38 stayed orphaned). A chosen branch stays; an unmapped
                // listing follows its account's company.
                if ($location->connection_id === null) {
                    $location->connection_id = $connection->getKey();
                    if ($location->franchise_id === null && $connection->scope_type === SocialConnection::SCOPE_COMPANY) {
                        $location->company_id = $connection->scope_id;
                    }
                }
            }

            $location->save();

            return [
                'location' => $location,
                'created' => $created,
                'stateChanged' => $after !== [],
                'before' => $before,
                'after' => $after,
            ];
        });
    }
}
