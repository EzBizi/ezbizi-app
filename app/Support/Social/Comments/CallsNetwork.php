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
            $why = is_string($why) ? $why : '';
            $code = (int) $response->json('error.code');

            // The screen shows this sentence as is (Jose 11-24: no raw Graph errors).
            // ponytail: once Meta approves, an old token still gets #10 until the Page is reconnected; reword then.
            throw new RuntimeException(match (true) {
                $code === 10 || ($code >= 200 && $code < 300) => "{$network} comments need a Meta permission that EzBizi is still getting approved.",
                $code === 190 => "{$network} signed this account out. Reconnect it from Integrations.",
                $response->status() === 402 || str_contains(strtolower($why), 'credits') => "{$network} comments are paused: the {$network} API credits ran out.",
                $why !== '' && ! str_starts_with($why, '(#') => $why,
                default => "{$network} refused that (HTTP ".$response->status().').',
            });
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
