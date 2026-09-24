<?php

namespace App\Http\Controllers\Api;

use App\Services\A1Leads\A1LeadsClient;
use App\Support\Tenancy\TenantContext;
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
    public function __construct(
        private readonly A1LeadsClient $a1leads,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/a1leads-gbp */
    public function index(Request $request): JsonResponse
    {
        $source = $this->source();

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
                filters: $this->filters($request),
            );
            $health = $this->a1leads->gbpHealth()['data'] ?? null;
            $locations = $this->a1leads->locations();
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
                'locations' => [],
            ]]);
        }

        $board = $this->board();

        return response()->json(['data' => [
            'source' => $source,
            'summary' => $summary,
            'reviews' => $this->withBoard($reviews['data'] ?? [], $board),
            'meta' => $reviews['meta'] ?? null,
            'health' => $health,
            'locations' => $locations,
            'board' => ['available' => $board['available'], 'because' => $board['because']],
        ]]);
    }

    /**
     * GET /api/v1/a1leads-gbp/reviews - one filtered page, and nothing else.
     *
     * Separate from `index` because the two are asked at different rates. The
     * tab loads its context once; the list is re-asked on every filter change
     * and every "Load more". Answering those from `index` would spend four
     * upstream requests to change one dropdown, against a service Rodrigo asked
     * us to keep under 120 reads a minute.
     *
     * `source` travels with this one too. A list must never be drawn without
     * the date it was captured on, and the page somebody scrolls to is as
     * capable of being a week old as the first.
     */
    public function reviews(Request $request): JsonResponse
    {
        $source = $this->source();

        if (! $source['configured'] || ! $source['reachable']) {
            return response()->json(['data' => [
                'source' => $source,
                'reviews' => [],
                'meta' => null,
            ]]);
        }

        try {
            $reviews = $this->a1leads->reviews(
                limit: (int) $request->integer('limit', 20),
                offset: (int) $request->integer('offset', 0),
                filters: $this->filters($request),
            );
        } catch (RuntimeException $e) {
            return response()->json(['data' => [
                'source' => [...$source, 'reachable' => false, 'because' => $e->getMessage()],
                'reviews' => [],
                'meta' => null,
            ]]);
        }

        $board = $this->board();

        return response()->json(['data' => [
            'source' => $source,
            'reviews' => $this->withBoard($reviews['data'] ?? [], $board),
            'meta' => $reviews['meta'] ?? null,
            'board' => ['available' => $board['available'], 'because' => $board['because']],
        ]]);
    }

    /**
     * Whether the board's columns could be read, and why not when they could not.
     *
     * The board is ENRICHMENT: the reviews are the page, Status and Notes are
     * columns beside them. So a failure here - most likely the one policy entry
     * being removed, which is exactly how Rodrigo said to revoke it - leaves the
     * reviews standing and says the columns are unavailable, rather than
     * taking the whole tab down with a 403 it did not need to.
     *
     * @return array{available: bool, because: ?string, cards: array<string, array<string, mixed>>}
     */
    private function board(): array
    {
        try {
            return ['available' => true, 'because' => null, 'cards' => $this->a1leads->cards()];
        } catch (RuntimeException $e) {
            return ['available' => false, 'because' => $e->getMessage(), 'cards' => []];
        }
    }

    /**
     * Each review with its board card beside it, when it has one.
     *
     * Joined on `google_review_name` = the card's `review_ref`. Most reviews
     * have no card - the board holds the few hundred bad ones out of thirteen
     * thousand - and `board: null` says that, which is different from a card
     * whose status is blank.
     *
     * @param  array<int, mixed>  $reviews
     * @param  array{cards: array<string, array<string, mixed>>}  $board
     * @return array<int, mixed>
     */
    private function withBoard(array $reviews, array $board): array
    {
        return array_map(function ($review) use ($board) {
            if (! is_array($review)) {
                return $review;
            }

            $ref = $review['google_review_name'] ?? null;

            return [...$review, 'board' => is_string($ref) ? ($board['cards'][$ref] ?? null) : null];
        }, $reviews);
    }

    /**
     * ONE ORGANIZATION MAY READ THIS, and it is named in configuration.
     *
     * A1 Leads holds one business's reviews - A-1 Chimney's - and this module
     * is available to every organization by default, because a module with no
     * platform flag fails OPEN (see `ModuleAvailability`). Without this, an
     * administrator at any other tenant could grant themselves
     * `a1leads_gbp.view` from their own Roles screen and read thirteen thousand
     * of somebody else's reviews. The permission is not the boundary here: the
     * data belongs to one organization, and nothing a role can say expresses
     * that.
     *
     * Unset fails CLOSED, unlike the module flag, and for the opposite reason.
     * An absent flag means nobody has restricted the module; an absent
     * organization here means nobody has said whose data this is.
     *
     * @return array<string, mixed>
     */
    private function source(): array
    {
        $allowed = config('services.a1leads.organization_id');

        if (! is_string($allowed) || $allowed === '' || $this->tenant->id() !== $allowed) {
            return [
                'configured' => false,
                'reachable' => false,
                'captured_at' => null,
                'installed_at' => null,
                'generation' => null,
                'hours_old' => null,
                'stale' => false,
                'because' => 'A1 Leads holds one business\'s Business Profile, and this '
                    .'organization is not the one it belongs to.',
            ];
        }

        return $this->a1leads->source();
    }

    /**
     * The filters the screen may pass through, read off the request.
     *
     * Named here rather than forwarded wholesale. The client drops anything it
     * does not recognise, and a request carrying a filter neither side
     * understands should fail to narrow the list rather than quietly widen it.
     *
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->only(['city', 'location', 'rating', 'replied', 'matched', 'from', 'to', 'sort']);
    }
}
