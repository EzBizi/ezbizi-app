<?php

namespace App\Support\Social\Comments;

use App\Models\SocialConnection;
use App\Support\Social\InstagramComments;
use RuntimeException;

/**
 * The Instagram comments InstagramComments already reads through a Facebook
 * Page, flattened into inbox rows. Reply and hide as before; Instagram's
 * moderation through a Page is hiding, so there is no Delete.
 */
final class InstagramCommentSource implements CommentSource
{
    use CallsNetwork;

    public function __construct(private readonly InstagramComments $comments) {}

    public function recent(SocialConnection $connection): array
    {
        $rows = [];

        foreach ($this->comments->recent($connection)['posts'] as $post) {
            $about = ['id' => (string) $post['id'], 'text' => $post['caption'] ?? null, 'permalink' => $post['permalink'] ?? null];

            foreach ($post['comments'] as $c) {
                $rows[] = $this->row($connection, 'instagram', $c['id'], $c['text'], $c['username'], $c['timestamp'], $c['hidden'], $about,
                    array_map(fn (array $r) => [
                        'id' => $r['id'], 'text' => $r['text'], 'author' => $r['username'], 'timestamp' => $r['timestamp'],
                    ], $c['replies']));
            }
        }

        return $rows;
    }

    public function actions(SocialConnection $connection): array
    {
        return ['reply', 'hide'];
    }

    public function unavailable(SocialConnection $connection): array
    {
        return ['delete' => 'Instagram lets a business hide comments, not delete them.'];
    }

    public function reply(SocialConnection $connection, string $commentId, string $message): string
    {
        return $this->comments->reply($connection, $commentId, $message);
    }

    public function hide(SocialConnection $connection, string $commentId, bool $hide): void
    {
        $this->comments->hide($connection, $commentId, $hide);
    }

    public function delete(SocialConnection $connection, string $commentId): void
    {
        throw new RuntimeException($this->unavailable($connection)['delete']);
    }
}
