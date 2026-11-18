<?php

namespace App\Support\Social\Comments;

use App\Models\SocialConnection;
use App\Support\Social\Publishing\XPublisher;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Replies and mentions aimed at the connected X account (mentions timeline).
 *
 * Replying is a tweet with reply.in_reply_to_tweet_id (tweet.write). Hiding a
 * reply is PUT /2/tweets/:id/hidden, which needs tweet.moderate.write: not in
 * XOAuth::SCOPES today, so no X row offers Hide. X never lets an account
 * delete somebody else's tweet.
 */
final class XReplies implements CommentSource
{
    use CallsNetwork;

    public const MODERATE_SCOPE = 'tweet.moderate.write';

    private const API = 'https://api.x.com/2';

    public function __construct(private readonly XPublisher $x) {}

    public function recent(SocialConnection $connection): array
    {
        $token = $this->x->token($connection);

        $body = $this->send('X', fn () => Http::withToken($token)->timeout(15)->acceptJson()->get(self::API.'/users/'.rawurlencode((string) $connection->remote_id).'/mentions', [
            'max_results' => 50,
            'tweet.fields' => 'created_at,conversation_id,author_id,referenced_tweets',
            'expansions' => 'author_id',
            'user.fields' => 'username',
        ]));

        $users = [];
        foreach ((array) $body->json('includes.users') as $u) {
            $users[(string) $u['id']] = (string) ($u['username'] ?? '');
        }

        return array_values(array_map(function (array $t) use ($connection, $users) {
            $parent = collect((array) ($t['referenced_tweets'] ?? []))->firstWhere('type', 'replied_to')['id'] ?? ($t['conversation_id'] ?? null);

            return $this->row($connection, 'x', (string) $t['id'], (string) ($t['text'] ?? ''),
                $users[(string) ($t['author_id'] ?? '')] ?? '', $t['created_at'] ?? null, false,
                ['id' => (string) $parent, 'text' => null, 'permalink' => $parent ? 'https://x.com/i/status/'.$parent : null], []);
        }, (array) $body->json('data')));
    }

    public function actions(SocialConnection $connection): array
    {
        $actions = $this->granted($connection, 'tweet.write') ? ['reply'] : [];

        if ($this->granted($connection, self::MODERATE_SCOPE)) {
            $actions[] = 'hide';
        }

        return $actions;
    }

    public function unavailable(SocialConnection $connection): array
    {
        $why = [];

        if (! $this->granted($connection, 'tweet.write')) {
            $why['reply'] = 'X needs posting permission to reply. Reconnect the account.';
        }
        if (! $this->granted($connection, self::MODERATE_SCOPE)) {
            $why['hide'] = 'X needs moderation permission to hide replies.';
        }
        $why['delete'] = 'X does not let an account delete other people\'s replies.';

        return $why;
    }

    public function reply(SocialConnection $connection, string $commentId, string $message): string
    {
        $token = $this->x->token($connection);

        return (string) $this->send('X', fn () => Http::withToken($token)->timeout(15)->asJson()->acceptJson()
            ->post(self::API.'/tweets', ['text' => $message, 'reply' => ['in_reply_to_tweet_id' => $commentId]]))->json('data.id');
    }

    public function hide(SocialConnection $connection, string $commentId, bool $hide): void
    {
        $token = $this->x->token($connection);

        $this->send('X', fn () => Http::withToken($token)->timeout(15)->asJson()->acceptJson()
            ->put(self::API.'/tweets/'.rawurlencode($commentId).'/hidden', ['hidden' => $hide]));
    }

    public function delete(SocialConnection $connection, string $commentId): void
    {
        throw new RuntimeException($this->unavailable($connection)['delete']);
    }
}
