<?php

namespace App\Support\Social;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The comments people leave on a business's Instagram posts: read, reply, hide.
 *
 * Reached through the connected Facebook PAGE, not the Instagram Login
 * connection: comment moderation is the Facebook-Login Instagram API
 * (instagram_basic + instagram_manage_comments), which acts on the Instagram
 * professional account linked to that Page. Jose, 2026-09-28, kept the
 * permission in the App Review submission; this is what uses it.
 *
 * Nothing is stored. Comments are read live when the screen opens, like the
 * Google reviews a business answers - a copy would go stale the moment
 * somebody deleted their comment on Instagram.
 */
final class InstagramComments
{
    private const API = 'https://graph.facebook.com/v21.0';

    /** Recent posts looked at. Enough to catch this week's comments. */
    private const POSTS = 10;

    /**
     * The linked Instagram account and its recent posts with their comments.
     *
     * @return array{account: ?array{id: string, username: string}, posts: list<array<string, mixed>>}
     */
    public function recent(SocialConnection $page): array
    {
        $account = $this->get($page, '/'.$page->remote_id, ['fields' => 'instagram_business_account{id,username}'])
            ->json('instagram_business_account');

        if (! is_array($account) || ! isset($account['id'])) {
            return ['account' => null, 'posts' => []];
        }

        $media = (array) $this->get($page, '/'.$account['id'].'/media', [
            'fields' => 'id,caption,permalink,timestamp,comments_count',
            'limit' => self::POSTS,
        ])->json('data');

        $posts = [];

        foreach ($media as $post) {
            $comments = ($post['comments_count'] ?? 0) > 0
                ? (array) $this->get($page, '/'.$post['id'].'/comments', [
                    'fields' => 'id,text,username,timestamp,hidden,replies{id,text,username,timestamp}',
                    'limit' => 25,
                ])->json('data')
                : [];

            $posts[] = [
                'id' => (string) $post['id'],
                'caption' => $post['caption'] ?? null,
                'permalink' => $post['permalink'] ?? null,
                'timestamp' => $post['timestamp'] ?? null,
                'comments' => array_map(fn (array $c) => [
                    'id' => (string) $c['id'],
                    'text' => $c['text'] ?? '',
                    'username' => $c['username'] ?? '',
                    'timestamp' => $c['timestamp'] ?? null,
                    'hidden' => (bool) ($c['hidden'] ?? false),
                    'replies' => array_map(fn (array $r) => [
                        'id' => (string) $r['id'],
                        'text' => $r['text'] ?? '',
                        'username' => $r['username'] ?? '',
                        'timestamp' => $r['timestamp'] ?? null,
                    ], (array) ($c['replies']['data'] ?? [])),
                ], $comments),
            ];
        }

        return ['account' => ['id' => (string) $account['id'], 'username' => (string) ($account['username'] ?? '')], 'posts' => $posts];
    }

    /** Answer a comment, as the business. Returns the reply's id. */
    public function reply(SocialConnection $page, string $commentId, string $message): string
    {
        return (string) $this->post($page, '/'.$commentId.'/replies', ['message' => $message])->json('id');
    }

    /** Hide a comment from everybody but its author, or show it again. */
    public function hide(SocialConnection $page, string $commentId, bool $hide): void
    {
        $this->post($page, '/'.$commentId, ['hide' => $hide ? 'true' : 'false']);
    }

    /** @param  array<string, mixed>  $query */
    private function get(SocialConnection $page, string $path, array $query): Response
    {
        return $this->send(fn () => Http::timeout(15)->get(self::API.$path, $query + ['access_token' => $this->token($page)]));
    }

    /** @param  array<string, mixed>  $form */
    private function post(SocialConnection $page, string $path, array $form): Response
    {
        return $this->send(fn () => Http::timeout(15)->asForm()->post(self::API.$path, $form + ['access_token' => $this->token($page)]));
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws RuntimeException with a sentence for the screen
     */
    private function send(callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new RuntimeException('Instagram did not answer. Try again in a moment.');
        }

        if ($response->failed()) {
            throw new RuntimeException((string) ($response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? 'Instagram refused that (HTTP '.$response->status().').'));
        }

        return $response;
    }

    private function token(SocialConnection $page): string
    {
        $token = $page->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('That Page is connected but has no usable token. Reconnect it from Social Media.');
        }

        return $token;
    }
}
