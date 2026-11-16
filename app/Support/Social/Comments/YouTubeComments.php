<?php

namespace App\Support\Social\Comments;

use App\Models\SocialConnection;
use App\Support\Social\GoogleToken;
use App\Support\Social\YouTubeOAuth;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Comments on the connected channel's videos (YouTube Data API v3).
 *
 * commentThreads.list with allThreadsRelatedToChannelId reads them; replying
 * (comments.insert with parentId) and hiding (comments.setModerationStatus
 * heldForReview, back with published) need youtube.force-ssl, which
 * YouTubeOAuth::SCOPES requests. A channel that did not grant it reads only.
 */
final class YouTubeComments implements CommentSource
{
    use CallsNetwork;

    private const API = 'https://www.googleapis.com/youtube/v3';

    public function recent(SocialConnection $connection): array
    {
        $token = GoogleToken::forConnection($connection, 'youtube');

        $threads = (array) $this->send('YouTube', fn () => Http::withToken($token)->timeout(15)->get(self::API.'/commentThreads', [
            'part' => 'snippet,replies',
            'allThreadsRelatedToChannelId' => $connection->remote_id,
            'maxResults' => 50,
            'order' => 'time',
        ]))->json('items');

        return array_values(array_map(function (array $t) use ($connection) {
            $top = (array) ($t['snippet']['topLevelComment'] ?? []);
            $s = (array) ($top['snippet'] ?? []);
            $video = (string) ($t['snippet']['videoId'] ?? $s['videoId'] ?? '');

            return $this->row($connection, 'youtube', (string) ($top['id'] ?? $t['id']), (string) ($s['textOriginal'] ?? $s['textDisplay'] ?? ''),
                (string) ($s['authorDisplayName'] ?? ''), $s['publishedAt'] ?? null, ($s['moderationStatus'] ?? 'published') === 'heldForReview',
                ['id' => $video, 'text' => null, 'permalink' => $video === '' ? null : 'https://www.youtube.com/watch?v='.$video],
                array_map(fn (array $r) => [
                    'id' => (string) $r['id'],
                    'text' => (string) ($r['snippet']['textOriginal'] ?? $r['snippet']['textDisplay'] ?? ''),
                    'author' => (string) ($r['snippet']['authorDisplayName'] ?? ''),
                    'timestamp' => $r['snippet']['publishedAt'] ?? null,
                ], (array) ($t['replies']['comments'] ?? [])));
        }, $threads));
    }

    public function actions(SocialConnection $connection): array
    {
        return $this->granted($connection, YouTubeOAuth::SCOPE_MANAGE) ? ['reply', 'hide'] : [];
    }

    public function unavailable(SocialConnection $connection): array
    {
        $why = [];

        if (! $this->granted($connection, YouTubeOAuth::SCOPE_MANAGE)) {
            $why['reply'] = $why['hide'] = 'YouTube needs the manage permission to reply or hide. Reconnect the channel.';
        }
        $why['delete'] = 'YouTube holds comments for review rather than deleting them here.';

        return $why;
    }

    public function reply(SocialConnection $connection, string $commentId, string $message): string
    {
        $token = GoogleToken::forConnection($connection, 'youtube');

        return (string) $this->send('YouTube', fn () => Http::withToken($token)->timeout(15)->asJson()
            ->post(self::API.'/comments?part=snippet', ['snippet' => ['parentId' => $commentId, 'textOriginal' => $message]]))->json('id');
    }

    public function hide(SocialConnection $connection, string $commentId, bool $hide): void
    {
        $token = GoogleToken::forConnection($connection, 'youtube');

        $this->send('YouTube', fn () => Http::withToken($token)->timeout(15)->post(self::API.'/comments/setModerationStatus?'.http_build_query([
            'id' => $commentId,
            'moderationStatus' => $hide ? 'heldForReview' : 'published',
        ])));
    }

    public function delete(SocialConnection $connection, string $commentId): void
    {
        throw new RuntimeException($this->unavailable($connection)['delete']);
    }
}
