<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
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
 * ── Media, and the URL it needs ─────────────────────────────────
 *
 * A container carrying media takes `image_url` or `video_url` — a PUBLIC
 * address Threads fetches from, because there is no way to hand it bytes. That
 * was refused here until 2026-09-01, when the trade was made deliberately;
 * MediaHandoff holds the reasoning and the four things that narrow it. The
 * handoff is released in a `finally` either way.
 *
 * A VIDEO container is polled before publishing, a text one is not. Threads
 * ingests video asynchronously and publishing a container that is still
 * IN_PROGRESS is refused — the same shape as Instagram, for the same reason.
 *
 * ── Its own app, its own token ──────────────────────────────────
 *
 * Threads is a separate app inside the Meta app (2132181681064840) with its own
 * id and secret, and its host is graph.threads.net rather than
 * graph.facebook.com. Signing a Threads call with the Facebook pair fails, which
 * is the bug ThreadsOAuth carried until 2026-08-28.
 */
final class ThreadsPublisher implements LinksToPosts, SocialPublisher
{
    use PostsThroughMetaGraph;

    private const API = 'https://graph.threads.net/v1.0';

    private const TIMEOUT = 30;

    private const NETWORK = 'Threads';

    /**
     * ponytail: 30 seconds, bounded by the queue rather than by Threads - the
     * worker's 60s timeout and the 90s retry_after, past which a second worker
     * takes the same target and posts it twice. InstagramPublisher carries the
     * whole reasoning and the upgrade path; they share it.
     *
     * @param  int  $pollSeconds  Zero in tests.
     * @param  int  $pollAttempts  Video only; 10 x 3s.
     */
    public function __construct(
        private readonly int $pollSeconds = 3,
        private readonly int $pollAttempts = 10,
    ) {}

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $kind = $post->kind();
        $body = trim($post->body);

        if ($kind === SocialMediaKind::Text && $body === '') {
            throw new SocialPublishFailed('A thread with no words would be an empty post. Write something.');
        }

        // Refused, never cut mid-sentence. The composer checks first; this
        // holds for posts written before it did.
        if (($why = SocialProvider::Threads->refusesLength($body)) !== null) {
            throw new SocialPublishFailed($why.' Nothing was posted.');
        }

        $token = $this->token($connection);
        $user = $connection->remote_id;
        $handoff = $kind === SocialMediaKind::Text ? null : MediaHandoff::hold($post);

        try {
            $id = $this->container($user, $token, $kind, $body, $handoff?->url);

            // Video is ingested asynchronously; publishing it too early is
            // refused. Text and images are ready as soon as the container is.
            if ($kind === SocialMediaKind::Video) {
                $this->waitFor($id, $token);
            }

            // Nothing is visible until this second call. A container left
            // unpublished is the failure that looks like success: the API said
            // 200 and the profile shows nothing.
            $published = $this->call('/'.$user.'/threads_publish', $token, ['creation_id' => $id]);

            $postId = $published['id'] ?? null;

            if (! is_string($postId) || $postId === '') {
                throw SocialPublishFailed::network(
                    'Threads',
                    'the container was made but publishing it returned nothing.',
                );
            }

            return $postId;
        } finally {
            $handoff?->release();
        }
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        return null;
    }

    private function container(string $user, string $token, SocialMediaKind $kind, string $body, ?string $url): string
    {
        $container = $this->call('/'.$user.'/threads', $token, array_filter([
            'media_type' => match ($kind) {
                SocialMediaKind::Text => 'TEXT',
                SocialMediaKind::Image => 'IMAGE',
                SocialMediaKind::Video => 'VIDEO',
            },
            'image_url' => $kind === SocialMediaKind::Image ? $url : null,
            'video_url' => $kind === SocialMediaKind::Video ? $url : null,
            // On a media post the text is the caption, and an empty one is
            // allowed. Its 500-character limit is checked in publish().
            'text' => $body === '' ? null : $body,
        ], fn ($value) => $value !== null));

        $id = $container['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('Threads', 'it accepted the post but returned no container.');
        }

        return $id;
    }

    private function waitFor(string $container, string $token): void
    {
        for ($attempt = 0; $attempt < $this->pollAttempts; $attempt++) {
            try {
                $response = Http::timeout(self::TIMEOUT)->get(self::API.'/'.$container, [
                    'fields' => 'status,error_message',
                    'access_token' => $token,
                ]);
            } catch (ConnectionException) {
                throw SocialPublishFailed::network('Threads', 'EzBizi could not reach it.');
            }

            $status = (string) ($response->json('status') ?? 'IN_PROGRESS');

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw SocialPublishFailed::network(
                    'Threads',
                    (string) ($response->json('error_message') ?: 'it could not process the video.'),
                );
            }

            if ($this->pollSeconds > 0) {
                sleep($this->pollSeconds);
            }
        }

        throw SocialPublishFailed::network(
            'Threads',
            'it was still processing the video after '
            .($this->pollAttempts * $this->pollSeconds).' seconds, so EzBizi stopped waiting. '
            .'Nothing was posted. A shorter or smaller video will go through.',
        );
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
