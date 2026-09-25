<?php

namespace App\Services\A1Leads;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * A1 Leads' review-operations routes: match, draft, workflow card, report.
 *
 * Rodrigo's handoff of 25 September: seven narrow routes on his LIVE A1 Leads,
 * reached through the loopback tunnel (127.0.0.1:3457) with their own identity,
 * `ezbizi:review-ops` - never the daily read key and never the public reply
 * key, which cannot call these and which these cannot call. None of them posts
 * to Google: a public reply still goes only through `A1LeadsReplyClient`.
 *
 * EzBizi checks its own permissions before calling, and names the signed-in
 * person as `actorId` on every write - never a name the browser sent.
 *
 * Every method returns `['status' => int, 'body' => array]`, A1 Leads' own
 * answer (`data` / `meta`, or `error: {code, message}`), and never throws.
 */
class A1LeadsReviewOpsClient
{
    private const BUSINESS = 'a1-chimney';

    public function configured(): bool
    {
        $url = config('services.a1leads.review_ops_url');

        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL) !== false
            && parse_url($url, PHP_URL_SCHEME) === 'http'
            && in_array(parse_url($url, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)
            && is_string(config('services.a1leads.review_ops_key'))
            && config('services.a1leads.review_ops_key') !== '';
    }

    /** Changes since `$cursor`, in sequence order. Reads the owner's database; no Google quota. */
    public function delta(int $cursor, int $limit = 200): array
    {
        return $this->request('get', '/api/v1/ezbizi/reviews/delta', [
            'businessSlug' => self::BUSINESS,
            'cursor' => max(0, $cursor),
            'limit' => max(1, min($limit, 500)),
        ]);
    }

    /** A1 Leads' cached, business-scoped Workiz suggestions for one review. */
    public function suggestions(int $reviewId, string $query = ''): array
    {
        return $this->request('get', "/api/v1/ezbizi/reviews/{$reviewId}/suggestions", array_filter([
            'q' => trim($query) !== '' ? trim($query) : null,
        ]));
    }

    /** Pin a review to one Workiz job, or clear the pin so A1 Leads can rematch. */
    public function match(int $reviewId, string $actorId, ?string $tenant, ?string $jobId, bool $clear = false): array
    {
        return $this->request('post', "/api/v1/ezbizi/reviews/{$reviewId}/match", $clear
            ? ['actorId' => $actorId, 'clear' => true]
            : ['actorId' => $actorId, 'tenant' => (string) $tenant, 'jobId' => (string) $jobId]);
    }

    /** A1 Leads' current draft reply. Saves nothing and posts nothing. */
    public function draft(int $reviewId, string $actorId): array
    {
        return $this->request('post', "/api/v1/ezbizi/reviews/{$reviewId}/draft", ['actorId' => $actorId]);
    }

    /**
     * ONE change to a workflow card: `status`, `assignee` or `add_update`.
     *
     * @param  array{status?: string, assignee?: string, add_update?: string}  $change
     */
    public function markCard(string $googleReviewName, string $actorId, array $change): array
    {
        return $this->request('post', '/api/v1/ezbizi/reviews/cards/mark', [
            'actorId' => $actorId,
            'ref' => $googleReviewName,
            ...$change,
        ]);
    }

    /** @param  int[]  $reviewIds */
    public function queueReport(array $reviewIds, string $reason, string $actorId): array
    {
        return $this->request('post', '/api/v1/ezbizi/reviews/report/queue', [
            'businessSlug' => self::BUSINESS,
            'actorId' => $actorId,
            'reviewIds' => array_values(array_map('intval', $reviewIds)),
            'reason' => $reason,
            'confirmed' => true,
        ]);
    }

    public function reportStatus(): array
    {
        return $this->request('get', '/api/v1/ezbizi/reviews/report/status', ['businessSlug' => self::BUSINESS]);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function request(string $verb, string $path, array $payload): array
    {
        if (! $this->configured()) {
            return self::failure(503, 'review_ops_not_configured', 'Review actions are not connected to A1 Leads yet.');
        }

        $url = rtrim((string) config('services.a1leads.review_ops_url'), '/').$path;

        try {
            $client = Http::timeout(20)->acceptJson()->withHeaders([
                'X-API-Key' => (string) config('services.a1leads.review_ops_key'),
            ]);
            $response = $verb === 'get' ? $client->get($url, $payload) : $client->post($url, $payload);
        } catch (ConnectionException) {
            return $verb === 'post'
                ? self::failure(503, 'outcome_unknown', 'The connection to A1 Leads dropped mid-change. Reload to see whether it took.')
                : self::failure(503, 'waiting_for_owner_edge', 'The live A1 Leads connection is unavailable.');
        }

        $body = $response->json();
        if (! is_array($body)) {
            return self::failure(502, 'review_ops_invalid', 'A1 Leads returned an unreadable answer.');
        }

        return ['status' => $response->status(), 'body' => $body];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private static function failure(int $status, string $code, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => compact('code', 'message')]];
    }
}
