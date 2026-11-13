<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialOAuthGrant;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\TikTokOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * A video, uploaded to TikTok in three calls.
 *
 *   init  — declare the size and how it will arrive, get an upload url
 *   PUT   — the bytes, with a Content-Range even when there is one chunk
 *   fetch — ask what became of it, because init only queues the work
 *
 * ── Why FILE_UPLOAD and not PULL_FROM_URL ───────────────────────
 *
 * PULL_FROM_URL needs the video at a publicly reachable address on a domain
 * verified with TikTok. That means publishing tenant media to an unauthenticated
 * URL. Pushing the bytes keeps the file where it is.
 *
 * ── Two ways in, and why both exist ─────────────────────────────
 *
 * Direct Post puts the video on the profile. Inbox puts it in the creator's
 * drafts for them to finish and post themselves. Direct Post needs the app to
 * have passed TikTok's audit; the inbox needs only video.upload and works from
 * the first day. Until the audit clears, the inbox is the difference between an
 * integration that demonstrably works and one nobody can try.
 */
final class TikTokPublisher implements SocialPublisher
{
    private const API = 'https://open.tiktokapis.com/v2';
    private const TIMEOUT = 120;

    public function __construct(
        private readonly bool $toDrafts = false,
    ) {}

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $token = $this->token($connection);
        $bytes = $post->bytes();
        $size  = strlen($bytes);

        $init = $this->toDrafts
            ? $this->initDraft($token, $size)
            : $this->initDirect($token, $post, $size);

        $uploadUrl = $init['data']['upload_url'] ?? null;
        $publishId = $init['data']['publish_id'] ?? null;

        if (! is_string($uploadUrl) || ! is_string($publishId)) {
            throw SocialPublishFailed::network('TikTok', 'it did not hand back an upload address.');
        }

        $this->upload($uploadUrl, $bytes, $post->mime());

