<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A post on the connected Instagram professional account.
 *
 * ── Three calls, and the middle one is a wait ───────────────────
 *
 * Create a container, wait for Instagram to finish ingesting it, publish it.
 * The wait is not a nicety: publishing a container that is still IN_PROGRESS is
 * refused, and for a reel the ingestion is a real transcode that takes as long
 * as it takes. A publisher that skipped the poll would work on every photo and
 * fail on most videos, which is the worst possible distribution of a bug.
 *
 * ── Where the file goes ─────────────────────────────────────────
 *
 * Instagram has no upload endpoint. None: not multipart, not base64, not a
 * chunked PUT. A container is made from a URL that Instagram FETCHES, so the
 * file has to be reachable from the internet for the length of that fetch.
 * MediaHandoff is that, deliberately and narrowly, and the reasoning for
 * accepting the trade is written there rather than repeated here.
 *
 * The handoff is released in a `finally`, so a refused post does not leave a
 * tenant's photo on a public URL because Instagram disliked the aspect ratio.
 *
 * ── Which token ─────────────────────────────────────────────────
 *
 * The Instagram user token from Instagram Login, against graph.instagram.com.
 * NOT a Page token against graph.facebook.com — that is the OTHER Instagram
 * API, the one reached through Facebook Login, and EzBizi does not use it.
 * InstagramOAuth is the authority on which of the two this is.
 *
 * ── Why there is no text-only path ──────────────────────────────
 *
 * Instagram has no such post. That is the network's rule rather than a gap
 * here, and SocialProvider::refuses() already says so in the composer's words
 * before a row is ever written; this repeats it only so publish() cannot be
 * called into a bad state from somewhere else.
 */
final class InstagramPublisher implements LinksToPosts, SocialPublisher
{
    use PostsThroughMetaGraph;

    private const API = 'https://graph.instagram.com/v21.0';

    private const TIMEOUT = 30;

    private const NETWORK = 'Instagram';

    /** Instagram truncates past this; better to cut it ourselves than be surprised. */
    private const CAPTION = 2200;

    /**
     * How long this may wait, and why it is not longer.
     *
     * 36 seconds in THIS job, because the QUEUE says so and not because Instagram
     * does. Past that the post is handed to FinishInstagramPost (2026-10-15:
     * Rodrigo's reel failed at exactly this ceiling). The worker runs with no --timeout (so 60s) and the database queue's
     * retry_after is 90s, so a job that runs past either one is killed, and past
     * 90s a SECOND worker picks the same target up and posts it AGAIN. Double
     * posting to a customer's real audience is the one failure PublishSocialTarget
     * is built entirely to prevent, so the wait fits inside the smaller number
     * rather than the queue being widened to suit it.
     *
     * Short reels — the length anyone posts from a phone — finish well inside
     * this. A long one will time out and say so, having published nothing.
     *
     * Longer videos are not refused: StillProcessing hands the container to a
     * delayed job, so the wait is bounded by that job's ten minutes and no
     * worker is held open.
     *
     * @param  int  $pollSeconds  Zero in tests.
     * @param  int  $pollAttempts  12 x 3s.
     */
    public function __construct(
        private readonly int $pollSeconds = 3,
        private readonly int $pollAttempts = 12,
    ) {}

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $token = $this->token($connection);
        $user = $connection->remote_id;

        $handoff = MediaHandoff::hold($post);
        $handedOn = false;

        try {
            $container = $this->container($user, $token, $post, $handoff->url);

            if (! $this->waitFor($container, $token)) {
                // Still ingesting: FinishInstagramPost carries on from a delayed
                // job, and keeps the file fetchable until it is done.
                $handedOn = true;

                throw new StillProcessing($container, $handoff->handle);
            }

            return $this->publishContainer($user, $token, $container);
        } finally {
            // Before the exception leaves, not after. A refused post is exactly
            // when a tenant's photo must stop being fetchable.
            if (! $handedOn) {
                $handoff->release();
            }
        }
    }

    /**
     * One look at a container handed on by StillProcessing: its post id once
     * FINISHED and published, null while Instagram is still working, and a
     * SocialPublishFailed when Instagram has refused it.
     */
    public function finish(SocialConnection $connection, string $container): ?string
    {
        $token = $this->token($connection);
        $status = $this->status($container, $token);

        if ($status['code'] === 'ERROR' || $status['code'] === 'EXPIRED') {
            throw SocialPublishFailed::network('Instagram', $status['because']);
        }

        return $status['code'] === 'FINISHED'
            ? $this->publishContainer($connection->remote_id, $token, $container)
            : null;
    }

    private function publishContainer(string $user, string $token, string $container): string
    {
        $published = $this->call('/'.$user.'/media_publish', $token, ['creation_id' => $container]);
        $id = $published['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network(
                'Instagram',
                'the post was prepared but publishing it returned nothing.',
            );
        }

        return $id;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        return $kind === SocialMediaKind::Text
            ? 'Instagram does not post words on their own. Attach a picture or a video.'
            : null;
    }

    private function container(string $user, string $token, PostPayload $post, string $url): string
    {
        $video = $post->kind() === SocialMediaKind::Video;

        $response = $this->call('/'.$user.'/media', $token, array_filter([
            // REELS, not VIDEO. Instagram retired the plain in-feed video
            // container — every video posted through the API is a reel now, and
            // media_type=VIDEO is refused with a message about the media rather
            // than about the parameter, which sends you inspecting the file.
            'media_type' => $video ? 'REELS' : null,
            'image_url' => $video ? null : $url,
            'video_url' => $video ? $url : null,
            'caption' => mb_substr($post->body, 0, self::CAPTION),
        ], fn ($value) => $value !== null));

        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('Instagram', 'it accepted the media but returned no container.');
        }

        return $id;
    }

    /**
     * Block until Instagram has finished ingesting, or say why it will not.
     *
     * FINISHED is the only state worth publishing. ERROR and EXPIRED are both
     * final and both carry a reason worth passing on — "the video is too long"
     * beats a timeout, which is what a caller would otherwise be told about a
     * file Instagram rejected in the first two seconds.
     */
    private function waitFor(string $container, string $token): bool
    {
        for ($attempt = 0; $attempt < $this->pollAttempts; $attempt++) {
            $status = $this->status($container, $token);

            if ($status['code'] === 'FINISHED') {
                return true;
            }

            if ($status['code'] === 'ERROR' || $status['code'] === 'EXPIRED') {
                throw SocialPublishFailed::network('Instagram', $status['because']);
            }

            if ($this->pollSeconds > 0) {
                sleep($this->pollSeconds);
            }
        }

        return false;
    }

    /** @return array{code: string, because: string} */
    private function status(string $container, string $token): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)->get(self::API.'/'.$container, [
                'fields' => 'status_code,status',
                'access_token' => $token,
            ]);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Instagram', 'EzBizi could not reach it.');
        }

        if ($response->failed()) {
            throw SocialPublishFailed::network(
                'Instagram',
                (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        return [
            'code' => (string) ($response->json('status_code') ?? 'IN_PROGRESS'),
            // `status` carries the human half when something went wrong. It is
            // absent while things are going well, which is why this has a
            // fallback rather than being trusted.
            'because' => (string) ($response->json('status') ?: 'it could not process the media.'),
        ];
    }

    private function token(SocialConnection $connection): string
    {
        $credentials = $connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network(
                'Instagram',
                'that connection has no usable token. Reconnect the account.',
            );
        }

        return $token;
    }
}
