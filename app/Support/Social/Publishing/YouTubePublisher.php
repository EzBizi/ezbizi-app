<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\GoogleToken;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A video, uploaded to the connected YouTube channel.
 *
 * ── Resumable, in two calls ─────────────────────────────────────
 *
 *   POST — the metadata (title, description, privacy), and the size and type
 *          of what is coming. YouTube answers with a session address in the
 *          `Location` header and nothing in the body.
 *   PUT  — the bytes, to that address. THIS answer is the video resource,
 *          and its `id` is what we keep.
 *
 * Resumable rather than multipart because multipart is one request carrying
 * both JSON and the whole file in a hand-built MIME body, and resumable keeps
 * the two apart. It is sent as ONE PUT: the file is already
 * in memory (PostPayload::bytes()) and the queue's 60-second worker is the
 * real ceiling, not the protocol.
 *
 * ── Why a fresh token every time ────────────────────────────────
 *
 * Google access tokens last an hour and none is stored worth trusting, so
 * GoogleToken refreshes on every send — the same helper the Ads and Business
 * Profile syncs use, signed with the YouTube OAuth client (`services.youtube`),
 * and it is that helper that marks the row broken on `invalid_grant`.
 *
 * ── The "unverified projects upload private" rule is gone ────────
 *
 * For years, videos uploaded through an unaudited API project were locked to
 * private whatever `privacyStatus` said. Google's videos.insert reference, as
 * read on 2026-10-09, now says the opposite: "Videos uploaded from unverified
 * API projects are not restricted to private viewing mode." What remains is
 * that a CHANNEL's own settings can default an upload to private, and the
 * default quota: 100 videos.insert calls a day for the whole installation
 * (its own "Video Uploads" bucket, as read the same day) until an audit
 * raises it. A "public" video that shows as private is that channel setting
 * first.
 */
final class YouTubePublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    private const TIMEOUT = 30;

    private const UPLOAD_TIMEOUT = 120;

    /** People & Blogs — the neutral default; a business channel can change it on YouTube. */
    private const CATEGORY = '22';

    private const PRIVACY = ['public', 'unlisted', 'private'];

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        try {
            $token = GoogleToken::forConnection($connection, 'youtube');
        } catch (RuntimeException $e) {
            throw new SocialPublishFailed($e->getMessage());
        }

        $bytes = $post->bytes();
        $mime = $post->mime();

        try {
            $session = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->asJson()
                ->withHeaders([
                    'X-Upload-Content-Length' => (string) strlen($bytes),
                    'X-Upload-Content-Type' => $mime,
                ])
                ->post(self::UPLOAD.'?uploadType=resumable&part=snippet,status', $this->metadata($post));
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('YouTube', 'EzBizi could not reach it.');
        }

        $this->check($session);

        $location = $session->header('Location');

        if ($location === '') {
            throw SocialPublishFailed::network('YouTube', 'it did not hand back an address to upload the video to.');
        }

        try {
            $uploaded = Http::withToken($token)
                ->timeout(self::UPLOAD_TIMEOUT)
                ->acceptJson()
                ->withBody($bytes, $mime)
                ->put($location);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('YouTube', 'the upload could not reach it.');
        }

        $this->check($uploaded);

        $id = $uploaded->json('id');

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('YouTube', 'the upload finished but it did not say what the video is called.');
        }

        return $id;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        // The network's own sentence, because here the network's limit and
        // ours are the same limit: YouTube takes video and nothing else.
        return SocialProvider::YouTube->refuses($kind);
    }

    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        try {
            $token = GoogleToken::forConnection($connection, 'youtube');
            $response = Http::withToken($token)->timeout(self::TIMEOUT)
                ->delete('https://www.googleapis.com/youtube/v3/videos?id='.rawurlencode($remoteId));
        } catch (RuntimeException $e) {
            throw new SocialPublishFailed($e->getMessage());
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('YouTube', 'EzBizi could not reach it.');
        }

        $this->check($response);
    }

    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        return 'https://www.youtube.com/watch?v='.rawurlencode($remoteId);
    }

    /**
     * Title, description and privacy.
     *
     * YouTube refuses `<` and `>` in both title and description (invalidTitle
     * / invalidDescription), so they are removed rather than letting a stray
     * "<3" fail the upload. The title is 100 characters; the description is
     * 5000 BYTES, hence mb_strcut and not mb_substr.
     *
     * @return array<string, mixed>
     */
    private function metadata(PostPayload $post): array
    {
        $clean = fn (string $s) => trim(str_replace(['<', '>'], '', $s));

        $title = $clean((string) $post->title);

        if ($title === '') {
            $title = $clean((string) strtok(trim($post->body), "\r\n"));
        }

        if ($title === '') {
            $title = 'EzBizi video';
        }

        $privacy = (string) ($post->options['privacy_status'] ?? '');

        return [
            'snippet' => [
                'title' => mb_substr($title, 0, 100),
                'description' => mb_strcut($clean($post->body), 0, 5000),
                'categoryId' => self::CATEGORY,
            ],
            'status' => [
                'privacyStatus' => in_array($privacy, self::PRIVACY, true) ? $privacy : 'public',
                // Required by COPPA for every upload; a business's marketing
                // video is not made for children. Asked explicitly rather than
                // left for YouTube to default, because an unanswered question
                // here holds the video back until it is answered in Studio.
                'selfDeclaredMadeForKids' => false,
            ],
        ];
    }

    /**
     * A failed answer as a sentence. Google puts the reason in
     * `error.errors[0].reason` and a readable line in `error.message`; the two
     * reasons a person can actually do something about get their own words.
     */
    private function check(Response $response): void
    {
        if (! $response->failed()) {
            return;
        }

        $reason = (string) ($response->json('error.errors.0.reason') ?? '');
        $message = $response->json('error.message');

        throw SocialPublishFailed::network('YouTube', match ($reason) {
            'youtubeSignupRequired' => 'that Google account has no YouTube channel yet. Create one on YouTube, then send it again.',
            'uploadLimitExceeded' => 'this channel has reached its upload limit for today. Try again tomorrow.',
            // Connected before deleting was asked for (2026-11-02).
            'insufficientPermissions' => 'this channel was connected before EzBizi could delete videos. Reconnect it in Accounts, then try again.',
            'quotaExceeded' => 'EzBizi has used up its YouTube allowance for today. Try again tomorrow.',
            default => is_string($message) && $message !== '' ? $message : 'HTTP '.$response->status().'.',
        });
    }
}
