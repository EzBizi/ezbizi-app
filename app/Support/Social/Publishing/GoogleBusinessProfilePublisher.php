<?php

namespace App\Support\Social\Publishing;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Support\Social\BusinessProfileOAuth;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A Google Business Profile post (an "update") on one location: Rodrigo's
 * Social Poster target, taken over (Jose, 2026-10-15; a1leads
 * integrations/google-business-profile.js createLocalPost/listLocalPosts).
 *
 * ── The v4 host, and our quota ───────────────────────────────────
 *
 * Posts exist only on `mybusiness.googleapis.com/v4` (`localPosts`), like
 * reviews and replies; there is no v1. Every call needs the project's Business
 * Profile API quota, which is 0 until Google approves the application (filed
 * from info@ezbizi.com on or after 2026-10-24, docs/BUSINESS-PROFILE.md). Until
 * then each call comes back 403/429 and is reported as exactly that.
 *
 * ── Which location ───────────────────────────────────────────────
 *
 * A connection is an account; a post belongs to one location. The composer
 * names it in `options['gbp_location']` (accounts/A/locations/L); without one,
 * an account with a single location posts there, and anything else is refused
 * with the reason rather than guessed.
 *
 * ── The photo ────────────────────────────────────────────────────
 *
 * Google takes a picture only by URL (`media[].sourceUrl`), so it goes through
 * MediaHandoff like Instagram's. Not released after a successful call: when
 * Google fetches it is not documented, so the handoff lives its signed hour
 * and the sweep deletes it. A refused call releases it at once, since Google
 * will never fetch it. ponytail: release on success too if Google proves to
 * fetch during the create.
 */
final class GoogleBusinessProfilePublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const TIMEOUT = 30;

    /** Google's limit on a post's text. Refused rather than cut. */
    public const MAX_SUMMARY = 1500;

    /** The buttons a post may carry; CALL takes no URL. */
    public const ACTIONS = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        return $kind === SocialMediaKind::Video
            ? 'Google Business Profile posts take a picture, not a video. Attach a photo or send words only.'
            : null;
    }

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $summary = trim($post->body);
        if ($summary === '') {
            throw new SocialPublishFailed('A Business Profile post needs words. Write something.');
        }
        if (mb_strlen($summary) > self::MAX_SUMMARY) {
            throw new SocialPublishFailed('Google takes at most '.self::MAX_SUMMARY.' characters in a post; this one has '.mb_strlen($summary).'.');
        }

        $body = ['languageCode' => 'en-US', 'summary' => $summary, 'topicType' => 'STANDARD'];

        $action = strtoupper((string) ($post->options['cta_type'] ?? ''));
        if ($action !== '') {
            if (! in_array($action, self::ACTIONS, true)) {
                throw new SocialPublishFailed('Google does not have a "'.$action.'" button.');
            }
            $body['callToAction'] = array_filter([
                'actionType' => $action,
                'url' => $action === 'CALL' ? null : ($post->options['cta_url'] ?? null),
            ]);
        }

        $handoff = null;
        if ($post->kind() === SocialMediaKind::Image) {
            $handoff = MediaHandoff::hold($post);
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $handoff->url]];
        }

        try {
            $created = $this->send($connection, fn ($http) => $http->post(
                BusinessProfileOAuth::LEGACY_V4.'/'.$this->location($connection, $post).'/localPosts', $body));
        } catch (\Throwable $e) {
            // Refused: Google will never fetch it, so the photo stops being
            // fetchable now. A post that went through keeps its handoff (above).
            $handoff?->release();

            throw $e;
        }

        $name = (string) ($created->json('name') ?? '');
        if ($name === '') {
            throw SocialPublishFailed::network('Google Business Profile', 'the post was accepted but no id came back.');
        }

        return $name;
    }

    /**
     * The location's posts, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function posts(SocialConnection $connection, string $location, int $pageSize = 20): array
    {
        $response = $this->send($connection, fn ($http) => $http->get(
            BusinessProfileOAuth::LEGACY_V4.'/'.ltrim($location, '/').'/localPosts', ['pageSize' => $pageSize]));

        return array_values((array) $response->json('localPosts', []));
    }

    /** LinksToPosts: the post's own `searchUrl`, the page Google shows it on. */
    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        $url = $this->send($connection, fn ($http) => $http->get(BusinessProfileOAuth::LEGACY_V4.'/'.$remoteId))->json('searchUrl');

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** DeletesPosts: the composer's "Delete from Google". */
    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        $this->delete($connection, $remoteId);
    }

    /**
     * A stored location's name as v4 wants it: accounts/A/locations/L. Public
     * so the composer files the same name publish() would work out.
     */
    public static function v4Name(BusinessLocation $location, SocialConnection $connection): string
    {
        // v1 names a location "locations/L"; v4 wants it under its account.
        $name = trim((string) $location->remote_name, '/');
        if (str_starts_with($name, 'accounts/')) {
            return $name;
        }
        $account = trim((string) ($location->account_name ?: $connection->remote_id), '/');

        return (str_starts_with($account, 'accounts/') ? $account : 'accounts/'.$account).'/'.$name;
    }

    /** Take a post down, by the name publish() returned. */
    public function delete(SocialConnection $connection, string $name): void
    {
        if (! preg_match('#^accounts/[^/]+/locations/[^/]+/localPosts/[^/]+$#', $name)) {
            throw new SocialPublishFailed('That is not a Business Profile post id.');
        }

        $this->send($connection, fn ($http) => $http->delete(BusinessProfileOAuth::LEGACY_V4.'/'.$name));
    }

    private function location(SocialConnection $connection, PostPayload $post): string
    {
        $named = trim((string) ($post->options['gbp_location'] ?? ''), '/');
        if ($named !== '') {
            if (! preg_match('#^accounts/[^/]+/locations/[^/]+$#', $named)) {
                throw new SocialPublishFailed('That is not a Business Profile location.');
            }

            return $named;
        }

        $locations = BusinessLocation::query()->where('connection_id', $connection->getKey())->get(['remote_name', 'account_name']);
        if ($locations->count() !== 1) {
            throw new SocialPublishFailed($locations->isEmpty()
                ? 'No locations have been read for this Business Profile account yet. Open Business Profile once, then post.'
                : 'This Business Profile account has '.$locations->count().' locations. Choose which one to post to.');
        }

        return self::v4Name($locations->first(), $connection);
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(SocialConnection $connection, callable $call): Response
    {
        try {
            $response = $call(Http::withToken(GoogleToken::forConnection($connection, 'business_profile'))->timeout(self::TIMEOUT));
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Google Business Profile', 'Google did not answer.');
        } catch (RuntimeException $e) {
            throw new SocialPublishFailed($e->getMessage());
        }

        if ($response->status() === 429 || ($response->status() === 403 && str_contains((string) $response->body(), 'quota'))) {
            throw new SocialPublishFailed('Google Business Profile refused: EzBizi\'s Business Profile API quota is not approved yet.');
        }
        if ($response->failed()) {
            throw SocialPublishFailed::network('Google Business Profile',
                'HTTP '.$response->status().' '.mb_substr((string) $response->json('error.message', ''), 0, 200));
        }

        return $response;
    }
}
