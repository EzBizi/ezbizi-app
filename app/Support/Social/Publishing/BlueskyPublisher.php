<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\BlueskySession;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * A post on the connected Bluesky account.
 *
 * ── A post is a record, not an API call ─────────────────────────
 *
 * Bluesky has no "post this" endpoint. A post is an `app.bsky.feed.post`
 * record written into the account's own repository with createRecord, and
 * everything else — the link under the words, the picture — is part of that
 * record's shape. A URL in the text is NOT a link until a facet says which
 * bytes it occupies; without one Bluesky shows it as plain text.
 *
 * ── Pictures are uploaded first ─────────────────────────────────
 *
 * uploadBlob takes the raw bytes and hands back a blob reference; the record
 * then names that reference. A blob nobody names is garbage-collected, so a
 * failed createRecord leaves nothing behind to clean up.
 *
 * ── The session ─────────────────────────────────────────────────
 *
 * Connected with an app password, stored as an access JWT (about two hours)
 * and a refresh JWT that ROTATES on every use. See token().
 */
final class BlueskyPublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const TIMEOUT = 30;

    /** Bluesky's lexicon limit on a post's text, counted in graphemes. */
    public const MAX_GRAPHEMES = 300;

    /** The lexicon's maxSize for an image blob in an embed. 1,000,000, not 1 MiB. */
    public const MAX_IMAGE_BYTES = 1_000_000;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $kind = $post->kind();
        $text = trim($post->body);

        if ($kind === SocialMediaKind::Text && $text === '') {
            throw new SocialPublishFailed('A Bluesky post with no words would be an empty post. Write something.');
        }

        // Refused rather than cut: a trimmed post is a post that says something
        // other than what was written, and nobody would see it happen.
        if (($count = self::graphemes($text)) > self::MAX_GRAPHEMES) {
            throw new SocialPublishFailed(sprintf(
                'Bluesky posts stop at %d characters and this one has %d. Shorten it, then send it again. Nothing was posted.',
                self::MAX_GRAPHEMES,
                $count,
            ));
        }

        // Checked before the session is touched, so a picture that could never
        // go does not spend a refresh token on the way to being refused.
        $bytes = $kind === SocialMediaKind::Image ? $post->bytes() : null;

        if ($bytes !== null && strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw new SocialPublishFailed(sprintf(
                'Bluesky takes pictures up to 1 MB and this one is %.1f MB. Use a smaller picture. Nothing was posted.',
                strlen($bytes) / 1_000_000,
            ));
        }

        $token = $this->token($connection);
        $did = (string) $connection->remote_id;

        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];

        if (($facets = self::linkFacets($text)) !== []) {
            $record['facets'] = $facets;
        }

        if ($bytes !== null) {
            $record['embed'] = [
                '$type' => 'app.bsky.embed.images',
                'images' => [[
                    // No alt text is asked for in the composer yet. Empty is
                    // what the lexicon allows; inventing one would be worse.
                    'alt' => '',
                    'image' => $this->uploadBlob($token, $bytes, $post->mime()),
                ]],
            ];
        }

        $created = $this->call($token, 'com.atproto.repo.createRecord', [
            'repo' => $did,
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]);

        $uri = $created['uri'] ?? null;

        if (! is_string($uri) || ! str_starts_with($uri, 'at://')) {
            throw SocialPublishFailed::network('Bluesky', 'it accepted the post but did not say where it is.');
        }

        return $uri;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        // The network has no video (SocialProvider::accepts says so). The
        // sentence is the network's limit, not a missing poster.
        return SocialProvider::Bluesky->refuses($kind);
    }

    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        if (preg_match('~^at://([^/]+)/(app\.bsky\.feed\.post)/([^/]+)$~', $remoteId, $m) !== 1) {
            throw SocialPublishFailed::network('Bluesky', 'EzBizi does not recognise that post.');
        }

        $this->call($this->token($connection), 'com.atproto.repo.deleteRecord', [
            'repo' => $m[1], 'collection' => $m[2], 'rkey' => $m[3],
        ]);
    }

    /**
     * at://did/app.bsky.feed.post/rkey → https://bsky.app/profile/did/post/rkey
     *
     * Derivable from the record uri, unlike a Facebook post, so no call.
     */
    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        if (preg_match('~^at://([^/]+)/app\.bsky\.feed\.post/([^/]+)$~', $remoteId, $m) !== 1) {
            return null;
        }

        return 'https://bsky.app/profile/'.$m[1].'/post/'.$m[2];
    }

    /**
     * A link facet for every URL in the text.
     *
     * The offsets are UTF-8 BYTES, not characters — preg's offset capture is
     * already bytes, which is why it is used rather than mb_ functions. One
     * accented letter before a link would otherwise put the link one byte to
     * the left of where it is.
     *
     * @return list<array<string, mixed>>
     */
    public static function linkFacets(string $text): array
    {
        preg_match_all('~https?://[^\s<>"]+~u', $text, $matches, PREG_OFFSET_CAPTURE);

        $facets = [];

        foreach ($matches[0] as [$url, $start]) {
            // A sentence that ends in a link ends in punctuation that is not
            // part of it: "see https://ezbizi.com." links to ezbizi.com.
            $url = rtrim($url, '.,;:!?)\'"');

            $facets[] = [
                'index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($url)],
                'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]],
            ];
        }

        return $facets;
    }

    /** Counted the way Bluesky counts: what a person sees as one character. */
    public static function graphemes(string $text): int
    {
        return (int) preg_match_all('/\X/u', $text);
    }

    /** @return array<string, mixed> the blob reference, exactly as returned */
    private function uploadBlob(string $token, string $bytes, string $mime): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->acceptJson()
                ->withBody($bytes, $mime)
                ->post(BlueskySession::PDS.'/xrpc/com.atproto.repo.uploadBlob');
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Bluesky', 'EzBizi could not reach it.');
        }

        $blob = $response->json('blob');

        if ($response->failed() || ! is_array($blob)) {
            throw SocialPublishFailed::network('Bluesky', $this->reason($response->json(), $response->status()));
        }

        return $blob;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $token, string $method, array $payload): array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(self::TIMEOUT)
                ->asJson()
                ->acceptJson()
                ->post(BlueskySession::PDS.'/xrpc/'.$method, $payload);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Bluesky', 'EzBizi could not reach it.');
        }

        if ($response->failed()) {
            throw SocialPublishFailed::network('Bluesky', $this->reason($response->json(), $response->status()));
        }

        return (array) $response->json();
    }

    /** XRPC errors are `{error, message}`; the message is the useful half. */
    private function reason(mixed $body, int $status): string
    {
        $message = is_array($body) ? ($body['message'] ?? $body['error'] ?? null) : null;

        return is_string($message) && $message !== '' ? $message : 'HTTP '.$status;
    }

    /**
     * A live access JWT, renewed first when the stored one is (nearly) dead.
     *
     * ── Why the expiry is read out of the token ─────────────────
     *
     * createSession gives no expires_in. The access JWT carries its own `exp`,
     * which is read, not verified — EzBizi is not the party that checks this
     * token, Bluesky is; all we need is a hint about when to renew. A token
     * whose exp cannot be read is renewed, which costs one call and is never
     * wrong.
     *
     * ── Why a lock, and why re-read inside it ───────────────────
     *
     * The refresh JWT rotates. Two workers posting for the same account at the
     * same minute would both send the same refresh JWT; the second is refused,
     * and that refusal looks exactly like a revoked app password. The lock
     * serialises them; the re-read inside it lets the second find the first's
     * new token instead of replaying the spent one.
     */
    public function token(SocialConnection $connection): string
    {
        $credentials = (array) ($connection->getAttribute('credentials') ?? []);

        if (self::stillLive($credentials['access_token'] ?? null)) {
            return (string) $credentials['access_token'];
        }

        return Cache::lock('bluesky-refresh:'.$connection->getKey(), 30)->block(15, function () use ($connection) {
            if ($connection->exists) {
                $connection->refresh();
            }

            $credentials = (array) ($connection->getAttribute('credentials') ?? []);

            if (self::stillLive($credentials['access_token'] ?? null)) {
                return (string) $credentials['access_token']; // renewed by another worker while we waited
            }

            $refresh = $credentials['refresh_token'] ?? null;

            if (! is_string($refresh) || $refresh === '') {
                throw SocialPublishFailed::network('Bluesky', 'that connection has no usable session. Reconnect it.');
            }

            try {
                $session = app(BlueskySession::class)->refresh($refresh);
            } catch (SocialOAuthRefused $e) {
                if ($e->status === Response::HTTP_CONFLICT) {
                    $connection->forceFill([
                        'status' => SocialConnection::STATUS_BROKEN,
                        'status_reason' => 'Bluesky no longer accepts this connection. It needs connecting again with a new app password.',
                    ])->save();
                }

                throw new SocialPublishFailed($e->getMessage());
            }

            // Stored before anything else can fail: the old refresh JWT is
            // already spent, so losing this one would strand the connection.
            $credentials['access_token'] = $session['access_jwt'];
            $credentials['refresh_token'] = $session['refresh_jwt'];
            $connection->setAttribute('credentials', $credentials);
            $connection->save();

            return $session['access_jwt'];
        });
    }

    /** True when the JWT's own exp is more than ten minutes away. */
    private static function stillLive(mixed $jwt): bool
    {
        if (! is_string($jwt) || substr_count($jwt, '.') !== 2) {
            return false;
        }

        $payload = json_decode((string) base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
        $exp = is_array($payload) ? ($payload['exp'] ?? null) : null;

        return is_int($exp) && $exp > now()->addMinutes(10)->getTimestamp();
    }
}
