<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A post on the Page the connection was made for.
 *
 * ── Which token this uses, and why it matters ───────────────────
 *
 * The PAGE token, not the user token. FacebookOAuth::identify() asks
 * /me/accounts and stores each Page's own `access_token` on its own connection
 * row, so this reads the token off the row it was handed and never has to know
 * which person authorised it. Posting to a Page with a user token fails with a
 * permissions error that reads like a missing scope, which sends you looking in
 * exactly the wrong place.
 *
 * ── Two endpoints, because Facebook has two ─────────────────────
 *
 * Text goes to /feed with `message`. A photo goes to /photos with the bytes and
 * `caption` — a different field name for the same idea, which is a detail worth
 * stating once here rather than rediscovering.
 *
 * ── Three endpoints, because Facebook has three ─────────────────
 *
 * Text goes to /feed with `message`. A photo goes to /photos with the bytes and
 * `caption` — a different field name for the same idea, which is a detail worth
 * stating once here rather than rediscovering. A video goes to /videos with a
 * `file_url` and `description`, a third name for the third one.
 *
 * ── Why video is a URL when the photo is bytes ──────────────────
 *
 * Because the alternative is Facebook's resumable upload — start, transfer,
 * finish, a whole third protocol — and `file_url` is one call. The photo stays
 * as bytes because it CAN: there is no reason to expose a file, however
 * briefly, to save nothing. Video gets a MediaHandoff and gets it released in
 * a `finally`, so the window is the length of one fetch.
 */
final class FacebookPublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const API = 'https://graph.facebook.com/v21.0';

    private const TIMEOUT = 60;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $token = $this->token($connection);
        $page = $connection->remote_id;

        return match ($post->kind()) {
            SocialMediaKind::Image => $this->photo($page, $token, $post),
            SocialMediaKind::Video => $this->video($page, $token, $post),
            SocialMediaKind::Text => $this->text($page, $token, $post),
        };
    }

    /**
     * Nothing. Facebook is the one network where EzBizi sends all three kinds.
     *
     * Kept rather than dropped: the interface asks every publisher this, and a
     * publisher that answers "nothing is missing" is a different statement from
     * a publisher that was never asked.
     */
    public function cannotSend(SocialMediaKind $kind): ?string
    {
        return null;
    }

    private function text(string $page, string $token, PostPayload $post): string
    {
        $body = trim($post->body);

        if ($body === '') {
            throw new SocialPublishFailed(
                'A Facebook post with no words and no picture would be an empty post. '
                .'Write something, or attach an image.',
            );
        }

        return $this->idFrom($this->call(
            fn () => Http::timeout(self::TIMEOUT)->asForm()->post(
                self::API.'/'.$page.'/feed',
                ['message' => $body, 'access_token' => $token],
            ),
        ));
    }

    private function photo(string $page, string $token, PostPayload $post): string
    {
        // Multipart, so the bytes go straight up. Facebook also accepts a `url`,
        // which would mean publishing the tenant's media to an unauthenticated
        // public address first — the same trade PinterestPublisher refuses, and
        // refused here for the same reason.
        return $this->idFrom($this->call(
            fn () => Http::timeout(self::TIMEOUT)
                ->attach('source', $post->bytes(), 'upload'.$this->extension($post))
                ->post(self::API.'/'.$page.'/photos', [
                    'caption' => $post->body,
                    'access_token' => $token,
                ]),
        ));
    }

    private function video(string $page, string $token, PostPayload $post): string
    {
        $handoff = MediaHandoff::hold($post);

        try {
            // `description`, not `message` and not `caption`. Facebook uses a
            // different field name on all three endpoints; sending the wrong one
            // is accepted and silently drops the words, which is a post that
            // succeeds and is missing half of itself.
            return $this->idFrom($this->call(
                fn () => Http::timeout(self::TIMEOUT)->asForm()->post(self::API.'/'.$page.'/videos', [
                    'file_url' => $handoff->url,
                    'description' => $post->body,
                    'access_token' => $token,
                ]),
            ));
        } finally {
            $handoff->release();
        }
    }

    /** @param  callable(): Response  $send */
    private function call(callable $send): array
    {
        try {
            $response = $send();
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Facebook', 'EzBizi could not reach it.');
        }

        if ($response->failed()) {
            // Graph nests the readable part at error.message. Passing it through
            // beats "HTTP 400" when somebody has to decide what to change.
            throw SocialPublishFailed::network(
                'Facebook',
                (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        return (array) $response->json();
    }

    private function idFrom(array $response): string
    {
        // /feed returns `id`; /photos returns `post_id` alongside the photo's own
        // `id`. The post id is the one that identifies what a person would see.
        $id = $response['post_id'] ?? $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('Facebook', 'it created nothing and said nothing.');
        }

        return $id;
    }

    private function extension(PostPayload $post): string
    {
        return match ($post->mime()) {
            'image/png' => '.png',
            'image/webp' => '.webp',
            'image/gif' => '.gif',
            'image/heic' => '.heic',
            'image/heif' => '.heif',
            default => '.jpg',
        };
    }

    private function token(SocialConnection $connection): string
    {
        $credentials = $connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network(
                'Facebook',
                'that connection has no usable token. Reconnect the Page.',
            );
        }

        return $token;
    }

    /**
     * Read back from the network rather than built from the id: a Page post's
     * address is not derivable from its id, and a guessed link that 404s is
     * worse than none.
     */
    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        $this->call(fn () => Http::timeout(self::TIMEOUT)->delete(self::API.'/'.$remoteId, [
            'access_token' => $this->token($connection),
        ]));
    }

    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        $url = Http::timeout(10)->get(self::API.'/'.$remoteId, [
            'fields' => 'permalink_url',
            'access_token' => $this->token($connection),
        ])->json('permalink_url');

        // A video's link comes back relative ("/reel/1792…/", measured
        // 2026-10-15), which used to be thrown away; a photo's is absolute.
        if (is_string($url) && str_starts_with($url, '/')) {
            $url = 'https://www.facebook.com'.$url;
        }

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
