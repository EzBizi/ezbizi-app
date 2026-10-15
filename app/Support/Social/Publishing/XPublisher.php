<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\XOAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * A post on the connected X account, with OAuth 2.0 user context.
 *
 * ── Words, or words and one picture ─────────────────────────────
 *
 * A picture is uploaded first to /2/media/upload (scope media.write) and the
 * post then names it by id. Video on X is a chunked INIT/APPEND/FINALIZE upload
 * followed by polling until X has transcoded it; that is not built, and the
 * composer is told so up front by cannotSend() rather than at send time.
 *
 * ── Two hours, and a refresh token that rotates ─────────────────
 *
 * X access tokens last two hours, so a post scheduled for tomorrow is always
 * sent with a renewed one. The refresh token is replaced on every renewal and
 * the old one stops working the moment X answers — see token() for why that
 * makes a lock necessary rather than tidy.
 */
final class XPublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const API = 'https://api.x.com/2';

    private const TIMEOUT = 30;

    /**
     * X's limit for an account without a subscription. X counts a link as 23
     * and some scripts as two; this counts characters, so it can only refuse
     * less than X would, never more. The composer enforces the same number.
     */
    public const MAX_CHARACTERS = 280;

    /** X's limit for a still image. GIFs may be 15 MB, but 5 is what all of them share. */
    public const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $kind = $post->kind();
        $text = trim($post->body);

        if ($kind === SocialMediaKind::Text && $text === '') {
            throw new SocialPublishFailed('A post on X with no words would be an empty post. Write something.');
        }

        // Refused, not cut. X would refuse it too, with a sentence about
        // "too long" that does not say by how much.
        if (($count = mb_strlen($text)) > self::MAX_CHARACTERS) {
            throw new SocialPublishFailed(sprintf(
                'Posts on X stop at %d characters and this one has %d. Shorten it, then send it again. Nothing was posted.',
                self::MAX_CHARACTERS,
                $count,
            ));
        }

        $bytes = $kind === SocialMediaKind::Image ? $post->bytes() : null;

        if ($bytes !== null && strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new SocialPublishFailed(sprintf(
                'X takes pictures up to 5 MB and this one is %.1f MB. Use a smaller picture. Nothing was posted.',
                strlen($bytes) / 1024 / 1024,
            ));
        }

        $token = $this->token($connection);

        $tweet = $text === '' ? [] : ['text' => $text];

        if ($bytes !== null) {
            $tweet['media'] = ['media_ids' => [$this->uploadImage($token, $bytes, $post)]];
        }

        $body = $this->send(
            fn () => Http::withToken($token)->timeout(self::TIMEOUT)->asJson()->acceptJson()
                ->post(self::API.'/tweets', $tweet),
        );

        $id = $body['data']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            // X can answer 2xx with `errors` and no `data`; that is a refusal.
            throw SocialPublishFailed::network('X', $this->reason($body, 200));
        }

        return $id;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        // X takes video (SocialProvider::accepts says so); this poster does not.
        return $kind === SocialMediaKind::Video
            ? SocialPublishFailed::notImplemented('X', $kind->label())->getMessage()
            : null;
    }

    /** Built from the id: X's own redirect finds the account, so no handle is needed. */
    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        $token = $this->token($connection);

        $this->send(fn () => Http::withToken($token)->timeout(self::TIMEOUT)->acceptJson()
            ->delete(self::API.'/tweets/'.rawurlencode($remoteId)));
    }

    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        return ctype_digit($remoteId) ? 'https://x.com/i/web/status/'.$remoteId : null;
    }

    private function uploadImage(string $token, string $bytes, PostPayload $post): string
    {
        $mime = $post->mime();

        // Multipart, raw bytes. The JSON form takes base64, a third bigger for
        // nothing. tweet_gif rather than tweet_image for a GIF, or X flattens
        // an animation to its first frame.
        $body = $this->send(
            fn () => Http::withToken($token)->timeout(self::TIMEOUT)->acceptJson()
                ->attach('media', $bytes, 'upload.'.(pathinfo((string) $post->filePath, PATHINFO_EXTENSION) ?: 'jpg'), ['Content-Type' => $mime])
                ->post(self::API.'/media/upload', [
                    'media_category' => $mime === 'image/gif' ? 'tweet_gif' : 'tweet_image',
                ]),
            uploading: true,
        );

        $id = $body['data']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('X', 'it took the picture but did not say what it called it.');
        }

        return $id;
    }

    /**
     * @param  callable(): \Illuminate\Http\Client\Response  $request
     * @return array<string, mixed>
     */
    private function send(callable $request, bool $uploading = false): array
    {
        try {
            $response = $request();
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('X', 'EzBizi could not reach it.');
        }

        $body = (array) $response->json();

        // A 403 on the upload is almost always the missing scope: accounts
        // connected before media.write was asked for can post words and not
        // pictures, and X's own text ("Forbidden") does not say which.
        if ($uploading && $response->status() === Response::HTTP_FORBIDDEN) {
            throw new SocialPublishFailed(
                'X would not take the picture for this account. Connect the X account again so '
                .'EzBizi can post pictures, then send it again. Nothing was posted.'
            );
        }

        if ($response->failed()) {
            throw SocialPublishFailed::network('X', $this->reason($body, $response->status()));
        }

        return $body;
    }

    /**
     * X's errors come in two shapes: problem details (`detail`) on a refused
     * request, and an `errors` list beside or instead of `data`.
     *
     * @param  array<string, mixed>  $body
     */
    private function reason(array $body, int $status): string
    {
        $message = $body['detail']
            ?? $body['errors'][0]['message']
            ?? $body['errors'][0]['detail']
            ?? $body['title']
            ?? null;

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$status;
    }

    /**
     * A live access token, renewed first when the stored one is (nearly) dead.
     *
     * ── No recorded expiry means renew ──────────────────────────
     *
     * Unlike TikTok's, which trusts a token with no expiry. Connections made
     * before XOAuth kept expires_in have none, and an X token is two hours old
     * at most, so a token of unknown age is almost certainly a dead one.
     *
     * ── Why a lock, and why re-read inside it ───────────────────
     *
     * X rotates the refresh token on every renewal. Two workers renewing the
     * same connection would both send the same refresh token; X honours the
     * first and refuses the second, and the second would then mark a perfectly
     * good connection broken. The lock serialises them, and the re-read lets
     * the second find the token the first has just stored.
     */
    public function token(SocialConnection $connection): string
    {
        $credentials = (array) ($connection->getAttribute('credentials') ?? []);

        if (self::stillLive($credentials)) {
            return (string) $credentials['access_token'];
        }

        return Cache::lock('x-refresh:'.$connection->getKey(), 30)->block(15, function () use ($connection) {
            if ($connection->exists) {
                $connection->refresh();
            }

            $credentials = (array) ($connection->getAttribute('credentials') ?? []);

            if (self::stillLive($credentials)) {
                return (string) $credentials['access_token']; // renewed by another worker while we waited
            }

            $refresh = $credentials['refresh_token'] ?? null;

            if (! is_string($refresh) || $refresh === '') {
                throw SocialPublishFailed::network('X', 'that connection has no usable token. Reconnect it.');
            }

            try {
                $grant = app(XOAuth::class)->refresh($refresh);
            } catch (SocialOAuthRefused $e) {
                if ($e->status === Response::HTTP_CONFLICT) {
                    $connection->forceFill([
                        'status' => SocialConnection::STATUS_BROKEN,
                        'status_reason' => 'X no longer accepts this connection. It needs connecting again.',
                    ])->save();
                }

                throw new SocialPublishFailed($e->getMessage());
            }

            // Stored before anything else can fail: the old refresh token is
            // already spent, so losing the new one would strand the connection.
            $credentials['access_token'] = $grant->accessToken;
            $credentials['refresh_token'] = $grant->refreshToken;
            $credentials['expires_at'] = now()->addSeconds($grant->expiresIn ?? 7200)->toIso8601String();
            $connection->setAttribute('credentials', $credentials);
            $connection->save();

            return $grant->accessToken;
        });
    }

    /** @param  array<string, mixed>  $credentials */
    private static function stillLive(array $credentials): bool
    {
        $token = $credentials['access_token'] ?? null;
        $expires = $credentials['expires_at'] ?? null;

        return is_string($token) && $token !== ''
            && is_string($expires) && Carbon::parse($expires)->isAfter(now()->addMinutes(10));
    }
}
