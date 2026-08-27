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
 * ── Why it is private until the app is audited ──────────────────
 *
 * An unaudited app may only post SELF_ONLY, and TikTok rejects anything else
 * with an error that reads like a bug in the caller. So this asks for SELF_ONLY
 * and says so, rather than letting somebody discover it from a 400.
 */
final class TikTokPublisher implements SocialPublisher
{
    private const API = 'https://open.tiktokapis.com/v2';
    private const TIMEOUT = 120;

    public function publish(SocialConnection $connection, SocialPost $post): string
    {
        $kind = $post->kind();

        if ($kind !== SocialMediaKind::Video) {
            throw SocialPublishFailed::notImplemented('TikTok', $kind->label());
        }

        $token = $this->token($connection);
        $bytes = $post->bytes();
        $size  = strlen($bytes);

        $init = $this->call($token, '/post/publish/video/init/', [
            'post_info' => [
                'title'          => mb_substr($post->body !== '' ? $post->body : ($post->title ?? 'EzBizi'), 0, 2200),
                'privacy_level'  => 'SELF_ONLY',
                'disable_comment' => false,
                'disable_duet'    => false,
                'disable_stitch'  => false,
            ],
            'source_info' => [
                'source'            => 'FILE_UPLOAD',
                'video_size'        => $size,
                // One chunk. TikTok wants chunks between 5MB and 64MB, and a
                // single chunk is allowed to exceed that only when it is the
                // whole file — which it is here.
                'chunk_size'        => $size,
                'total_chunk_count' => 1,
            ],
        ]);

        $uploadUrl = $init['data']['upload_url'] ?? null;
        $publishId = $init['data']['publish_id'] ?? null;

        if (! is_string($uploadUrl) || ! is_string($publishId)) {
            throw SocialPublishFailed::network('TikTok', 'it did not hand back an upload address.');
        }

        $this->upload($uploadUrl, $bytes, $post->mime());

        return $publishId;
    }

    private function upload(string $url, string $bytes, string $mime): void
    {
        $size = strlen($bytes);

        try {
            $response = Http::withHeaders([
                'Content-Type'  => $mime,
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

    /** Where a publish got to. TikTok processes after the bytes land. */
    public function status(SocialConnection $connection, string $publishId): array
    {
        return (array) ($this->call($this->token($connection), '/post/publish/status/fetch/', [
            'publish_id' => $publishId,
        ])['data'] ?? []);
    }

    /** @return array<string, mixed> */
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
