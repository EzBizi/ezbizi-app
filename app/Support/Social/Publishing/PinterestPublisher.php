<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\ConnectSetup;
use App\Support\Social\PinterestOAuth;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialOAuthRefused;
use App\Support\Social\SocialProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * A Pin, on a board belonging to the connected account.
 *
 * ── Why it goes looking for a board ─────────────────────────────
 *
 * Pinterest has no "post to my profile": every Pin lives on a board, and the
 * board id is required. Connect chooses one (the first board, by default) and
 * the account's card offers the others (ConnectSetup, 2026-11-12); this posts
 * to that board, and only falls back to the first one for a row connected
 * before the choice existed.
 *
 * An account with no boards at all is refused with the reason, because the fix
 * is on Pinterest's side and nothing EzBizi does will help.
 */
final class PinterestPublisher implements DeletesPosts, LinksToPosts, SocialPublisher
{
    private const API = 'https://api.pinterest.com/v5';

    private const SANDBOX_API = 'https://api-sandbox.pinterest.com/v5';

    private const TIMEOUT = 30;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        // Refused, never cut mid-sentence (the description's 800 characters).
        if (($why = SocialProvider::Pinterest->refusesLength($post->body)) !== null) {
            throw new SocialPublishFailed($why.' Nothing was posted.');
        }

        $token = $this->token($connection);
        // The sandbox lists none of its boards back (measured 2026-10-09), so
        // it names one rather than searching.
        $board = $this->sandbox() !== null && (string) config('services.pinterest.sandbox_board') !== ''
            ? (string) config('services.pinterest.sandbox_board')
            : (ConnectSetup::chosen($connection, 'board') ?? $this->firstBoard($token));

        // Base64 in the body, not a URL. pull_by_url would mean publishing the
        // tenant's media to an unauthenticated public address first, which is a
        // privacy change nobody asked for to save one request.
        $response = $this->call('post', '/pins', $token, [
            'board_id' => $board,
            'title' => $post->title !== null ? mb_substr($post->title, 0, 100) : null,
            'description' => $post->body,
            'media_source' => [
                'source_type' => 'image_base64',
                'content_type' => $post->mime(),
                'data' => base64_encode($post->bytes()),
            ],
        ]);

        $id = $response['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('Pinterest', 'it created nothing and said nothing.');
        }

        return $id;
    }

    public function cannotSend(SocialMediaKind $kind): ?string
    {
        // A Pin is a picture. Pinterest does take video, and EzBizi does not
        // send it — which is why this says "not built" and not "not accepted".
        return $kind === SocialMediaKind::Image
            ? null
            : SocialPublishFailed::notImplemented('Pinterest', $kind->label())->getMessage();
    }

    /**
     * The account's boards, for Connect's board choice.
     *
     * @return list<array{id: string, name: string}>
     */
    public function boards(SocialConnection $connection): array
    {
        $boards = $this->call('get', '/boards', $this->token($connection), ['page_size' => 100]);

        return array_values(array_map(
            fn (array $b) => ['id' => (string) $b['id'], 'name' => (string) ($b['name'] ?? $b['id'])],
            array_filter((array) ($boards['items'] ?? []), fn ($b) => is_array($b) && is_string($b['id'] ?? null) && $b['id'] !== ''),
        ));
    }

    /**
     * A board for an account that has none, so Connect leaves one chosen.
     *
     * @return array{id: string, name: string}
     */
    public function createBoard(SocialConnection $connection, string $name): array
    {
        $board = $this->call('post', '/boards', $this->token($connection), ['name' => $name, 'privacy' => 'PUBLIC']);
        $id = $board['id'] ?? null;

        if (! is_string($id) || $id === '') {
            throw SocialPublishFailed::network('Pinterest', 'it did not create the board.');
        }

        return ['id' => $id, 'name' => (string) ($board['name'] ?? $name)];
    }

    private function firstBoard(string $token): string
    {
        $boards = $this->call('get', '/boards', $token, ['page_size' => 25]);
        $first = $boards['items'][0]['id'] ?? null;

        if (! is_string($first) || $first === '') {
            throw SocialPublishFailed::network(
                'Pinterest',
                'that account has no boards, and a Pin has to live on one. Create a board on '
                .'Pinterest first.',
            );
        }

        return $first;
    }

    /** @return array<string, mixed> */
    private function call(string $method, string $path, string $token, array $payload): array
    {
        try {
            $request = Http::withToken($token)->timeout(self::TIMEOUT)->acceptJson();

            $response = match ($method) {
                'get' => $request->get($this->api().$path, $payload),
                'delete' => $request->delete($this->api().$path),
                default => $request->post($this->api().$path, array_filter($payload, fn ($v) => $v !== null)),
            };
        } catch (ConnectionException) {
            throw SocialPublishFailed::network('Pinterest', 'EzBizi could not reach it.');
        }

        if ($response->failed()) {
            // Pinterest puts a readable sentence in `message`. Passing it through
            // beats "HTTP 400" by a distance when somebody has to fix the post.
            throw SocialPublishFailed::network(
                'Pinterest',
                (string) ($response->json('message') ?? 'HTTP '.$response->status()),
            );
        }

        return (array) $response->json();
    }

    /** A pin's page is its id: no call, so nothing to fail after the post is out. */
    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        return 'https://www.pinterest.com/pin/'.rawurlencode($remoteId).'/';
    }

    public function deleteRemote(SocialConnection $connection, string $remoteId): void
    {
        $this->call('delete', '/pins/'.rawurlencode($remoteId), $this->token($connection), []);
    }

    /** The sandbox token while the app is on Trial access, else null. */
    private function sandbox(): ?string
    {
        $token = config('services.pinterest.sandbox_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function api(): string
    {
        return $this->sandbox() !== null ? self::SANDBOX_API : self::API;
    }

    public function token(SocialConnection $connection): string
    {
        if (($sandbox = $this->sandbox()) !== null) {
            return $sandbox;
        }

        $credentials = (array) ($connection->getAttribute('credentials') ?? []);
        $token = $credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network('Pinterest', 'that connection has no usable token. Reconnect it.');
        }

        $expires = $credentials['expires_at'] ?? null;
        if (! is_string($expires) || Carbon::parse($expires)->isAfter(now()->addMinutes(10))) {
            return $token;
        }

        // ponytail: no lock; Pinterest keeps the refresh token, so two renewals both work.
        $refresh = $credentials['refresh_token'] ?? null;
        if (! is_string($refresh) || $refresh === '') {
            throw SocialPublishFailed::network('Pinterest', 'that connection has no usable token. Reconnect it.');
        }

        try {
            $grant = app(PinterestOAuth::class)->refresh($refresh);
        } catch (SocialOAuthRefused $e) {
            if ($e->status === Response::HTTP_CONFLICT) {
                $connection->forceFill([
                    'status' => SocialConnection::STATUS_BROKEN,
                    'status_reason' => $e->getMessage(),
                ])->save();
            }

            throw new SocialPublishFailed($e->getMessage());
        }

        $credentials['access_token'] = $grant->accessToken;
        $credentials['refresh_token'] = $grant->refreshToken;
        $credentials['expires_at'] = now()->addSeconds($grant->expiresIn ?? 2592000)->toIso8601String();
        $connection->setAttribute('credentials', $credentials);
        if ($connection->exists) {
            $connection->save();
        }

        return $grant->accessToken;
    }
}
