<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\LinkedInOAuth;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialOAuthRefused;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * A post on LinkedIn, as the member who connected (or, later, their Page).
 *
 * ── The versioned Posts API, not ugcPosts ───────────────────────
 *
 * `/rest/posts` replaced `/v2/ugcPosts`, and every call to `/rest/` must name
 * a version in `LinkedIn-Version`. There is no "latest" default: a missing
 * header is refused, and so is a version past its sunset. Versions live for at
 * least a year, so VERSION below is a date that has to be moved forward at
 * least once a year — when it lapses, every LinkedIn post fails with a
 * sentence about the version and nothing else is wrong.
 *
 * ── Media is uploaded first, then referenced ────────────────────
 *
 * A post never carries bytes. An image is registered (`initializeUpload`),
 * PUT to the address LinkedIn hands back, and the post names the
 * `urn:li:image:…`. A video is the same in parts: LinkedIn decides the parts
 * (4 MB each) and returns one upload address per part; each PUT answers with
 * an ETag, and `finalizeUpload` needs those ETags back in the same order. A
 * skipped or reordered ETag is refused at finalize with no hint which part.
 *
 * ── Who the author is ───────────────────────────────────────────
 *
 * LinkedInOAuth::identify() stores the OpenID `sub` as remote_id. That IS the
 * member's person id, so the author is `urn:li:person:{sub}`. A remote_id that
 * is already a URN (an organization connection, the day Community Management
 * is approved and Connect lists Pages) is used as it stands.
 */
