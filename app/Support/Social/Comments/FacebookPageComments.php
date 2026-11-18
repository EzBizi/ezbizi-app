<?php

namespace App\Support\Social\Comments;

use App\Models\SocialConnection;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Comments on a connected Facebook Page's own posts (Graph API).
 *
 * Reading other people's comments needs pages_read_user_content; replying
 * (/{comment-id}/comments) and hiding (is_hidden) need
 * pages_manage_engagement. Neither is in FacebookOAuth::SCOPES today, so a
 * Page connected now offers neither action and says why. Facebook has no
 * Delete here: hiding is the moderation it gives a Page.
 */
final class FacebookPageComments implements CommentSource
{
    use CallsNetwork;

    public const READ_SCOPE = 'pages_read_user_content';

    public const MANAGE_SCOPE = 'pages_manage_engagement';

    private const API = 'https://graph.facebook.com/v21.0';

    private const POSTS = 10;

    public function recent(SocialConnection $connection): array
    {
        $posts = (array) $this->send('Facebook', fn () => Http::timeout(15)->get(self::API.'/'.$connection->remote_id.'/posts', [
            'fields' => 'id,message,permalink_url,created_time,comments.limit(25){id,message,from,created_time,is_hidden,comments{id,message,from,created_time}}',
            'limit' => self::POSTS,
            'access_token' => $this->token($connection),
        ]))->json('data');

        $rows = [];

        foreach ($posts as $post) {
            $about = ['id' => (string) $post['id'], 'text' => $post['message'] ?? null, 'permalink' => $post['permalink_url'] ?? null];

            foreach ((array) ($post['comments']['data'] ?? []) as $c) {
                $rows[] = $this->row($connection, 'facebook', (string) $c['id'], (string) ($c['message'] ?? ''),
                    (string) ($c['from']['name'] ?? ''), $c['created_time'] ?? null, (bool) ($c['is_hidden'] ?? false), $about,
                    array_map(fn (array $r) => [
                        'id' => (string) $r['id'],
                        'text' => (string) ($r['message'] ?? ''),
                        'author' => (string) ($r['from']['name'] ?? ''),
                        'timestamp' => $r['created_time'] ?? null,
                    ], (array) ($c['comments']['data'] ?? [])));
            }
        }

        return $rows;
    }

    public function actions(SocialConnection $connection): array
    {
        return $this->granted($connection, self::MANAGE_SCOPE) ? ['reply', 'hide'] : [];
    }

    public function unavailable(SocialConnection $connection): array
    {
        $why = [];

        if (! $this->granted($connection, self::MANAGE_SCOPE)) {
            $why['reply'] = $why['hide'] = 'Facebook needs the manage-engagement permission to reply or hide.';
        }
        $why['delete'] = 'Facebook lets a Page hide comments, not delete them.';

        return $why;
    }

    public function reply(SocialConnection $connection, string $commentId, string $message): string
    {
        return (string) $this->send('Facebook', fn () => Http::timeout(15)->asForm()->post(self::API.'/'.$commentId.'/comments', [
            'message' => $message,
            'access_token' => $this->token($connection),
        ]))->json('id');
    }

    public function hide(SocialConnection $connection, string $commentId, bool $hide): void
    {
        $this->send('Facebook', fn () => Http::timeout(15)->asForm()->post(self::API.'/'.$commentId, [
            'is_hidden' => $hide ? 'true' : 'false',
            'access_token' => $this->token($connection),
        ]));
    }

    public function delete(SocialConnection $connection, string $commentId): void
    {
        throw new RuntimeException($this->unavailable($connection)['delete']);
    }

    private function token(SocialConnection $connection): string
    {
        $token = $connection->credentials['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('That Page is connected but has no usable token. Reconnect it from Social Media.');
        }

        return $token;
    }
}
