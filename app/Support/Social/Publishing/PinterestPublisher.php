<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use App\Support\Social\SocialMediaKind;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A Pin, on a board belonging to the connected account.
 *
 * ── Why it goes looking for a board ─────────────────────────────
 *
 * Pinterest has no "post to my profile": every Pin lives on a board, and the
 * board id is required. EzBizi does not ask the person to pick one yet — the
 * composer has no board picker — so this takes the account's first board and
 * says which one it used. When the composer grows a picker, this method takes
 * the id instead of finding it, and nothing else changes.
 *
 * An account with no boards at all is refused with the reason, because the fix
 * is on Pinterest's side and nothing EzBizi does will help.
 */
final class PinterestPublisher implements DeletesPosts, SocialPublisher
{
    private const API = 'https://api.pinterest.com/v5';

    private const SANDBOX_API = 'https://api-sandbox.pinterest.com/v5';

    private const TIMEOUT = 30;

    public function publish(SocialConnection $connection, PostPayload $post): string
    {
        if (($why = $this->cannotSend($post->kind())) !== null) {
            throw new SocialPublishFailed($why);
        }

        $token = $this->token($connection);
        // The sandbox lists none of its boards back (measured 2026-10-09), so
        // it names one rather than searching.
        $board = $this->sandbox() !== null && (string) config('services.pinterest.sandbox_board') !== ''
            ? (string) config('services.pinterest.sandbox_board')
            : $this->firstBoard($token);

        // Base64 in the body, not a URL. pull_by_url would mean publishing the
        // tenant's media to an unauthenticated public address first, which is a
        // privacy change nobody asked for to save one request.
        $response = $this->call('post', '/pins', $token, [
            'board_id' => $board,
            'title' => $post->title !== null ? mb_substr($post->title, 0, 100) : null,
            'description' => mb_substr($post->body, 0, 800),
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

    private function token(SocialConnection $connection): string
    {
        if (($sandbox = $this->sandbox()) !== null) {
            return $sandbox;
        }

        $credentials = $connection->getAttribute('credentials');
        $token = is_array($credentials) ? ($credentials['access_token'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw SocialPublishFailed::network('Pinterest', 'that connection has no usable token. Reconnect it.');
        }

        return $token;
    }
}
