<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
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

    public function __construct(private readonly EventEmitter $events) {}

    /**
     * Pull reviews for every location this connection reaches.
     *
     * @return array{fetched: int, created: int, updated: int}
     */
    public function sync(SocialConnection $connection): array
    {
        $token = $this->accessToken($connection);
        $account = trim((string) $connection->remote_id);

        $fetched = 0;
        $created = 0;
        $updated = 0;

        foreach ($this->locations($token, $account) as $location) {
            $pageToken = null;

            do {
                $page = $this->page($token, $location, $pageToken);

                foreach ($page['reviews'] as $raw) {
                    $fetched++;

                    $result = $this->store($connection, $location, $raw);

                    if ($result['created']) {
                        $created++;
                        $this->events->emitAfterCommit('review.received', $result['review'], [
                            'after' => $result['review']->only([
                                'rating', 'reviewer_name', 'location_name', 'reviewed_at',
                            ]),
                        ]);
                    } else {
                        $updated++;
                    }
                }

                $pageToken = $page['next'];
            } while ($pageToken !== null);
        }

        $connection->forceFill(['last_reached_at' => now()])->save();

        return ['fetched' => $fetched, 'created' => $created, 'updated' => $updated];
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

        $token = $this->accessToken($connection);

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
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->get(BusinessProfileOAuth::LEGACY_V4."/accounts/{$account}/locations", [
                    'pageSize' => 100,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while listing locations.');
        }

        if ($response->failed()) {
            throw $this->refused($response->status(), 'listing locations');
        }

        $names = [];

        foreach ((array) $response->json('locations', []) as $location) {
            $name = trim((string) ($location['name'] ?? ''));

            if ($name !== '') {
                $names[] = str_starts_with($name, 'accounts/')
                    ? $name
                    : "accounts/{$account}/".ltrim($name, '/');
            }
        }

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
                $review = new Review([
                    'organization_id' => $connection->organization_id,
                    'company_id'      => $connection->scope_type === SocialConnection::SCOPE_COMPANY
                        ? $connection->scope_id
                        : null,
                    'franchise_id'    => $connection->scope_type === SocialConnection::SCOPE_FRANCHISE
                        ? $connection->scope_id
                        : null,
                    'connection_id'   => $connection->getKey(),
                    'remote_name'     => $name,
                    ...$fromGoogle,
                ]);
            } else {
                $review->fill($fromGoogle);
            }

            $review->save();

            return ['review' => $review, 'created' => $created];
        });
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
        if ($status === 429 || $status === 403) {
            return new RuntimeException(
                'Google has not granted this installation any Business Profile quota yet, so '
                .'reviews cannot be read. This is an approval EzBizi is waiting on, not a problem '
                .'with the connected profile.'
            );
        }

        return new RuntimeException("Google refused while {$doing} (HTTP {$status}).");
    }

    /**
     * A fresh access token from the stored refresh token.
     *
     * Access tokens last an hour and a sync may run at any time, so there is no
     * useful case where the stored one is still good.
     */
    private function accessToken(SocialConnection $connection): string
    {
        $refresh = (string) (($connection->credentials['refresh_token'] ?? '') ?: '');

        if ($refresh === '') {
            throw new RuntimeException(
                'This Business Profile connection has no lasting permission stored, so it cannot '
                .'be refreshed. It needs connecting again.'
            );
        }

        try {
            $response = Http::asForm()->timeout(self::TIMEOUT)->post(
                'https://oauth2.googleapis.com/token',
                [
                    'client_id'     => (string) config('services.business_profile.client_id'),
                    'client_secret' => (string) config('services.business_profile.client_secret'),
                    'refresh_token' => $refresh,
                    'grant_type'    => 'refresh_token',
                ],
            );
        } catch (ConnectionException) {
            throw new RuntimeException('Google did not answer while renewing the connection.');
        }

        $access = $response->json('access_token');

        if ($response->failed() || ! is_string($access) || $access === '') {
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
}
