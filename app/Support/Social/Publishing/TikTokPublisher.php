<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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

    public function publish(SocialConnection $connection, SocialPost $post): string
    {
        $kind = $post->kind();

        if ($kind !== SocialMediaKind::Video) {
            throw SocialPublishFailed::notImplemented('TikTok', $kind->label());
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
    private function initDirect(string $token, SocialPost $post, int $size): array
    {
        $creator = $this->creatorInfo($token);

        return $this->call($token, '/post/publish/video/init/', [
            'post_info' => [
                'title'           => mb_substr($post->body !== '' ? $post->body : ($post->title ?? 'EzBizi'), 0, 2200),
                'privacy_level'   => $this->privacyFor($creator),
                'disable_comment' => false,
                'disable_duet'    => false,
                'disable_stitch'  => false,
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
     * The most private level this creator offers.
     *
     * Private on purpose. An unaudited app may only post SELF_ONLY, and until a
     * composer exists to ask a person what they want, anything more public would
     * be EzBizi choosing to publish on their behalf.
     *
     * @param  array<string, mixed>  $creator
     */
    private function privacyFor(array $creator): string
    {
        $offered = (array) ($creator['privacy_level_options'] ?? []);

        foreach (['SELF_ONLY', 'MUTUAL_FOLLOW_FRIENDS', 'PUBLIC_TO_EVERYONE'] as $level) {
            if (in_array($level, $offered, strict: true)) {
                return $level;
            }
        }

        throw SocialPublishFailed::network(
            'TikTok',
            'that account offers no privacy level EzBizi can post at.',
        );
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

    private function token(SocialConnection $connection): string
    {
        $credentials = $connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network('TikTok', 'that connection has no usable token. Reconnect it.');
        }

        return $token;
    }
}
