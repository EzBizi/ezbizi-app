<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
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
 * ── Why video is refused ────────────────────────────────────────
 *
 * Facebook takes video perfectly well, through a resumable upload that is a
 * different protocol again: start, transfer, finish. EzBizi has not built it.
 * The refusal says "not built" rather than "not accepted" because those are
 * different facts and only one of them is ours to fix.
 */
final class FacebookPublisher implements SocialPublisher
{
    private const API = 'https://graph.facebook.com/v21.0';
    private const TIMEOUT = 60;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $token = $this->token($connection);
        $page  = $connection->remote_id;

        return $post->kind() === SocialMediaKind::Image
            ? $this->photo($page, $token, $post)
            : $this->text($page, $token, $post);
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        return $kind === SocialMediaKind::Video
            ? SocialPublishFailed::notImplemented('Facebook', $kind->label())->getMessage()
            : null;
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
                    'caption'      => $post->body,
                    'access_token' => $token,
                ]),
        ));
    }

    /** @param  callable(): \Illuminate\Http\Client\Response  $send */
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
            'image/png'  => '.png',
            'image/webp' => '.webp',
            'image/gif'  => '.gif',
            default      => '.jpg',
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
}
