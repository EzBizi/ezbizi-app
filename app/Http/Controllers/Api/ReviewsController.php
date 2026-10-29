<?php

namespace App\Http\Controllers\Api;

use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Review;
use App\Models\ReviewNote;
use App\Models\ReviewReply;
use App\Models\SocialConnection;
use App\Services\Reviews\BusinessProfileReviews;
use App\Support\Ai\GrokCli;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reviews on a connected Business Profile, and the replies to them.
 *
 * Read and answer. Nothing here writes a review — every row is a member of the
 * public's own words, fetched from Google, and an endpoint that could edit them
 * would turn a cached public record into something the business can quietly
 * revise.
 *
 * ── Drafting and publishing are different endpoints on purpose ──
 *
 * `PUT .../reply` writes text nobody outside the building can see.
 * `POST .../reply/publish` puts it on Google under the organization's name, in
 * public, permanently. They carry different permissions (`reviews.edit` and
 * `reviews.publish`) because they are routinely different people: whoever
 * composes the answer to an angry review is very often not whoever should
 * decide it goes out.
 */
class ReviewsController extends Controller
{
    public function __construct(
        private readonly BusinessProfileReviews $google,
        private readonly SocialConnectionAccess $access,
        private readonly AuditLogger $audit,
        private readonly EventEmitter $events,
    ) {}

