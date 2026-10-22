<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Support\Social\GoogleToken;
use App\Support\Automations\EventEmitter;
use App\Support\Social\BusinessProfileOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads reviews from a connected Business Profile, and publishes replies.
 *
 * ── This whole class runs on a deprecated host ───────────────
 *
 * Reviews, replies, posts and media are served only by
 * `mybusiness.googleapis.com/v4`. There is no v1 equivalent and Google has been
 * retiring v4 endpoint by endpoint for years. Everything that touches v4 is
 * therefore in this one file rather than spread across the module: when it
 * goes, there is one place to start, and the modules above it talk to methods
 * rather than to Google.
 *
 * v4 is not a separately enabled Console API. Access rides on the
 * `business.manage` grant, so its absence from the project's enabled-API list
 * is correct rather than a missing step.
 *
 * ── Quota is a separate gate from enablement ─────────────────
 *
 * The Business Profile APIs ship at 0 QPM and stay there until Google approves
 * an access application. Every call here will fail until that lands, which is
 * why the failure path returns a sentence somebody can act on rather than a
 * stack trace: "not approved yet" and "broken" look identical from inside an
 * empty list.
 */
class BusinessProfileReviews
{
    private const TIMEOUT = 20;

    private const PAGE_SIZE = 50;

    /**
     * A ceiling on any paging loop, so a token Google keeps re-issuing cannot
     * spin a request forever spending quota. Generous - at fifty reviews a page
     * this is ten thousand reviews per location - and only ever a backstop.
     */
    private const MAX_PAGES = 200;

    public function __construct(private readonly EventEmitter $events) {}

    /**
     * Pull reviews for every location this connection reaches.
     *
     * @return array{fetched: int, created: int, updated: int}
     */
    public function sync(SocialConnection $connection): array
    {
        $token = GoogleToken::forConnection($connection, 'business_profile');
        $account = trim((string) $connection->remote_id);

        $fetched = 0;
        $created = 0;
        $updated = 0;

        // The first import is the backlog, not news: nothing is announced until
        // the connection already holds its reviews (A-1's 13,000 would otherwise
        // each raise review.received - "no backlogged notifications").
        $announce = Review::query()->where('connection_id', $connection->getKey())->exists();

        foreach ($this->locations($token, $account) as $location) {
            $pageToken = null;
            $pages     = 0;
            $seen      = [];

            do {
                $page = $this->page($token, $location, $pageToken);

                foreach ($page['reviews'] as $raw) {
                    $fetched++;

                    $result = $this->store($connection, $location, $raw);
                    $seen[] = $result['review']->getKey();

                    if ($result['created']) {
                        $created++;
                        $announce && $this->events->emitAfterCommit('review.received', $result['review'], [
                            'after' => [
                                ...$result['review']->only(['rating', 'reviewer_name', 'location_name', 'reviewed_at', 'comment']),
                                // For a rule like "2 stars or fewer, at most 14 days old"
                                // (A1 Leads takeover P0, 2026-10-16).
                                // Never null: a rule reads null as 0, so "at most 14 days"
                                // would pass for a review of unknown age. Undated, it is
                                // as old as the moment it first reached us (Automations, 2026-10-16).
                                'age_days' => (int) ($result['review']->reviewed_at ?? $result['review']->created_at ?? now())
                                    ->diffInDays(now(), true),
                            ],
                        ]);
                    } else {
                        $updated++;
                    }
                }

                $pageToken = $page['next'];
            } while ($pageToken !== null && ++$pages < self::MAX_PAGES);

            // Only a list that reached its end can say a review is missing.
            if ($pageToken === null) {
                $this->checkMissing($token, $connection, $location, $seen);
            }
        }

        $connection->forceFill(['last_reached_at' => now()])->save();

        return ['fetched' => $fetched, 'created' => $created, 'updated' => $updated];
    }

    /** Checks a review must miss, two hours apart, before it counts as removed (the A1 Leads rule). */
    public const REMOVAL_CHECKS = 3;

