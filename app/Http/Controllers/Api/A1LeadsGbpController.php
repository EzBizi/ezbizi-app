<?php

namespace App\Http\Controllers\Api;

use App\Services\A1Leads\A1LeadsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The A1Leads GBP tab's only endpoint.
 *
 * ── What this is, and what it is careful not to be ───────────────
 *
 * A window onto Rodrigo's A1 Leads while EzBizi has no Business Profile API
 * access of its own. It reads; it writes nothing, to Google or to us. EzBizi's
 * own Reviews and Business Profile modules are untouched and live in Marketing
 * — this endpoint must never be confused for them, which is why it answers
 * under its own module's permission and its own route.
 *
 * ── Freshness travels with the data, always ──────────────────────
 *
 * Every response carries `source`, and the screen draws it above everything
 * else. The reason is specific rather than tidy: the data is a daily snapshot,
 * and a snapshot whose refresh has failed looks exactly like a business having
 * a quiet week. A list with no date on it cannot tell those apart, and the
 * wrong reading is the expensive one.
 */
class A1LeadsGbpController extends Controller
{
    public function __construct(private readonly A1LeadsClient $a1leads) {}

    /** GET /api/v1/a1leads-gbp */
    public function index(Request $request): JsonResponse
    {
        $source = $this->a1leads->source();

        /*
         * The source is answered even when it is unusable, and that is the
         * point of returning early rather than throwing.
         *
         * "Not configured" and "configured but unreachable" are different
         * sentences for the person reading the tab: one is waiting on a key,
         * the other is waiting on a service. Both are different again from "no
         * reviews", which is the one thing this must never appear to say — the
         * business has thirteen thousand of them.
         */
        if (! $source['configured'] || ! $source['reachable']) {
            return response()->json(['data' => [
                'source' => $source,
                'summary' => null,
                'reviews' => [],
                'health' => null,
            ]]);
        }

        try {
            $summary = $this->a1leads->reviewsSummary()['data'] ?? null;
            $reviews = $this->a1leads->reviews(
                limit: (int) $request->integer('limit', 20),
                offset: (int) $request->integer('offset', 0),
            );
            $health = $this->a1leads->gbpHealth()['data'] ?? null;
        } catch (RuntimeException $e) {
            /*
             * A refusal from the other side is reported as a source problem,
             * not as an empty result.
             *
             * The likeliest cause by far is the consumer policy: five routes
             * are allowed and a sixth answers 403. Turning that into an empty
             * list would hide a configuration fault behind a plausible screen.
             */
            return response()->json(['data' => [
                'source' => [...$source, 'reachable' => false, 'because' => $e->getMessage()],
                'summary' => null,
                'reviews' => [],
                'health' => null,
            ]]);
        }

        return response()->json(['data' => [
            'source' => $source,
            'summary' => $summary,
            'reviews' => $reviews['data'] ?? [],
            'meta' => $reviews['meta'] ?? null,
            'health' => $health,
        ]]);
    }
}
