<?php

namespace App\Http\Controllers\Api;

use App\Models\Review;
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
use Illuminate\Support\Facades\DB;
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
            ->with(['reply', 'company:id,name', 'franchise:id,name'])
            ->orderByDesc('reviewed_at')
            ->orderByDesc('created_at');

        if (! $user->canActOnOrganization($user->organization)) {
            $companyIds   = $user->reachableCompanies()->pluck('id')->all();
            $franchiseIds = $user->reachableFranchises()->pluck('id')->all();

            $query->where(function ($q) use ($companyIds, $franchiseIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    // A row filed at organization level - both columns null,
                    // which is every row from an organization-scoped
                    // connection - belongs to everybody who can see this tab.
                    // Matching the two columns alone hid all of them from
                    // every user below organization level, while the policy
                    // still allowed each one by id. Business Profile carried
                    // this clause from the start; Leads and Reviews did not.
                    ->orWhere(fn ($w) => $w->whereNull('company_id')->whereNull('franchise_id'));
            });
        }

        // The filter the work actually uses. Somebody opening this tab is
        // usually looking for the ones nobody has answered, not browsing.
        if ($request->query('answered') === 'no') {
            $query->whereDoesntHave('reply', fn ($q) => $q->where('status', ReviewReply::STATUS_PUBLISHED));
        }

        if (is_numeric($rating = $request->query('rating'))) {
            $query->where('rating', (int) $rating);
        }

        $reviews = $query->paginate(min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => array_map(fn (Review $r) => $this->present($r), $reviews->items()),
            'meta' => [
                ...$this->pageMeta($reviews),
                'unanswered'   => $this->unansweredCount($user),
                'connections'  => $this->access->presentableInView($user, SocialProvider::GoogleBusinessProfile),
            ],
        ]);
    }

    /** GET /api/v1/reviews/{review} */
    public function show(Review $review): JsonResponse
    {
        $this->authorize('view', $review);

        return response()->json([
            'data' => $this->present($review->load(['reply', 'company:id,name', 'franchise:id,name'])),
        ]);
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

        if (! GrokCli::configured()) {
            return response()->json(['message' => 'AI drafting is not switched on.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $business = $review->company?->name ?? $review->franchise?->name ?? 'the business';

        try {
            $answer = GrokCli::ask(
                "Write a reply from {$business} (a home-services company: chimneys, fireplaces, garage "
                ."doors) to this Google review. Warm, specific to what the reviewer said, 2 to 4 "
                ."sentences, no emojis, no hashtags. Thank them by first name if one is given. For a "
                ."low rating: apologise once, do not argue or admit fault in detail, and invite them to "
                ."call the office to put it right. Never invent facts, names, prices or dates.

"
                ."Reviewer: ".($review->reviewer_name ?? 'unknown')."
Stars: ".($review->rating ?? '?')
                ."
Review: ".($review->comment ?: '(no text, stars only)'),
                [
                    'type' => 'object',
                    'properties' => ['reply' => ['type' => 'string']],
                    'required' => ['reply'],
                    'additionalProperties' => false,
                ],
            );
        } catch (RuntimeException) {
            return response()->json(['message' => 'AI could not draft a reply just now. Try again in a minute.'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json(['data' => ['body' => mb_substr(trim((string) $answer['reply']), 0, 4096)]]);
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

        if (! $user->canActOnOrganization($user->organization)) {
            $companyIds   = $user->reachableCompanies()->pluck('id')->all();
            $franchiseIds = $user->reachableFranchises()->pluck('id')->all();

            $query->where(function ($q) use ($companyIds, $franchiseIds) {
                $q->whereIn('company_id', $companyIds)
                    ->orWhereIn('franchise_id', $franchiseIds)
                    // A row filed at organization level - both columns null,
                    // which is every row from an organization-scoped
                    // connection - belongs to everybody who can see this tab.
                    // Matching the two columns alone hid all of them from
                    // every user below organization level, while the policy
                    // still allowed each one by id. Business Profile carried
                    // this clause from the start; Leads and Reviews did not.
                    ->orWhere(fn ($w) => $w->whereNull('company_id')->whereNull('franchise_id'));
            });
        }

        return (int) $query->count();
    }

    /** @return array<string, mixed> */
    private function present(Review $review): array
    {
        $reply = $review->reply;

        return [
            'id'            => $review->id,
            'reviewer_name' => $review->reviewer_name,
            'reviewer_photo_url' => $review->reviewer_photo_url,
            'rating'        => $review->rating,
            'comment'       => $review->comment,
            'reviewed_at'   => $review->reviewed_at?->toIso8601String(),
            'edited_at'     => $review->edited_at?->toIso8601String(),
            'was_edited'    => $review->wasEdited(),
            'company'       => $review->company?->only(['id', 'name']),
            'franchise'     => $review->franchise?->only(['id', 'name']),
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