    /**
     * A review Google's complete list no longer has. It is asked for directly:
     * only a 404 counts, and only once per two hours, so three runs of the
     * six-hourly sync are what it takes. A review seen again starts over.
     * `review.removed` is raised once, when the third check lands.
     *
     * @param  list<string>  $seen
     */
    private function checkMissing(string $token, SocialConnection $connection, string $location, array $seen): void
    {
        $here = fn () => Review::query()->where('connection_id', $connection->getKey())
            ->where('location_name', $location)->whereNull('removed_at');

        $here()->whereIn('id', $seen)->where('missing_checks', '>', 0)
            ->update(['missing_checks' => 0, 'missing_since' => null, 'missing_checked_at' => null]);

        $here()->whereNotIn('id', $seen)
            ->where(fn ($q) => $q->whereNull('missing_checked_at')->orWhere('missing_checked_at', '<=', now()->subHours(2)))
            ->limit(100)->get()
            ->each(function (Review $review) use ($token) {
                try {
                    $status = Http::withToken($token)->timeout(self::TIMEOUT)
                        ->get(BusinessProfileOAuth::LEGACY_V4.'/'.$review->remote_name)->status();
                } catch (ConnectionException) {
                    return;
                }

                if ($status !== 404) {
                    // Still there resets the count; an error is not evidence either way.
                    if ($status < 300) {
                        $review->forceFill(['missing_checks' => 0, 'missing_since' => null, 'missing_checked_at' => null])->save();
                    }

                    return;
                }

                // A day without a check breaks the run: the count starts again.
                $stale = $review->missing_checked_at !== null && $review->missing_checked_at->lt(now()->subDay());
                $checks = $stale ? 1 : $review->missing_checks + 1;
                $review->forceFill([
                    'missing_checks' => $checks,
                    'missing_since' => $stale || $review->missing_since === null ? now() : $review->missing_since,
                    'missing_checked_at' => now(),
                    'removed_at' => $checks >= self::REMOVAL_CHECKS ? now() : null,
                ])->save();

                if ($checks >= self::REMOVAL_CHECKS) {
                    $this->events->emitAfterCommit('review.removed', $review, ['after' => [
                        ...$review->only(['rating', 'reviewer_name', 'location_name', 'reviewed_at', 'comment']),
                        'removed_at' => $review->removed_at,
                        'missing_since' => $review->missing_since,
                    ]]);
                }
            });
    }

