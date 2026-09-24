<?php

namespace App\Services\A1Leads;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Narrow live review-reply transport. The daily snapshot reader above remains GET-only.
 * This uses a separate, reply-only credential and the live loopback tunnel; EzBizi
 * never receives Google's credential or becomes a second Google writer.
 */
class A1LeadsReplyClient
{
    private const PATH = '/api/v1/reviews/reply/by-name';

    public function configured(): bool
    {
        $url = config('services.a1leads.live_reply_url');

        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'http'
            && in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)
            && is_string(config('services.a1leads.live_reply_key'))
            && config('services.a1leads.live_reply_key') !== '';
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function preflight(string $googleReviewName): array
    {
        return $this->request('get', [
            'businessSlug' => 'a1-chimney',
            'googleReviewName' => $googleReviewName,
        ]);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    public function publish(string $googleReviewName, string $text, string $expectedReviewUpdatedAt, string $actorId): array
    {
        return $this->request('post', [
            'businessSlug' => 'a1-chimney',
            'googleReviewName' => $googleReviewName,
            'text' => $text,
            'expectedReviewUpdatedAt' => $expectedReviewUpdatedAt,
            'actorId' => $actorId,
        ]);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function request(string $verb, array $payload): array
    {
        if (! $this->configured()) {
            return $this->failure(503, 'reply_not_configured', 'Review replies are not connected yet.');
        }

        $url = rtrim((string) config('services.a1leads.live_reply_url'), '/');

        try {
            $client = Http::timeout(20)->acceptJson()->withHeaders([
                'X-API-Key' => (string) config('services.a1leads.live_reply_key'),
            ]);
            $response = $verb === 'get'
                ? $client->get($url.self::PATH, $payload)
                : $client->post($url.self::PATH, $payload);
        } catch (ConnectionException) {
            return $verb === 'post'
                ? $this->failure(503, 'reply_outcome_unknown', 'The connection dropped during posting. Check the live review before trying again.')
                : $this->failure(503, 'waiting_for_owner_edge', 'The live A1 Leads connection is unavailable. No reply was sent.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            return $this->failure(502, 'reply_source_invalid', 'A1 Leads returned an unreadable result. Check the live review before trying again.');
        }

        return ['status' => $response->status(), 'body' => $body];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function failure(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => compact('code', 'message')]];
    }
}