        return $publishId;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        // TikTok learned photos; this publisher has not. Same distinction as
        // Pinterest's — the network's limit and ours are different sentences.
        return $kind === SocialMediaKind::Video
            ? null
            : SocialPublishFailed::notImplemented('TikTok', $kind->label())->getMessage();
    }

    /**
     * Straight to the profile.
     *
     * ── Why creator_info first ──────────────────────────────────
     *
     * Not optional politeness. TikTok's content-sharing guidelines require the
     * app to ask what the creator allows and post within it, and skipping the
     * call is answered by init with "Please review our integration guidelines" —
     * which names no field and reads like the video was rejected.
     *
     * @return array<string, mixed>
     */
    private function initDirect(string $token, PostPayload $post, int $size): array
    {
        $creator = $this->creatorInfo($token);
        $chosen = $post->options;
        $privacy = (string) ($chosen['privacy_level'] ?? '');

        // Every answer comes from the person in the composer; nothing here
        // picks for them. A post without them (queued before the composer
        // asked) is refused rather than published at a guessed visibility.
        if (! in_array($privacy, (array) ($creator['privacy_level_options'] ?? []), strict: true)) {
            throw new SocialPublishFailed(
                'Choose who can see this TikTok post, then send it again. Nothing was posted.'
            );
        }

        $branded = (bool) ($chosen['branded_content'] ?? false);

        if ($branded && $privacy === 'SELF_ONLY') {
            throw new SocialPublishFailed('Branded content cannot be private on TikTok. Nothing was posted.');
        }

        return $this->call($token, '/post/publish/video/init/', [
            'post_info' => [
                'title'           => mb_substr($post->body !== '' ? $post->body : ($post->title ?? ''), 0, 2200),
                'privacy_level'   => $privacy,
                // Off unless chosen, and off whatever was chosen when the
                // account itself has switched the interaction off.
                'disable_comment' => ! ($chosen['allow_comment'] ?? false) || (bool) ($creator['comment_disabled'] ?? false),
                'disable_duet'    => ! ($chosen['allow_duet'] ?? false) || (bool) ($creator['duet_disabled'] ?? false),
                'disable_stitch'  => ! ($chosen['allow_stitch'] ?? false) || (bool) ($creator['stitch_disabled'] ?? false),
                'brand_content_toggle' => $branded,
                'brand_organic_toggle' => (bool) ($chosen['your_brand'] ?? false),
            ],
            'source_info' => $this->source($size),
        ]);
    }

    /**
     * Into the creator's drafts.
     *
     * No post_info at all: the caption and the privacy are chosen by the person
     * when they open the draft in TikTok. Sending post_info here is refused.
     *
     * @return array<string, mixed>
     */
    private function initDraft(string $token, int $size): array
    {
        return $this->call($token, '/post/publish/inbox/video/init/', [
            'source_info' => $this->source($size),
        ]);
    }

    /** @return array<string, mixed> */
    private function source(int $size): array
    {
        return [
            'source'     => 'FILE_UPLOAD',
            'video_size' => $size,
            // One chunk. TikTok wants chunks between 5MB and 64MB, and a single
            // chunk may exceed that only when it is the whole file — which it is.
            'chunk_size'        => $size,
            'total_chunk_count' => 1,
        ];
    }

    private function upload(string $url, string $bytes, string $mime): void
    {
        $size = strlen($bytes);

        try {
            $response = Http::withHeaders([
                'Content-Type' => $mime,
                // Required even for a single chunk, and the range is inclusive:
                // the last byte is size-1, not size. Off by one here is a 400
                // with no explanation.
                'Content-Range' => sprintf('bytes 0-%d/%d', $size - 1, $size),
            ])->timeout(self::TIMEOUT)->withBody($bytes, $mime)->put($url);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('TikTok', 'the upload could not reach it.');
        }

        if ($response->failed()) {
            throw SocialPublishFailed::network('TikTok', 'the upload was rejected, HTTP '.$response->status().'.');
        }
    }

    /**
     * The account's own name, avatar and what it permits.
     *
     * Public, because a composer needs exactly this: who the account is, and
     * which privacy levels to draw.
     *
     * @return array<string, mixed>
     */
    public function creatorInfo(string $token): array
    {
        // An empty JSON OBJECT. `->post($url)` with no data sends `[]`, and
        // TikTok answers "The request parameter type is incorrect" — an array
        // and an object are not the same thing to it.
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->withBody('{}', 'application/json')
                ->post(self::API.'/post/publish/creator_info/query/');
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('TikTok', 'EzBizi could not reach it.');
        }

        $body = (array) $response->json();

        if ($response->failed() || (($body['error']['code'] ?? null) !== 'ok')) {
            throw SocialPublishFailed::network('TikTok', (string) (
                $body['error']['message'] ?? 'it would not say what this account allows.'
            ));
        }

        return (array) ($body['data'] ?? []);
    }

    /**
     * Where a publish got to. TikTok processes after the bytes land.
     *
     * @return array<string, mixed>
     */
    public function status(SocialConnection $connection, string $publishId): array
    {
        return (array) ($this->call($this->token($connection), '/post/publish/status/fetch/', [
            'publish_id' => $publishId,
        ])['data'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $token, string $path, array $payload): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->asJson()
                ->post(self::API.$path, $payload);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('TikTok', 'EzBizi could not reach it.');
        }

        $body = (array) $response->json();

        // TikTok answers 200 with an error inside, so the status code alone is
        // not the question. `error.code` is "ok" when it worked.
        $code = $body['error']['code'] ?? null;

        if ($response->failed() || ($code !== null && $code !== 'ok')) {
            throw SocialPublishFailed::network('TikTok', (string) (
                $body['error']['message'] ?? 'HTTP '.$response->status()
            ));
        }

        return $body;
    }

    /**
     * A live access token, renewed first when the stored one is (nearly) dead.
     *
     * TikTok access tokens last 24 hours. Renewed under a per-connection lock
     * and re-read inside it, because TikTok may rotate the refresh token and a
     * second worker replaying the old one would be refused.
     */
    public function token(SocialConnection $connection): string
    {
        $credentials = (array) ($connection->getAttribute('credentials') ?? []);
        $expires = $credentials['expires_at'] ?? null;

        // No recorded expiry means nothing to go on; the stored token is tried.
        if (! is_string($expires) || Carbon::parse($expires)->isAfter(now()->addMinutes(10))
            || ($credentials['refresh_token'] ?? '') === '') {
            return $this->stored($credentials);
        }

        return Cache::lock('tiktok-refresh:'.$connection->getKey(), 30)->block(15, function () use ($connection) {
            $connection->refresh();
            $credentials = (array) ($connection->getAttribute('credentials') ?? []);
            $expires = $credentials['expires_at'] ?? null;

            if (is_string($expires) && Carbon::parse($expires)->isAfter(now()->addMinutes(10))) {
                return $this->stored($credentials); // another worker renewed it while we waited
            }

            try {
                $grant = app(TikTokOAuth::class)->refresh((string) $credentials['refresh_token']);
            } catch (SocialOAuthRefused $e) {
                if ($e->status === Response::HTTP_CONFLICT) {
                    $connection->forceFill([
                        'status' => SocialConnection::STATUS_BROKEN,
                        'status_reason' => 'TikTok no longer accepts this connection. It needs connecting again.',
                    ])->save();
                }

                throw new SocialPublishFailed($e->getMessage());
            }

            $credentials['access_token'] = $grant->accessToken;
            $credentials['refresh_token'] = $grant->refreshToken;
            $credentials['expires_at'] = now()->addSeconds($grant->expiresIn ?? 86400)->toIso8601String();
            $connection->setAttribute('credentials', $credentials);
            $connection->setAttribute('avatar_url', $this->freshAvatar($grant, $connection));
            $connection->save();

            return $grant->accessToken;
        });
    }

    /**
     * TikTok's avatar URL is signed and expires, so a renewed token re-reads it.
     * Best effort: a refused read keeps the stored one rather than failing the post.
     */
    private function freshAvatar(SocialOAuthGrant $grant, SocialConnection $connection): ?string
    {
        try {
            return app(TikTokOAuth::class)->identify($grant)[0]->avatarUrl ?? $connection->avatar_url;
        } catch (SocialOAuthRefused) {
            return $connection->avatar_url;
        }
    }

    /** @param  array<string, mixed>  $credentials */
    private function stored(array $credentials): string
    {
        $token = $credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network('TikTok', 'that connection has no usable token. Reconnect it.');
        }

        return $token;
    }
}
