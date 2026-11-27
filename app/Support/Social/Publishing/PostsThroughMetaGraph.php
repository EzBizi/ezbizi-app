<?php

namespace App\Support\Social\Publishing;

use App\Models\SocialConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The Graph calls Instagram and Threads publishing share: a form POST that
 * turns a dead line or a refusal into the network's SocialPublishFailed, and
 * reading a post's permalink back. Each publisher keeps its own container and
 * status polling, which differ in fields and in how a failure is spelled.
 *
 * The host declares `private const API`, `private const TIMEOUT` and
 * `private const NETWORK` (the name a refusal sentence starts with), and a
 * token(SocialConnection) that refuses in its own words.
 */
trait PostsThroughMetaGraph
{
    abstract private function token(SocialConnection $connection): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function call(string $path, string $token, array $payload): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->asForm()
                ->post(self::API.$path, $payload + ['access_token' => $token]);
        } catch (ConnectionException) {
            throw SocialPublishFailed::network(self::NETWORK, 'EzBizi could not reach it.');
        }

        if ($response->failed()) {
            throw SocialPublishFailed::network(
                self::NETWORK,
                (string) ($response->json('error.message') ?? 'HTTP '.$response->status()),
            );
        }

        return (array) $response->json();
    }

    /**
     * Read back from the network rather than built from the id: a Page post's
     * address is not derivable from its id, and a guessed link that 404s is
     * worse than none.
     */
    public function permalink(SocialConnection $connection, string $remoteId): ?string
    {
        $url = Http::timeout(10)->get(self::API.'/'.$remoteId, [
            'fields' => 'permalink',
            'access_token' => $this->token($connection),
        ])->json('permalink');

        return is_string($url) && str_starts_with($url, 'https://') ? $url : null;
    }
}