    /**
     * Publish a reply, and only ever because a person asked for it.
     *
     * Google allows exactly one reply per review and a second PUT overwrites
     * the first, which is why the stored reply is 1:1 and why this is an
     * update rather than an append.
     *
     * @throws RuntimeException with a sentence fit to show somebody
     */
    public function publish(ReviewReply $reply): void
    {
        $review = $reply->review;
        $connection = $review?->connection;

        if ($review === null || $connection === null) {
            throw new RuntimeException(
                'This review is no longer attached to a connected Business Profile, so a reply '
                .'cannot be sent. Reconnect the profile and fetch again.'
            );
        }

        $token = GoogleToken::forConnection($connection, 'business_profile');

        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->put(
                    BusinessProfileOAuth::LEGACY_V4.'/'.ltrim($review->remote_name, '/').'/reply',
                    ['comment' => $reply->body],
                );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while sending the reply.');
        }

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Google refused the reply (HTTP %d). Nothing was published.',
                $response->status(),
            ));
        }
    }

    /**
     * Locations under this account.
     *
     * v4 wants an account-qualified path — `accounts/X/locations/Y` — while the
     * Business Information API hands back a bare `locations/Y`. Getting them
     * from the account listing keeps the qualified form, which is the one every
     * review and reply call needs back verbatim.
     *
     * @return string[]
     */
    private function locations(string $token, string $account): array
    {
        $names     = [];
        $pageToken = null;
        $pages     = 0;

        // Every page, not the first one. This used to be a single call with
        // pageSize 100 and no look at nextPageToken, so an account with more
        // listings than that had reviews synced for the first hundred and
        // silently nothing for the rest - in the product built for
        // multi-location operators. The listings service beside this one
        // paginated from the start.
        do {
            try {
                $response = Http::withToken($token)
                    ->timeout(self::TIMEOUT)
                    // v1 Business Information, not v4: Google retired v4's location
                    // list (404 on 0.0.0441's first real sync, 2026-10-15). Reviews
                    // themselves are still v4, under accounts/A/locations/L.
                    ->get('https://mybusinessbusinessinformation.googleapis.com/v1/accounts/'.$account.'/locations', array_filter([
                        'readMask'  => 'name',
                        'pageSize'  => 100,
                        'pageToken' => $pageToken,
                    ], fn ($v) => $v !== null));
            } catch (ConnectionException) {
                throw new RuntimeException('Google did not answer while listing locations.');
            }

            if ($response->failed()) {
                throw $this->refused($response->status(), 'listing locations');
            }

            foreach ((array) $response->json('locations', []) as $location) {
                $name = trim((string) ($location['name'] ?? ''));

                if ($name !== '') {
                    $names[] = str_starts_with($name, 'accounts/')
                        ? $name
                        : "accounts/{$account}/".ltrim($name, '/');
                }
            }

            $next      = $response->json('nextPageToken');
            $pageToken = is_string($next) && $next !== '' ? $next : null;
        } while ($pageToken !== null && ++$pages < self::MAX_PAGES);

        return $names;
    }

    /**
     * @return array{reviews: array<int, array<string, mixed>>, next: ?string}
     */
    private function page(string $token, string $location, ?string $pageToken): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get(BusinessProfileOAuth::LEGACY_V4."/{$location}/reviews", array_filter([
                    'pageSize'  => self::PAGE_SIZE,
                    'orderBy'   => 'updateTime desc',
                    'pageToken' => $pageToken,
                ], fn ($v) => $v !== null));
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while fetching reviews.');
        }

        if ($response->failed()) {
            throw $this->refused($response->status(), 'fetching reviews');
        }

        $next = $response->json('nextPageToken');

        return [
            'reviews' => (array) $response->json('reviews', []),
            'next'    => is_string($next) && $next !== '' ? $next : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{review: Review, created: bool}
     */
    private function store(SocialConnection $connection, string $location, array $raw): array
    {
        $name = trim((string) ($raw['name'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Google returned a review with no name.');
        }

        $reviewer = (array) ($raw['reviewer'] ?? []);

        return DB::transaction(function () use ($connection, $location, $raw, $name, $reviewer) {
            $review = Review::query()
                ->where('organization_id', $connection->organization_id)
                ->where('remote_name', $name)
                ->first();

            $created = $review === null;

            $fromGoogle = [
                'location_name'      => $location,
                'reviewer_name'      => trim((string) ($reviewer['displayName'] ?? '')) ?: null,
                'reviewer_photo_url' => trim((string) ($reviewer['profilePhotoUrl'] ?? '')) ?: null,
                'rating'             => $this->stars($raw['starRating'] ?? null),
                'comment'            => trim((string) ($raw['comment'] ?? '')) ?: null,
                'reviewed_at'        => isset($raw['createTime'])
                    ? Carbon::parse((string) $raw['createTime'])
                    : null,
                'edited_at'          => isset($raw['updateTime'])
                    ? Carbon::parse((string) $raw['updateTime'])
                    : null,
                'synced_at'          => now(),
            ];

            if ($created) {
                // Where its listing sits, once somebody gave the listing a branch;
                // else where the connection sits (2026-10-22).
                $tail = \App\Support\CustomerExperience\Places::tail($location);
                $listing = $tail === null ? null : \App\Models\BusinessLocation::query()->whereNotNull('franchise_id')
                    ->where(fn ($q) => $q->where('remote_name', $tail)->orWhere('remote_name', 'like', '%/'.addcslashes($tail, '%_')))
                    ->first(['company_id', 'franchise_id']);
                $review = new Review([
                    'organization_id' => $connection->organization_id,
                    'company_id'      => $listing?->company_id ?? ($connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null),
                    'franchise_id'    => $listing?->franchise_id ?? ($connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                        ? $connection->scope_id
                        : null),
                    'connection_id'   => $connection->getKey(),
                    'remote_name'     => $name,
                    ...$fromGoogle,
                ]);
            } else {
                $review->fill($fromGoogle);
            }

            $review->save();
            $this->keepGooglesReply($review, (array) ($raw['reviewReply'] ?? []));

            return ['review' => $review, 'created' => $created];
        });
    }

    /**
     * The answer Google already shows, so a review answered on Google (or by
     * A1 Leads) is not counted Unanswered. Never over a reply drafted or
     * published here: those are this organization's own words.
     *
     * @param  array<string, mixed>  $reply
     */
    private function keepGooglesReply(Review $review, array $reply): void
    {
        $body = trim((string) ($reply['comment'] ?? ''));
        $mine = $review->reply()->first();

        if ($body === '' || ($mine !== null && $mine->drafted_by !== ReviewReply::BY_GOOGLE)) {
            return;
        }

        ($mine ?? new ReviewReply(['organization_id' => $review->organization_id, 'review_id' => $review->getKey()]))
            ->fill([
                'body' => $body,
                'status' => ReviewReply::STATUS_PUBLISHED,
                'drafted_by' => ReviewReply::BY_GOOGLE,
                'published_at' => isset($reply['updateTime']) ? Carbon::parse((string) $reply['updateTime']) : now(),
            ])->save();
    }

    /**
     * Google sends stars as a word, and `STAR_RATING_UNSPECIFIED` for none.
     *
     * Mapped to null rather than 0. A review nobody rated is not a nought-star
     * review, and averaging one in as zero would libel the business by
     * arithmetic.
     */
    private function stars(mixed $value): ?int
    {
        return match (is_string($value) ? strtoupper($value) : '') {
            'ONE'   => 1,
            'TWO'   => 2,
            'THREE' => 3,
            'FOUR'  => 4,
            'FIVE'  => 5,
            default => Review::NO_RATING,
        };
    }

    /** A refusal in words somebody can act on, not a status code. */
    private function refused(int $status, string $doing): RuntimeException
    {
        if ($status === 429) {
            return new RuntimeException(
                'Google has not granted this installation any Business Profile quota yet, so '
                .'reviews cannot be read. This is an approval EzBizi is waiting on, not a problem '
                .'with the connected profile.'
            );
        }

        // 403 used to share the quota sentence above, and that sentence told
        // the customer to do nothing. A 403 from this API is PERMISSION_DENIED:
        // a grant somebody revoked in their Google Account, or a listing this
        // user may not read. That IS the connection, and the fix is to connect
        // it again - which the quota wording actively steered them away from.
        if ($status === 403 || $status === 401) {
            return new RuntimeException(
                'Google no longer accepts this connection\'s permission to read the profile, so '
                .'reviews cannot be fetched. Connect the Business Profile again to grant it afresh.'
            );
        }

        return new RuntimeException("Google refused while {$doing} (HTTP {$status}).");
    }

}