    /** GET /api/v1/reviews */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);

        $user = $request->user();

        $query = Review::query()
            ->with(['reply', 'company:id,name', 'franchise:id,name', 'customer', 'job.performedBy'])
            ->withCount('notes');

        \App\Support\CustomerExperience\Places::scope($query, $user);

        // The filter the work actually uses. Somebody opening this tab is
        // usually looking for the ones nobody has answered, not browsing.
        if ($request->query('answered') === 'no') {
            $query->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED));
        }

        $rating = $request->query('rating');
        if (is_numeric($rating)) {
            $query->where('rating', (int) $rating);
        } elseif ($rating === 'positive') {
            $query->where('rating', '>=', 4);
        } elseif ($rating === 'negative' || $rating === 'low') {
            $query->where('rating', '<=', 3);
        }

        $replied = $request->query('replied');
        if ($replied === 'yes') {
            $query->whereHas('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED));
        } elseif ($replied === 'no') {
            $query->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED));
        }

        // A location is its `locations/<id>`: the account part differs by grant.
        if (is_string($location = $request->query('location')) && $location !== '') {
            $query->where('location_name', 'like', '%/'.addcslashes($location, '%_'));
        }

        match ($request->query('matched')) {
            'yes'   => $query->whereNotNull('customer_id'),
            'no'    => $query->whereNull('customer_id'),
            default => null,
        };

        if (in_array($status = $request->query('status'), Review::STATUSES, true)) {
            $query->where('status', $status);
        }

        // The search bar: who wrote it, what they said, or the customer it matched.
        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('reviewer_name', 'like', $like)
                ->orWhere('comment', 'like', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', $like)));
        }

        // This month and last, under every filter but the dates (UI design 145).
        $stats = $this->stats(clone $query);

        if (is_string($from = $request->query('from')) && strtotime($from) !== false) {
            $query->where('reviewed_at', '>=', Carbon::parse($from)->startOfDay());
        }

        $locations = $this->locations(clone $query);

        // UI queue 101 (2026-10-16): newest, oldest, highest, lowest.
        match ($request->query('sort')) {
            'oldest'  => $query->orderBy('reviewed_at'),
            'highest' => $query->orderByDesc('rating')->orderByDesc('reviewed_at'),
            'lowest'  => $query->orderBy('rating')->orderByDesc('reviewed_at'),
            default   => $query->orderByDesc('reviewed_at'),
        };
        $query->orderByDesc('created_at');

        $reviews = $query->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => array_map(fn (Review $r) => $this->present($r), $reviews->items()),
            'meta' => [
                ...$this->pageMeta($reviews),
                'unanswered'   => $this->unansweredCount($user),
                'locations'    => $locations,
                'stats'        => $stats,
                'connections'  => $this->access->presentableInView($user, SocialProvider::GoogleBusinessProfile),
            ],
        ]);
    }

    /** GET /api/v1/reviews/{review} */
    public function show(Review $review): JsonResponse
    {
        $this->authorize('view', $review);

        return response()->json([
            'data' => [
                ...$this->present($review->load(['reply', 'company:id,name', 'franchise:id,name', 'customer', 'job.performedBy'])->loadCount('notes')),
                'notes' => $review->notes()->with('user:id,name')->get()->map(fn (ReviewNote $n) => [
                    'id' => $n->id, 'at' => $n->created_at?->toIso8601String(), 'by' => $n->user?->name, 'text' => $n->body,
                ])->all(),
            ],
        ]);
    }

    /** PATCH /api/v1/reviews/{review} {status} - where working it has got to. */
    public function update(Request $request, Review $review): JsonResponse
    {
        $this->authorize('manage', $review);
        $input = $request->validate(['status' => ['present', 'nullable', Rule::in(Review::STATUSES)]]);
        $review->forceFill(['status' => $input['status']])->save();

        return $this->show($review);
    }

    /** POST /api/v1/reviews/{review}/notes {text} */
    public function addNote(Request $request, Review $review): JsonResponse
    {
        $this->authorize('manage', $review);
        $input = $request->validate(['text' => ['required', 'string', 'max:4000']]);
        $review->notes()->create(['organization_id' => $review->organization_id,
            'user_id' => $request->user()->getKey(), 'body' => trim($input['text']), 'created_at' => now()]);

        return $this->show($review);
    }

    /**
     * GET /api/v1/reviews/{review}/customer-suggestions?q= - customers whose
     * name has every word of the reviewer's name (or q). A suggestion, never a
     * match: a person picks.
     */
    public function customerSuggestions(Request $request, Review $review): JsonResponse
    {
        $this->authorize('manage', $review);
        $q = trim((string) ($request->query('q') ?? $review->reviewer_name ?? ''));
        $words = array_slice(array_filter(preg_split('/\s+/', $q) ?: []), 0, 3);
        if ($words === []) {
            return response()->json(['data' => []]);
        }

        $query = Customer::query();
        foreach ($words as $word) {
            $query->where('name', 'like', '%'.addcslashes($word, '%_').'%');
        }

        return response()->json(['data' => $query->orderBy('name')->limit(10)->get(['id', 'name', 'phone', 'email'])
            ->map(fn (Customer $c) => $c->only(['id', 'name', 'phone', 'email']))->all()]);
    }

    /** PUT /api/v1/reviews/{review}/customer {customer_id|null} - a person's match; the job follows. */
    public function setCustomer(Request $request, Review $review): JsonResponse
    {
        $this->authorize('manage', $review);
        $input = $request->validate(['customer_id' => ['present', 'nullable', 'uuid']]);
        $customer = $input['customer_id'] === null ? null : Customer::query()->find($input['customer_id']);
        if ($input['customer_id'] !== null && $customer === null) {
            return response()->json(['message' => 'That customer could not be found.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $review->forceFill([
            'customer_id' => $customer?->getKey(),
            'customer_match' => $customer === null ? null : 'manual',
            'job_id' => $customer === null ? null : self::jobFor($customer, $review),
        ])->save();

        return $this->show($review);
    }

    /** The customer's job last scheduled before the review, else their latest. */
    public static function jobFor(Customer $customer, Review $review): ?string
    {
        $jobs = Job::query()->where('customer_id', $customer->getKey())->orderByDesc('scheduled_for');

        return (clone $jobs)->when($review->reviewed_at, fn ($q, $at) => $q->where('scheduled_for', '<=', $at))->value('id')
            ?? $jobs->value('id');
    }

    /**
     * POST /api/v1/reviews/{review}/report {reason}. The Business Profile API
     * cannot flag a review (v4 reviews has list, get, updateReply and
     * deleteReply only - checked 2026-10-16), so this records that it was
     * reported and why; the report itself is made on Google's own page.
     */
    public function report(Request $request, Review $review): JsonResponse
    {
        $this->authorize('manage', $review);
        $input = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $review->forceFill(['reported_at' => now(), 'report_reason' => trim($input['reason'])])->save();

        return $this->show($review);
    }

    /**
     * Average rating and count for this month and last, in the app's time zone.
     *
     * @return array{this_month: array{average: ?float, count: int}, last_month: array{average: ?float, count: int}}
     */
    private function stats($query): array
    {
        $month = fn (Carbon $start) => (clone $query)->reorder()->whereNotNull('rating')
            ->whereBetween('reviewed_at', [$start, $start->copy()->endOfMonth()])
            ->toBase()->select(DB::raw('avg(rating) as average, count(*) as n'))->first();
        $now = Carbon::now()->startOfMonth();

        $months = collect(['this_month' => $now, 'last_month' => $now->copy()->subMonth()])
            ->map(function (Carbon $start) use ($month) {
                $row = $month($start);

                return ['average' => $row?->n ? round((float) $row->average, 2) : null, 'count' => (int) ($row->n ?? 0)];
            })->all();

        // Design 154 #7: histogram, who wrote the replies, and the queue, under the same filters.
        $histogram = (clone $query)->reorder()->whereNotNull('rating')->toBase()
            ->select('rating', DB::raw('count(*) as n'))->groupBy('rating')->pluck('n', 'rating');
        $replies = ReviewReply::query()->whereIn('review_id', (clone $query)->reorder()->select('reviews.id'))
            ->toBase()->select('status', 'drafted_by', DB::raw('count(*) as n'))->groupBy('status', 'drafted_by')->get();
        $published = $replies->where('status', ReviewReply::STATUS_PUBLISHED);

        // The last twelve months, oldest first, for the monthly chart.
        $since = $now->copy()->subMonths(11);
        $byMonth = (clone $query)->reorder()->whereNotNull('rating')->where('reviewed_at', '>=', $since)->get(['rating', 'reviewed_at'])
            ->groupBy(fn ($r) => $r->reviewed_at->format('Y-m'));
        $monthly = collect(range(0, 11))->map(function ($i) use ($since, $byMonth) {
            $key = $since->copy()->addMonths($i)->format('Y-m');
            $set = $byMonth->get($key, collect());

            return ['month' => $key, 'count' => $set->count(), 'average' => $set->isEmpty() ? null : round($set->avg('rating'), 2)];
        })->all();

        return $months + [
            'monthly' => $monthly,
            'histogram' => collect(range(1, 5))->mapWithKeys(fn ($r) => [$r => (int) ($histogram[$r] ?? 0)])->all(),
            'replies' => [
                'published' => (int) $published->sum('n'),
                'by_ai' => (int) $published->whereIn('drafted_by', [ReviewReply::BY_DRAFTING_JOB, ReviewReply::BY_AUTOMATION])->sum('n'),
                'drafts' => (int) $replies->where('status', ReviewReply::STATUS_DRAFT)->sum('n'),
                'failed' => (int) $replies->where('status', ReviewReply::STATUS_FAILED)->sum('n'),
            ],
        ];
    }

    /** @return list<array{id: string, name: string}> each `locations/<id>` in the list, named. */
    private function locations($query): array
    {
        $tail = fn ($n) => preg_match('~(locations/[^/]+)$~', (string) $n, $m) ? $m[1] : null;
        $keys = $query->reorder()->distinct()->pluck('location_name')->map($tail)->filter()->unique()->values();
        $titles = BusinessLocation::query()->get(['remote_name', 'title', 'city', 'state'])
            ->mapWithKeys(fn ($l) => [(string) $tail($l->remote_name) => $l->label()]);

        return $keys->map(fn ($k) => ['id' => $k, 'name' => $titles[$k] ?? $k])->sortBy('name')->values()->all();
    }

    /**
     * PUT /api/v1/reviews/{review}/reply
     *
     * Write or replace the draft. Never publishes — a person still has to say
     * so, in a separate request, holding a different permission.
     */
    public function draftReply(Request $request, Review $review): JsonResponse
    {
        $this->authorize('update', $review);

        $input = $request->validate([
            // Google's own ceiling. Refusing here with a sentence beats letting
            // somebody write four hundred words and learn it was too long from
            // a 400 at publish time.
            'body' => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        $reply = $review->reply;

        if ($reply !== null && ! $reply->isEditable()) {
            return response()->json([
                'message' => 'This reply is already published on Google. Publishing a new one '
                    .'replaces it — Google allows a business one reply per review.',
            ], Response::HTTP_CONFLICT);
        }

        $reply = DB::transaction(function () use ($review, $reply, $input) {
            $reply ??= new ReviewReply([
                'organization_id' => $review->organization_id,
                'review_id'       => $review->getKey(),
                'drafted_by'      => ReviewReply::BY_PERSON,
            ]);

            $reply->fill([
                'body'   => $input['body'],
                'status' => ReviewReply::STATUS_DRAFT,
                // A previous failure is not carried forward onto a new attempt;
                // leaving it would have the screen explain a refusal of text
                // that is no longer there.
                'failure_reason' => null,
            ]);

            $reply->save();

            $this->audit->log('review.reply_drafted', $review, metadata: [
                'length' => strlen($input['body']),
            ]);

            return $reply;
        });

        return response()->json(['data' => $this->present($review->fresh()->load('reply'))]);
    }

    /**
     * POST /api/v1/reviews/{review}/reply/suggest
     *
     * Grok's suggested words, returned and not saved (Jose, 2026-10-12, AI
     * item 11). They go into the box for a person to edit, save and publish.
     */
    public function suggestReply(Review $review): JsonResponse
    {
        $this->authorize('update', $review);

        try {
            $body = \App\Support\Reviews\ReviewReplyWriter::write($review);
        } catch (RuntimeException $e) {
            return response()->json(['message' => GrokCli::configured()
                ? 'AI could not draft a reply just now. Try again in a minute.'
                : $e->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json(['data' => ['body' => $body]]);
    }

    /**
     * POST /api/v1/reviews/replies/draft-batch {ids: [review id]} (A1 Leads takeover #22).
     * AI drafts for unanswered reviews, saved as drafts for a person to post; up to 20 at a time.
     */
    public function draftBatch(Request $request): JsonResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:20'], 'ids.*' => ['uuid']])['ids'];
        $results = [];
        foreach (Review::query()->whereKey($ids)->with('reply')->get() as $review) {
            $this->authorize('update', $review);
            if ($review->reply !== null && ! $review->reply->isEditable()) {
                $results[] = ['id' => $review->id, 'ok' => false, 'message' => 'Already answered on Google.'];

                continue;
            }
            try {
                $body = \App\Support\Reviews\ReviewReplyWriter::write($review);
            } catch (RuntimeException) {
                $results[] = ['id' => $review->id, 'ok' => false, 'message' => 'AI could not draft a reply just now.'];

                continue;
            }
            $reply = $review->reply ?? new ReviewReply(['organization_id' => $review->organization_id,
                'review_id' => $review->getKey(), 'drafted_by' => ReviewReply::BY_DRAFTING_JOB]);
            $reply->fill(['body' => $body, 'status' => ReviewReply::STATUS_DRAFT, 'failure_reason' => null])->save();
            $this->audit->log('review.reply_drafted', $review, metadata: ['length' => strlen($body), 'batch' => true]);
            $results[] = ['id' => $review->id, 'ok' => true, 'body' => $body];
        }

        return response()->json(['data' => $results]);
    }

    /** DELETE /api/v1/reviews/{review}/reply — discard a draft. */
    public function discardReply(Review $review): JsonResponse
    {
        $this->authorize('update', $review);

        $reply = $review->reply;

        if ($reply === null) {
            return response()->json(['data' => $this->present($review)]);
        }

        if (! $reply->isEditable()) {
            return response()->json([
                'message' => 'This reply is published on Google and cannot be discarded from here. '
                    .'Removing it is done on the Business Profile itself.',
            ], Response::HTTP_CONFLICT);
        }

        $reply->delete();
        $this->audit->log('review.reply_discarded', $review);

        return response()->json(['data' => $this->present($review->fresh()->load('reply'))]);
    }

    /**
     * POST /api/v1/reviews/{review}/reply/publish
     *
     * The one that speaks in public. Its own permission, its own audit entry,
     * and the name of whoever pressed it stored on the row.
     */
    public function publishReply(Request $request, Review $review): JsonResponse
    {
        $this->authorize('publish', $review);
        if (($review->source ?? Review::SOURCE_GOOGLE) !== Review::SOURCE_GOOGLE) {
            return response()->json(['message' => 'Only Google reviews can be answered from EzBizi.'], Response::HTTP_CONFLICT);
        }

        $reply = $review->reply;

        if ($reply === null) {
            return response()->json([
                'message' => 'There is nothing drafted to publish for this review.',
            ], Response::HTTP_CONFLICT);
        }

        if ($reply->isPublished()) {
            return response()->json(['data' => $this->present($review->load('reply'))]);
        }


        try {
            $this->google->publish($reply);
        } catch (RuntimeException $e) {
            // The text is kept. A refusal usually means quota or an expired
            // connection, and losing what somebody wrote because Google was
            // briefly unavailable would be its own small betrayal.
            //
            // ── All three together, and this path needed saying twice ──
            //
            // The success path below was already one transaction. This one was
            // not, and it is the half that matters more: the record it writes
            // is a FAILURE, and the event it emits is how anybody finds out.
            // An outbox insert that threw here left the reply durably failed
            // with nobody told — and a vanishing "something is broken" is
            // indistinguishable from nothing being broken, which is the worst
            // way for an alert to fail.
            //
            // This `catch` sits OUTSIDE the DB::transaction below — that one
            // wraps only the success path — so there was no enclosing
            // transaction to inherit. Nothing was rolling this back.
            DB::transaction(function () use ($reply, $review, $e) {
                $reply->forceFill([
                    'status'         => ReviewReply::STATUS_FAILED,
                    'failure_reason' => $e->getMessage(),
                ])->save();

                // Audited for the same reason the success is. A reply that
                // Google refused is a thing that happened to a customer's
                // public presence, and the log that carries "we published"
                // should carry "we tried and could not" beside it.
                $this->audit->log('review.reply_failed', $review, metadata: [
                    'reason' => $e->getMessage(),
                ]);

                $this->events->emit('review.reply_failed', $review, [
                    'after' => ['reason' => $e->getMessage()],
                ]);
            });

            return response()->json(['message' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        DB::transaction(function () use ($reply, $review, $request) {
            $reply->forceFill([
                'status'               => ReviewReply::STATUS_PUBLISHED,
                'published_by_user_id' => $request->user()->getKey(),
                'published_at'         => now(),
                'failure_reason'       => null,
            ])->save();

            $this->audit->log('review.reply_published', $review, metadata: [
                'length' => strlen($reply->body),
            ]);

            $this->events->emit('review.reply_published', $review, [
                'after' => ['published_at' => $reply->published_at?->toIso8601String()],
            ]);
        });

        return response()->json(['data' => $this->present($review->fresh()->load('reply'))]);
    }

    /** POST /api/v1/reviews/sync */
    public function sync(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Review::class);

        $user = $request->user();

        $connections = SocialConnection::query()
            ->where('provider', SocialProvider::GoogleBusinessProfile->value)
            ->where('status', SocialConnection::STATUS_CONNECTED)
            ->get()
            /*
             * The view, not just the organization.
             *
             * The tenant scope narrows this to the organization and nothing
             * narrowed it to the company being looked at, so every company in an
             * organization saw - and fetched through - every other company's
             * connected Google account. Marketing's catalogue has always filtered
             * this way; these three tabs never did.
             */
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        if ($connections->isEmpty()) {
            return response()->json([
                'message' => 'No Business Profile is connected yet, so there is nothing to fetch. '
                    .'Connect one on Marketing to start seeing reviews here.',
            ], Response::HTTP_CONFLICT);
        }

        $totals = ['fetched' => 0, 'created' => 0, 'updated' => 0];
        $failures = [];

        foreach ($connections as $connection) {
            try {
                $result = $this->google->sync($connection);

                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
            } catch (RuntimeException $e) {
                $failures[] = [
                    'connection' => $connection->display_name,
                    'because'    => $e->getMessage(),
                ];
            }
        }

        $this->audit->log('review.synced', $request->user()->organization, metadata: $totals);

        // Failed only when EVERY profile failed. Zero reviews is the honest
        // answer for a profile nobody has reviewed, not a failure - the same
        // conflation LeadsController::sync was corrected for. And the reason
        // goes on top as `message`, which is the one key the client renders;
        // buried in data.failures it never reached the screen.
        $failedOut = $failures !== [] && count($failures) === $connections->count();

        $payload = ['data' => [...$totals, 'failures' => $failures]];

        if ($failedOut) {
            $payload['message'] = (string) ($failures[0]['because'] ?? 'Google would not return reviews.');
        }

        return response()->json($payload, $failedOut ? Response::HTTP_BAD_GATEWAY : Response::HTTP_OK);
    }

    private function unansweredCount($user): int
    {
        $query = Review::query()->whereDoesntHave(
            'reply',
            fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED),
        );

        \App\Support\CustomerExperience\Places::scope($query, $user);

        return (int) $query->count();
    }

    /** @return array<string, mixed> */
    private function present(Review $review): array
    {
        $reply = $review->reply;

        return [
            'id'            => $review->id,
            'source'        => $review->source ?? Review::SOURCE_GOOGLE,
            'reviewer_name' => $review->reviewer_name,
            'reviewer_photo_url' => $review->reviewer_photo_url,
            'rating'        => $review->rating,
            'comment'       => $review->comment,
            'reviewed_at'   => $review->reviewed_at?->toIso8601String(),
            'edited_at'     => $review->edited_at?->toIso8601String(),
            'was_edited'    => $review->wasEdited(),
            'company'       => $review->company?->only(['id', 'name']),
            'franchise'     => $review->franchise?->only(['id', 'name']),
            'location'      => preg_match('~(locations/[^/]+)$~', (string) $review->location_name, $m) ? $m[1] : null,
            'status'        => $review->status,
            'note_count'    => (int) ($review->notes_count ?? 0),
            'customer'      => $review->customer?->only(['id', 'name', 'phone', 'email']),
            'match'         => $review->customer_match,
            'job'           => $review->job === null ? null : [
                'id'         => $review->job->id,
                'number'     => $review->job->number,
                'starts_at'  => $review->job->scheduled_for?->toIso8601String(),
                'tech'       => $review->job->performedBy?->name,
                'workiz_url' => null,
            ],
            'reported_at'   => $review->reported_at?->toIso8601String(),
            'report_reason' => $review->report_reason,
            'reply'         => $reply === null ? null : [
                'id'             => $reply->id,
                'body'           => $reply->body,
                'status'         => $reply->status,
                'drafted_by'     => $reply->drafted_by,
                'published_at'   => $reply->published_at?->toIso8601String(),
                'failure_reason' => $reply->failure_reason,
                'editable'       => $reply->isEditable(),
            ],
        ];
    }
}
