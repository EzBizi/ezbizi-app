<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A thread on the connected Threads profile.
 *
 * ── Two calls, always ───────────────────────────────────────────
 *
 * Threads does not have a "post this" endpoint. You create a container, then
 * publish the container by id. Both steps are required even for plain text, so
 * this is two round trips rather than one and there is no shorter path.
 *
 * ── Why only text, for now ──────────────────────────────────────
 *
 * A container carrying media takes `image_url` or `video_url` — a PUBLIC
 * address Threads fetches from. There is no way to hand it bytes. Serving a
 * tenant's media from an unauthenticated URL is a privacy decision nobody has
 * made, and PinterestPublisher refuses the same trade for the same reason, so
 * media is refused here until that decision exists.
 *
 * Text has no such requirement, which is why it works and is worth having: it
 * is enough to prove the connection, the token and the publish path end to end.
 *
 * ── Its own app, its own token ──────────────────────────────────
 *
 * Threads is a separate app inside the Meta app (2132181681064840) with its own
 * id and secret, and its host is graph.threads.net rather than
 * graph.facebook.com. Signing a Threads call with the Facebook pair fails, which
 * is the bug ThreadsOAuth carried until 2026-08-28.
 */
final class ThreadsPublisher implements SocialPublisher
{
    private const API = 'https://graph.threads.net/v1.0';
    private const TIMEOUT = 30;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $body = trim($post->body);

        if ($body === '') {
            throw new SocialPublishFailed('A thread with no words would be an empty post. Write something.');
        }

        $token = $this->token($connection);
        $user  = $connection->remote_id;

        // Threads counts by characters and stops at 500.
        $container = $this->call('/'.$user.'/threads', $token, [
            'media_type' => 'TEXT',
            'text'       => mb_substr($body, 0, 500),
        ]);

        $id = $container['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('Threads', 'it accepted the text but returned no container.');
        }

        // Nothing is visible until this second call. A container left unpublished
        // is the failure that looks like success: the API said 200 and the
        // profile shows nothing.
        $published = $this->call('/'.$user.'/threads_publish', $token, ['creation_id' => $id]);

        $postId = $published['id'] ?? null;

        if (! is_string($postId) || $postId === '') {
            throw SocialPublishFailed::network('Threads', 'the container was made but publishing it returned nothing.');
        }

        return $postId;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        return $kind === SocialMediaKind::Text
            ? null
            : SocialPublishFailed::notImplemented('Threads', $kind->label())->getMessage();
    }

    /** @return array<string, mixed> */
    private function call(string $path, string $token, array $payload): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->asForm()
                ->post(self::API.$path, $payload + ['access_token' => $token]);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Threads', 'EzBizi could not reach it.');
        }

        if ($response->failed()) {
            throw SocialPublishFailed::network(
                'Threads',
                (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        return (array) $response->json();
    }

    private function token(SocialConnection $connection): string
    {
        $credentials = $connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network(
                'Threads',
                'that connection has no usable token. Reconnect the profile.',
            );
        }

        return $token;
    }
}
