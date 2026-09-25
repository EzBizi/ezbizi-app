<?php

namespace App\Http\Controllers\Api;

use App\Models\A1LeadsReplyReceipt;
use App\Models\User;
use App\Services\A1Leads\A1LeadsClient;
use App\Services\A1Leads\A1LeadsGbpReplies;
use App\Services\A1Leads\A1LeadsOwner;
use App\Services\A1Leads\A1LeadsReplyClient;
use App\Services\A1Leads\A1LeadsReviewOpsClient;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
        private readonly A1LeadsReplyClient $replies,
        private readonly A1LeadsGbpReplies $publisher,
        private readonly TenantContext $tenant,
    ) {}

    /** GET /api/v1/a1leads-gbp */
    public function index(Request $request): JsonResponse
    {
        $source = $this->source($request);

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
                'reply_available' => false,
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
                'reply_available' => false,
                'summary' => null,
                'reviews' => [],
                'health' => null,
                'locations' => [],
            ]]);
        }

        $board = $this->board();
        $replyBecause = $this->replyUnavailableBecause($request);

        return response()->json(['data' => [
            'source' => $source,
            'reply_available' => $replyBecause === null,
            // WHY there is no Reply button, so the screen names the real cause:
            // "not switched on" is a deployment waiting on its key, "company
            // not in view" is something the reader can change themselves.
            'reply_unavailable_because' => $replyBecause,
            // Whether the card, match, report and draft actions are connected
            // (their own key, Rodrigo's review-ops routes). The screen draws
            // none of them without it.
            'ops_available' => app(A1LeadsReviewOpsClient::class)->configured(),
            'summary' => $summary,
            'reviews' => $this->withBoard($this->withReplyReceipts($reviews['data'] ?? []), $board),
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
        $source = $this->source($request);

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
            'reviews' => $this->withBoard($this->withReplyReceipts($reviews['data'] ?? []), $board),
            'meta' => $reviews['meta'] ?? null,
            'board' => ['available' => $board['available'], 'because' => $board['because']],
        ]]);
    }

    /**
     * GET /api/v1/a1leads-gbp/reply/preflight?googleReviewName=...
     *
     * ── Replies, and why they go somewhere the reads do not ─────────
     *
     * Rodrigo's handoff of 24 September (A1 Leads `421fa5ef`, patch SHA-256
     * 67D54389...ADEFAF) authorises an EzBizi operator to publish a reply to an
     * A-1 Chimney review. A1 Leads stays the only Google writer: EzBizi never
     * holds Google's credential. The reply goes to his LIVE A1 Leads through a
     * loopback tunnel (127.0.0.1:3457) with its own reply-only key - never to
     * the daily snapshot on :3456, which is read-only by design and whose key
     * this never uses.
     *
     * The preflight reads that exact review from live Google before an editor
     * may publish. The daily snapshot is never proof a review is still
     * unanswered or unchanged: the reply is sent against the live
     * `reviewUpdatedAt`, and A1 Leads refuses it if the reviewer edited since.
     */
    public function replyPreflight(Request $request): JsonResponse
    {
        if ($refusal = $this->replyScopeRefusal($request)) {
            return $refusal;
        }

        $name = trim((string) $request->query('googleReviewName', ''));
        if (! $this->validReviewName($name)) {
            return $this->replyError(422, 'invalid_review_name', 'Choose a review from this page.');
        }

        return $this->replyResult($this->replies->preflight($name));
    }

    /**
     * POST /api/v1/a1leads-gbp/reply - one public Google reply, by exact review.
     *
     * The actor is the signed-in user, derived here: an `actorId`,
     * `businessSlug`, URL or key in the request body is ignored, so nobody can
     * post as somebody else or for another business by editing a request.
     *
     * Every reply Google accepts is written to OUR audit trail as
     * `a1leads_gbp.review_replied` - it is public, permanent, and under the
     * business's name, and "who answered that review" must have an answer here,
     * not only in A1 Leads' log.
     */
    public function publishReply(Request $request): JsonResponse
    {
        $name = trim((string) $request->input('googleReviewName', ''));

        /*
         * WHO MAY REPLY: whoever holds `a1leads_gbp.reply`. Seeing A-1's
         * reviews does not include answering them in public - Jose, 2026-09-25,
         * on Rodrigo's cutover checklist: "Separate Reply permission". (For one
         * day, 24-25 September, the view permission covered replying.)
         *
         * Checked HERE rather than by route middleware so that a refusal can
         * be audited: Rodrigo's contract audits reply ATTEMPTS, and a
         * middleware refusal happens before any code of ours runs.
         */
        if (! $request->user()?->hasPermission('a1leads_gbp.reply')) {
            return $this->refusedAttempt($name, $this->replyError(403, 'no_permission',
                'Replying to A-1 Chimney\'s reviews needs the A1Leads GBP Reply permission.'));
        }

        if ($refusal = $this->replyScopeRefusal($request)) {
            return $this->refusedAttempt($name, $refusal);
        }

        $expected = trim((string) $request->input('expectedReviewUpdatedAt', ''));
        if ($expected === '') {
            // A person replies to the revision the editor showed them. Only an
            // automation, which saw nothing, may leave it to be read live.
            return $this->refusedAttempt($name, $this->replyError(422, 'missing_review_revision', 'Check the live review again before sending.'));
        }

        // The one path to Google, shared with Automations: it validates,
        // posts, writes the receipt and audits every outcome.
        return $this->replyResult($this->publisher->publish(
            $name,
            (string) $request->input('text', ''),
            $expected,
            $request->user(),
        ));
    }

    /**
     * Record a reply attempt that did NOT end in a confirmed send, and return
     * the refusal unchanged.
     *
     * Who tried, which review, the status and the machine code - and never the
     * reply text. A refused reply was not published, so the words are not the
     * business's public statement; they are a draft that belongs to nobody but
     * its writer. `reply_outcome_unknown` is recorded here too: it means the
     * connection dropped during the POST, and the audit must not read as
     * "nothing happened" when Google may have accepted it.
     */
    private function refusedAttempt(string $name, JsonResponse $response): JsonResponse
    {
        $this->publisher->refused(
            $name,
            ['status' => $response->getStatusCode(), 'body' => $response->getData(true)],
            request()->user(),
        );

        return $response;
    }

    private function replyScopeRefusal(Request $request): ?JsonResponse
    {
        $source = $this->source($request);
        if (! $source['configured']) {
            return $this->replyError(403, 'wrong_organization', (string) $source['because']);
        }

        $companyId = config('services.a1leads.company_id');
        if (! is_string($companyId) || $companyId === '') {
            return $this->replyError(503, 'reply_company_not_configured', 'Review replies are not enabled for this company yet.');
        }

        $user = $request->user();
        if ($user === null || ! $user->hasPermission('a1leads_gbp.reply')
            || ! $this->companyInView($user, $companyId)) {
            return $this->replyError(403, 'wrong_company_view', 'Switch to the A1 Chimney company to reply to its reviews.');
        }

        return null;
    }

    /**
     * Why this person, here, now, may NOT be offered a Reply button - or null
     * when they may.
     *
     * Every condition the endpoints enforce, so the screen never draws a button
     * whose only outcome is a refusal: the live connection configured, the
     * company named, the A1Leads GBP permission held, and A-1 Chimney the
     * company in view. Returned as a reason rather than a bool because the
     * screen used to blame the company in view for all of them - including
     * for somebody who had A-1 Chimney in view while replying was simply off.
     */
    private function replyUnavailableBecause(Request $request): ?string
    {
        $companyId = config('services.a1leads.company_id');
        $user = $request->user();

        if (! $this->replies->configured() || ! is_string($companyId) || $companyId === '') {
            return 'not_switched_on';
        }
        if ($user === null || ! $user->hasPermission('a1leads_gbp.reply')) {
            return 'no_permission';
        }
        if (! $this->companyInView($user, $companyId)) {
            return 'company_not_in_view';
        }

        return null;
    }

    private function companyInView(User $user, string $companyId): bool
    {
        return A1LeadsOwner::inView($user, $companyId);
    }

    /**
     * Keep a confirmed live send visible until the daily snapshot catches up.
     *
     * The snapshot is a day behind, so a reply posted at 10:00 would read as
     * "No reply" until tomorrow's install - inviting a second, duplicate reply.
     * The receipt overlays it, and gives way the moment the snapshot carries a
     * reply newer than the receipt.
     *
     * @param  array<int, mixed>  $reviews
     * @return array<int, mixed>
     */
    private function withReplyReceipts(array $reviews): array
    {
        if ($reviews === []) {
            return $reviews;
        }

        $names = array_values(array_filter(array_map(
            fn ($review) => is_array($review) ? ($review['google_review_name'] ?? null) : null,
            $reviews,
        ), 'is_string'));
        if ($names === []) {
            return $reviews;
        }

        $receipts = A1LeadsReplyReceipt::query()
            ->where('organization_id', $this->tenant->idOrFail())
            ->whereIn('google_review_name', $names)
            ->get()->keyBy('google_review_name');

        foreach ($reviews as &$review) {
            if (! is_array($review)) {
                continue;
            }
            $receipt = $receipts->get($review['google_review_name'] ?? '');
            if ($receipt === null) {
                continue;
            }
            $snapshotSentAt = $review['reply_at'] ?? null;
            if ($snapshotSentAt !== null && $receipt->sent_at !== null
                && Carbon::parse($snapshotSentAt)->greaterThan($receipt->sent_at)) {
                continue;
            }

            $sentAt = $receipt->sent_at?->toIso8601String();
            $review['reply'] = $receipt->reply_text;
            $review['reply_at'] = $sentAt;
            $review['review_status'] = [
                ...(is_array($review['review_status'] ?? null) ? $review['review_status'] : []),
                'reply' => 'sent',
                'replied_at' => $sentAt,
            ];
        }
        unset($review);

        return $reviews;
    }

    private function validReviewName(string $name): bool
    {
        return strlen($name) <= 300
            && preg_match('#^accounts/[^/]+/locations/[^/]+/reviews/[^/]+$#D', $name) === 1;
    }

    /** @param array{status: int, body: array<string, mixed>} $result */
    private function replyResult(array $result): JsonResponse
    {
        return response()->json($result['body'], $result['status']);
    }

    private function replyError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json(['error' => compact('code', 'message')], $status);
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
    private function source(Request $request): array
    {
        $allowed = config('services.a1leads.organization_id');

        if (! is_string($allowed) || $allowed === '' || $this->tenant->id() !== $allowed) {
            return $this->notHere('A1 Leads holds one business\'s Business Profile, and this '
                .'organization is not the one it belongs to.');
        }

        /*
         * THE COMPANY, not only the organization. Jose, 2026-09-24: "this
         * belongs to a-1chimney" - with Everdoors or Crestline Home in view the
         * tab showed A-1 Chimney's reviews as if they were theirs. The rule is
         * the one replying already used: the organization view (which contains
         * A-1 Chimney), A-1 Chimney itself, or one of its franchises. Unnamed
         * fails CLOSED, for the same reason the organization does.
         */
        $company = config('services.a1leads.company_id');
        $user = $request->user();
        if (! is_string($company) || $company === '' || $user === null || ! $this->companyInView($user, $company)) {
            return $this->notHere('These are A-1 Chimney\'s Google reviews. Switch to A-1 Chimney to see them.');
        }

        return $this->a1leads->source();
    }

    /** @return array<string, mixed> */
    private function notHere(string $because): array
    {
        return [
            'configured' => false,
            'reachable' => false,
            'captured_at' => null,
            'installed_at' => null,
            'generation' => null,
            'hours_old' => null,
            'stale' => false,
            'because' => $because,
        ];
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
