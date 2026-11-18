<?php

namespace App\Support\Social\Comments;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/** The shared plumbing of a CommentSource: granted scopes, refusals, row shape. */
trait CallsNetwork
{
    /** A scope the network granted, as recorded on the row at connect. */
    protected function granted(SocialConnection $connection, string $scope): bool
    {
        return in_array($scope, (array) ($connection->credentials['scopes'] ?? []), true);
    }

    /**
     * @param  callable(): Response  $call
     *
     * @throws RuntimeException with a sentence for the screen
     */
    protected function send(string $network, callable $call): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new RuntimeException("{$network} did not answer. Try again in a moment.");
        }

        if ($response->failed()) {
            $why = $response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? $response->json('detail');

            throw new RuntimeException(is_string($why) && $why !== '' ? $why : "{$network} refused that (HTTP ".$response->status().').');
        }

        return $response;
    }

    /**
     * @param  array{id: string, text: ?string, permalink: ?string}  $post
     * @param  list<array<string, mixed>>  $replies
     * @return array<string, mixed>
     */
    protected function row(SocialConnection $connection, string $network, string $id, string $text, string $author, ?string $timestamp, bool $hidden, array $post, array $replies): array
    {
        return [
            'id' => $id,
            'network' => $network,
            'connection_id' => (string) $connection->getKey(),
            'account' => (string) $connection->display_name,
            'post' => $post,
            'author' => $author,
            'text' => $text,
            'timestamp' => $timestamp,
            'hidden' => $hidden,
            'replies' => $replies,
            'actions' => $this->actions($connection),
            'unavailable' => (object) $this->unavailable($connection),
        ];
    }
}