final class LinkedInPublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const API = 'https://api.linkedin.com/rest';

    /** Marketing September 2026. Supported until at least September 2027. */
    public const VERSION = '202609';

    private const TIMEOUT = 30;

    private const UPLOAD_TIMEOUT = 120;

    /** LinkedIn's commentary limit, in characters. */
    private const MAX_COMMENTARY = 3000;

    /** The Images API takes these three and nothing else. */
    private const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif'];

    /** The Videos API: MP4, 75 KB to 500 MB. */
    private const VIDEO_MIN_BYTES = 75 * 1024;

    private const VIDEO_MAX_BYTES = 500 * 1024 * 1024;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $kind = $post->kind();
        $body = trim($post->body);

        if ($kind === SocialMediaKind::Text && $body === '') {
            throw new SocialPublishFailed('A LinkedIn post with no words would be an empty post. Write something.');
        }

        $token = $this->token($connection);
        $author = self::author($connection);

        $media = match ($kind) {
            SocialMediaKind::Text => null,
            SocialMediaKind::Image => $this->uploadImage($connection, $token, $author, $post),
            SocialMediaKind::Video => $this->uploadVideo($connection, $token, $author, $post),
        };

        $payload = [
            'author' => $author,
            'commentary' => self::littleText(mb_substr($body, 0, self::MAX_COMMENTARY)),
            'visibility' => 'PUBLIC',
            'distribution' => [
                'feedDistribution' => 'MAIN_FEED',
                'targetEntities' => [],
                'thirdPartyDistributionChannels' => [],
            ],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        if ($media !== null) {
            $payload['content'] = ['media' => array_filter([
                'id' => $media,
                'title' => $kind === SocialMediaKind::Video && $post->title !== null && trim($post->title) !== ''
                    ? mb_substr(trim($post->title), 0, 200)
                    : null,
            ], fn ($v) => $v !== null)];
        }

        $response = $this->send($connection, fn () => $this->api($token)->post(self::API.'/posts', $payload));

        // The post id is in a HEADER, not the body — a 201 with an empty body
        // is the success case. Reading the body finds nothing and would call a
        // published post a failure.
        $id = $response->header('x-restli-id');

        if ($id === '') {
            throw SocialPublishFailed::network('LinkedIn', 'it accepted the post but did not say what it was called.');
        }

        return $id;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        // All three are built. The image FORMAT and video container limits
        // are checked in publish(), because a kind cannot say whether a
        // picture is a .webp.
        return null;
    }

    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        $token = $this->token($connection);

        // The URN is URL-encoded in this path, unlike the share link.
        $this->send($connection, fn () => $this->api($token)
            ->delete(self::API.'/posts/'.rawurlencode($remoteId)), 'deleting the post');
    }

    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        // The URN goes in the path as it is. LinkedIn's own share links take
        // `urn:li:share:…` and `urn:li:ugcPost:…` unencoded.
        return str_starts_with($remoteId, 'urn:li:')
            ? 'https://www.linkedin.com/feed/update/'.$remoteId.'/'
            : null;
    }

    /**
     * Who the post is by: a person URN from the stored member id, or the URN
     * as stored when it already is one.
     */
    public static function author(SocialConnection $connection): string
    {
        $id = trim((string) $connection->remote_id);

        if ($id === '') {
            throw SocialPublishFailed::network('LinkedIn', 'that connection does not say whose account it is. Reconnect it.');
        }

        return str_starts_with($id, 'urn:li:') ? $id : 'urn:li:person:'.$id;
    }

    /**
     * The words, escaped for LinkedIn's "little" text format.
     *
     * `commentary` is not plain text. `@[Name](urn)` is a mention, `{hashtag|…}`
     * a template, and every reserved character has to be backslashed even when
     * it is not part of one (LinkedIn's docs say so in those words). An
     * unescaped `(` is widely reported to cut the post off at that character
     * rather than be refused, so the failure would be a half-written post
     * that looks sent.
     *
     * The one exception made here is `#word`, which is left alone, whole,
     * because it is how somebody writes a hashtag and LinkedIn links it. A `#`
     * that is not followed by a letter or digit ("# 1", "#!") is escaped like
     * the rest. (*SKIP)(*FAIL) is what steps over the hashtag: it matches the
     * whole tag, then refuses it, so nothing inside it is touched.
     */
    public static function littleText(string $text): string
    {
        return (string) preg_replace(
            '/#[\p{L}\p{N}]+(*SKIP)(*FAIL)|[\\\\|{}@\[\]()<>#*_~]/u',
            '\\\\$0',
            $text,
        );
    }

    private function uploadImage(SocialConnection $connection, string $token, string $owner, PostPayload $post): string
    {
        $mime = $post->mime();

        if (! in_array($mime, self::IMAGE_TYPES, true)) {
            throw new SocialPublishFailed(
                'LinkedIn takes pictures as JPG, PNG or GIF only. Save this one as a JPG and send it again. Nothing was posted.'
            );
        }

        $init = $this->send($connection, fn () => $this->api($token)->post(
            self::API.'/images?action=initializeUpload',
            ['initializeUploadRequest' => ['owner' => $owner]],
        ));

        $url = $init->json('value.uploadUrl');
        $image = $init->json('value.image');

        if (! is_string($url) || ! is_string($image) || $url === '' || $image === '') {
            throw SocialPublishFailed::network('LinkedIn', 'it did not hand back an address for the picture.');
        }

        // The image upload address takes the member's token as well; the
        // video ones are pre-signed and do not.
        $this->send($connection, fn () => Http::withToken($token)
            ->timeout(self::UPLOAD_TIMEOUT)
            ->withBody($post->bytes(), 'application/octet-stream')
            ->put($url), 'the picture upload');

        return $image;
    }

    private function uploadVideo(SocialConnection $connection, string $token, string $owner, PostPayload $post): string
    {
        if ($post->mime() !== 'video/mp4') {
            throw new SocialPublishFailed(
                'LinkedIn takes video as MP4 only. Export this one as an MP4 and send it again. Nothing was posted.'
            );
        }

        $bytes = $post->bytes();
        $size = strlen($bytes);

        if ($size < self::VIDEO_MIN_BYTES || $size > self::VIDEO_MAX_BYTES) {
            throw new SocialPublishFailed(
                'LinkedIn takes videos between 75 KB and 500 MB, and this one is '
                .number_format($size / 1024 / 1024, 1).' MB. Nothing was posted.'
            );
        }

        $init = $this->send($connection, fn () => $this->api($token)->post(
            self::API.'/videos?action=initializeUpload',
            ['initializeUploadRequest' => [
                'owner' => $owner,
                'fileSizeBytes' => $size,
                'uploadCaptions' => false,
                'uploadThumbnail' => false,
            ]],
        ));

        $video = $init->json('value.video');
        $parts = $init->json('value.uploadInstructions');

        if (! is_string($video) || $video === '' || ! is_array($parts) || $parts === []) {
            throw SocialPublishFailed::network('LinkedIn', 'it did not hand back anywhere to upload the video.');
        }

        // Upload exactly the ranges LinkedIn chose. Its byte ranges are
        // inclusive at both ends, hence the +1.
        $etags = [];

        foreach ($parts as $part) {
            $first = (int) ($part['firstByte'] ?? -1);
            $last = (int) ($part['lastByte'] ?? -1);
            $url = (string) ($part['uploadUrl'] ?? '');

            if ($url === '' || $first < 0 || $last < $first) {
                throw SocialPublishFailed::network('LinkedIn', 'its upload instructions for the video did not make sense.');
            }

            $response = $this->send($connection, fn () => Http::timeout(self::UPLOAD_TIMEOUT)
                ->withBody(substr($bytes, $first, $last - $first + 1), 'application/octet-stream')
                ->put($url), 'the video upload');

            $etag = $response->header('ETag');

            if ($etag === '') {
                throw SocialPublishFailed::network('LinkedIn', 'a piece of the video went up without a receipt, so it cannot be finished.');
            }

            $etags[] = $etag;
        }

        $this->send($connection, fn () => $this->api($token)->post(
            self::API.'/videos?action=finalizeUpload',
            ['finalizeUploadRequest' => [
                'video' => $video,
                'uploadToken' => (string) ($init->json('value.uploadToken') ?? ''),
                'uploadedPartIds' => $etags,
            ]],
        ));

        // Not polled for AVAILABLE before posting. `GET /rest/videos` needs a
        // read permission a w_member_social token does not carry, and LinkedIn
        // accepts a post naming a video that is still processing — the post
        // appears when the video does.
        return $video;
    }

    private function api(string $token): PendingRequest
    {
        return Http::withToken($token)
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'LinkedIn-Version' => self::VERSION,
                'X-Restli-Protocol-Version' => '2.0.0',
            ]);
    }

    /**
     * Make one call and turn every way it can fail into a sentence.
     *
     * A 401 is the one answer that is about the CONNECTION rather than this
     * post: LinkedIn has stopped honouring the token (revoked, expired early,
     * password changed). That, and only that, marks the row for reconnecting.
     * A 403 is usually a missing product or Page role and a 4xx is usually the
     * post; marking those broken would send somebody to reconnect an account
     * that is fine.
     *
     * @param  callable(): Response  $call
     */
    private function send(SocialConnection $connection, callable $call, ?string $what = null): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('LinkedIn', $what === null
                ? 'EzBizi could not reach it.'
                : $what.' could not reach it.');
        }

        if ($response->status() === Status::HTTP_UNAUTHORIZED) {
            $connection->forceFill([
                'status' => SocialConnection::STATUS_BROKEN,
                'status_reason' => 'LinkedIn no longer accepts this connection. It needs connecting again.',
            ])->save();

            throw new SocialPublishFailed(
                'LinkedIn no longer accepts this connection, so nothing was posted. Reconnect the account and send it again.'
            );
        }

        if ($response->failed()) {
            // LinkedIn's `message` names the field ("commentary length exceeds
            // the allowed maximum"). Worth more than a status code.
            $message = $response->json('message');
            $why = is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status().'.';

            throw SocialPublishFailed::network('LinkedIn', $what === null ? $why : $what.' was rejected: '.$why);
        }

        return $response;
    }

    /**
     * A live access token.
     *
     * LinkedIn's last 60 days. Renewing one needs a refresh token, which
     * LinkedIn gives only to approved partners — so most connections have
     * nothing to renew with, and when theirs runs out the only fix is to
     * connect again. Said in those words, and the row is marked broken: an
     * expired token with nothing to renew it is as final as a refusal, and an
     * unmarked row kept the card green until a post failed (2026-11-12).
     *
     * When a refresh token IS held, it is renewed under a per-connection lock
     * and re-read inside it, the TikTok pattern, so two workers never both
     * spend it.
     */
    public function token(SocialConnection $connection): string
    {
        $credentials = (array) ($connection->getAttribute('credentials') ?? []);
        $expires = $credentials['expires_at'] ?? null;
        $expiry = is_string($expires) ? Carbon::parse($expires) : null;

        // No recorded expiry means nothing to go on; the stored token is tried.
        if ($expiry === null || $expiry->isAfter(now()->addMinutes(10))) {
            return $this->stored($credentials);
        }

        if (($credentials['refresh_token'] ?? '') === '') {
            if ($expiry->isFuture()) {
                return $this->stored($credentials);
            }

            // Marked, so the card says it instead of staying green until a
            // post fails (2026-11-12): the token is gone and only Connect fixes it.
            $connection->forceFill([
                'status' => SocialConnection::STATUS_BROKEN,
                'status_reason' => 'LinkedIn’s permission ran out after 60 days. It needs connecting again.',
            ])->save();

            throw new SocialPublishFailed(
                'LinkedIn’s permission for this account ran out — LinkedIn makes it last 60 days. '
                .'Reconnect the account and send it again. Nothing was posted.'
            );
        }

        return Cache::lock('linkedin-refresh:'.$connection->getKey(), 30)->block(15, function () use ($connection) {
            $connection->refresh();
            $credentials = (array) ($connection->getAttribute('credentials') ?? []);
            $expires = $credentials['expires_at'] ?? null;

            if (is_string($expires) && Carbon::parse($expires)->isAfter(now()->addMinutes(10))) {
                return $this->stored($credentials); // another worker renewed it while we waited
            }

            try {
                $grant = app(LinkedInOAuth::class)->refresh((string) $credentials['refresh_token']);
            } catch (SocialOAuthRefused $e) {
                if ($e->status === Status::HTTP_CONFLICT) {
                    $connection->forceFill([
                        'status' => SocialConnection::STATUS_BROKEN,
                        'status_reason' => 'LinkedIn no longer accepts this connection. It needs connecting again.',
                    ])->save();
                }

                throw new SocialPublishFailed($e->getMessage());
            }

            $credentials['access_token'] = $grant->accessToken;
            $credentials['refresh_token'] = $grant->refreshToken;
            $credentials['expires_at'] = now()->addSeconds($grant->expiresIn ?? 60 * 86400)->toIso8601String();
            $connection->setAttribute('credentials', $credentials);
            $connection->save();

            return $grant->accessToken;
        });
    }

    /** @param  array<string, mixed>  $credentials */
    private function stored(array $credentials): string
    {
        $token = $credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network('LinkedIn', 'that connection has no usable token. Reconnect it.');
        }

        return $token;
    }
}
