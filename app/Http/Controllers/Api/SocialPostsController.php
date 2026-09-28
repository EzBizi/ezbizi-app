<?php

namespace App\Http\Controllers\Api;

use App\Jobs\PublishSocialTarget;
use App\Models\SocialConnection;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Files\UploadLimits;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialMediaKind;
use App\Support\Time\ViewTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as Status;

/**
 * Compose once, send to every account that was ticked.
 *
 * ── Everything that can refuse, refuses before a row is written ──
 *
 * A post to three accounts is three sends that cannot be rolled back, so the
 * checks that CAN be made in advance are all made in advance: is this account
 * one this view may post as, is there a publisher for its network at all, and
 * will that network take a post of this kind. Each answers in the sentence the
 * screen shows.
 *
 * The alternative — write the rows, let the jobs discover it — turns a
 * correctable mistake ("Pinterest needs a picture") into three queue entries and
 * a red row, after two other networks already published a post the person would
 * have edited had they been told.
 *
 * What is deliberately NOT pre-checked is anything only the network knows: an
 * expired token, a caption its own rules refuse, a video codec. Those are the
 * publisher's to report, per target, after the fact.
 *
 * ── The gate ─────────────────────────────────────────────────────
 *
 * `marketing.edit`, and reach through `SocialConnectionAccess`, which is the
 * same pair Connect and Disconnect use — publishing as an account is not a
 * lesser act than connecting it. There is no model to hang a policy off (the
 * post does not exist yet), so the check is here, in a sentence.
 */
class SocialPostsController extends Controller
{
    /**
     * Longest body this will accept.
     *
     * ponytail: one number for every network, and the real limits differ wildly
     * — Bluesky is 300 characters, X is 280, LinkedIn is 3,000. None of those
     * four have a publisher yet, so enforcing per-network here would be
     * enforcing rules against sends that cannot happen. When one lands, the
     * limit moves onto SocialProvider beside `accepts()` and this constant goes.
     */
    private const MAX_BODY = 5000;

    public function __construct(
        private readonly SocialConnectionAccess $access,
        private readonly FileStore $files,
        private readonly UploadLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * GET /api/v1/social/posts
     *
     * What has been sent from this view lately, and how each one landed.
     *
     * Filtered by the same `inView` rule the account list uses, applied to the
     * TARGETS: a post is visible here when at least one account it went to is
     * visible here, and the targets that are not are not listed. Standing in
     * A-1 Chimney you see what A-1 Chimney posted, never what Premier Garage
     * did — the sibling rule holds on the output of publishing as much as on
     * the accounts themselves.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.view'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('open Marketing')->message(),
        );

        $visible = SocialConnection::query()->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->keyBy(fn (SocialConnection $c) => $c->getKey());

        $posts = SocialPost::query()
            ->with(['targets', 'author:id,name'])
            ->whereHas('targets', fn ($q) => $q->whereIn('social_connection_id', $visible->keys()))
            ->latest()
            // ponytail: the twenty most recent, no paging. This is the "did it
            // send" list under the composer, not an archive; when Marketing
            // grows a real queue screen that screen brings its own paging.
            ->limit(20)
            ->get();

        return response()->json([
            'data' => $posts->map(fn (SocialPost $post) => [
                'id' => $post->getKey(),
                'body' => $post->body,
                'title' => $post->title,
                'has_media' => $post->media_path !== null,
                'scheduled_for' => $post->scheduled_for,
                'scheduled_timezone' => $post->scheduled_timezone,
                'author' => $post->author?->name,
                'created_at' => $post->created_at?->toIso8601String(),
                'targets' => $post->targets
                    ->filter(fn (SocialPostTarget $t) => $visible->has($t->social_connection_id))
                    ->map(fn (SocialPostTarget $t) => $this->presentTarget($t, $visible->get($t->social_connection_id)))
                    ->values()->all(),
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/social/posts
     *
     * Multipart, because it carries the file. `connections[]`, `body`, `title`,
     * `media`.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('publish to a social account')->message(),
        );

        // Before validation: a file over PHP's own limit arrives present and
        // empty, and `max:` has nothing to weigh. See UploadLimits.
        $this->limits->assertUploadArrived($request, 'media');

        $data = $request->validate([
            'connections' => ['required', 'array', 'min:1'],
            'connections.*' => ['string'],
            'body' => ['nullable', 'string', 'max:'.self::MAX_BODY],
            'title' => ['nullable', 'string', 'max:255'],
            // Wall clock in the view's own timezone, as the composer typed it.
            // No timezone on the wire: the server decides which clock the view
            // is on, because a client that sent one could send a different one
            // than the screen was showing.
            'scheduled_for' => ['nullable', 'string', 'date_format:Y-m-d H:i'],
            'media' => [
                'nullable', 'file',
                'mimes:'.implode(',', SocialMediaKind::extensions()),
                'max:'.$this->limits->maxKilobytes(),
            ],
        ], [
            'connections.required' => 'Tick at least one account to post to.',
            'media.mimes' => 'Social networks take pictures and video: '
                .implode(', ', SocialMediaKind::extensions()).'.',
            'media.max' => $this->limits->tooLargeMessage(),
            'scheduled_for.date_format' => 'That is not a time this can read. '
                .'Pick the day and time again.',
        ]);

        $file = $request->file('media');
        $schedule = $this->schedule($request, $data['scheduled_for'] ?? null);

        if ($file === null && trim((string) ($data['body'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'body' => 'Write something, or attach a picture or a video.',
            ]);
        }

        /*
         * The kind the post IS, decided the same way the publisher will decide
         * it — off the extension, through PostPayload — so the answer the
         * networks are checked against below is the answer they will really get.
         *
         * `extension()` and not `getClientOriginalName()`: the guessed one is
         * what `store()` writes into the path, and the client's name can carry a
         * different extension or none at all. Taking the client's word here
         * would let a file called `holiday` with PNG bytes be checked as text —
         * refused by Pinterest at this line — and then stored as `.png`, which
         * Pinterest would have taken.
         */
        $kind = (new PostPayload(
            body: (string) ($data['body'] ?? ''),
            filePath: $file === null ? null : 'upload.'.$file->extension(),
        ))->kind();

        $connections = $this->resolve($request, $data['connections']);

        $this->assertEveryNetworkTakesThis($connections, $kind);

        $post = DB::transaction(function () use ($connections, $data, $file, $user, $schedule) {
            $post = new SocialPost([
                'body' => $data['body'] ?? null,
                'title' => $data['title'] ?? null,
                'created_by' => $user->getKey(),
                'scheduled_for' => $schedule['for'],
                'scheduled_timezone' => $schedule['timezone'],
            ]);

            if ($file !== null) {
                $post->media_disk = $this->files->defaultDisk();
                $post->media_path = $this->files->put(
                    $file,
                    $this->files->directoryFor('social', $user->organization_id),
                );
            }

            $post->save();

            foreach ($connections as $connection) {
                $post->targets()->create([
                    'social_connection_id' => $connection->getKey(),
                    'status' => $schedule['for'] === null
                        ? SocialPostTarget::STATUS_QUEUED
                        : SocialPostTarget::STATUS_SCHEDULED,
                ]);
            }

            return $post;
        });

        /*
         * Dispatched after the transaction has returned, never from inside it.
         * The worker is a separate process reading the same database, so a job
         * enqueued mid-transaction can be picked up before the target row it
         * names is visible — and it would return having found nothing, leaving a
         * post that says `queued` forever with nothing left to send it.
         */
        /*
         * A scheduled post dispatches nothing now. `ezbizi:publish-due-social-
         * posts` picks it up the minute it comes due — the same outbox shape
         * the automations use, and for the same reason: a delayed job carrying
         * the work would have to be cancelled if somebody changed their mind,
         * and a row can simply stop being due.
         */
        if ($schedule['for'] === null) {
            foreach ($post->targets as $target) {
                PublishSocialTarget::dispatch($target->getKey());
            }
        }

        /*
         * Read back AFTER dispatching, not before. On a queue that runs the job
         * in-process — `sync`, which is what the test suite uses — the rows have
         * already changed by now, and answering from the copies loaded a moment
         * ago would report `queued` for a post that has been sent. Reloading
         * makes the response true under either driver rather than true under the
         * one this happened to be written against.
         */
        $post->load('targets');

        $this->audit->log('social.post_queued', $post, [
            'accounts' => $connections->map(fn (SocialConnection $c) => $c->display_name)->all(),
            'kind' => $kind->value,
            'scheduled_for' => $schedule['for'],
            'timezone' => $schedule['timezone'],
        ], actor: $user);

        return response()->json([
            'data' => [
                'id' => $post->getKey(),
                'targets' => $post->targets->map(
                    fn (SocialPostTarget $t) => $this->presentTarget($t, $connections->firstWhere('id', $t->social_connection_id))
                )->all(),
            ],
        ], Status::HTTP_CREATED);
    }

    /**
     * The time this post is promised for, and the clock that promise is in.
     *
     * ── Why the server names the timezone ────────────────────────
     *
     * The composer sends a wall time and nothing else. The clock comes from the
     * VIEW (§3), resolved here — a client that sent its own could send one the
     * screen was not showing, and "9am" would quietly become a different 9am
     * than the person read.
     *
     * ── Why a past time is refused rather than sent now ──────────
     *
     * Somebody picking a time that has already gone has made a mistake — most
     * often the day — and silently publishing immediately is the least
     * recoverable reading of it. A post cannot be unsent.
     *
     * @return array{for: string|null, timezone: string|null}
     */
    private function schedule(Request $request, ?string $wall): array
    {
        if ($wall === null || trim($wall) === '') {
            return ['for' => null, 'timezone' => null];
        }

        $timezone = ViewTimezone::forUser($request->user());
        $moment = Carbon::parse($wall, $timezone);

        // A minute of slack: the composer's clock and the server's are not the
        // same clock, and refusing "now" as past would be right and useless.
        if ($moment->lt(now()->subMinute())) {
            throw ValidationException::withMessages([
                'scheduled_for' => 'That time has already passed. Pick a time in the future, '
                    .'or use Publish now.',
            ]);
        }

        return ['for' => $moment->format('Y-m-d H:i:s'), 'timezone' => $timezone];
    }

    /**
     * The accounts named, or a refusal.
     *
     * An id this view cannot see and an id that does not exist answer
     * identically, for the reason CustomersController's scoped `exists` rule
     * gives: two different answers would tell a caller which uuids are real
     * somewhere else on the platform.
     *
     * @param  string[]  $ids
     * @return Collection<int, SocialConnection>
     */
    private function resolve(Request $request, array $ids): Collection
    {
        $user = $request->user();

        $found = SocialConnection::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
            ->values();

        if ($found->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'connections' => 'One of those accounts is not connected in this view any more. '
                    .'Reload Marketing and tick them again.',
            ]);
        }

        return $found;
    }

    /**
     * @param  Collection<int, SocialConnection>  $connections
     */
    private function assertEveryNetworkTakesThis(Collection $connections, SocialMediaKind $kind): void
    {
        foreach ($connections as $connection) {
            $provider = $connection->providerEnum();

            /*
             * Three different refusals, and the ORDER is the whole point —
             * each one is the truest thing that can be said at that step, and
             * a later one said earlier is misleading rather than merely worse.
             *
             *   1. No publisher for this network at all. "Facebook is not built
             *      yet" — nothing about the post could change that.
             *   2. The NETWORK will not take a post of this kind. "Pinterest
             *      needs a picture or a video" — the remedy is to attach one.
             *   3. The network WOULD take it and EzBizi has not built that
             *      half. "Cannot post video to Pinterest yet; the connection is
             *      fine" — the remedy is on us, and saying so stops somebody
             *      hunting through their Pinterest settings.
             *
             * Getting 2 and 3 the wrong way round tells a person with a
             * text-only post that our video poster is missing, which is true
             * and not their problem. Number 3 was absent entirely until
             * 2026-08-28, which cost a real post: Pinterest accepts video, so
             * step 2 passed, a row was written, the job ran, and only the
             * publisher knew.
             */
            $because = Publishers::refusalFor($provider)
                ?? $provider?->refuses($kind)
                ?? Publishers::for($provider)?->cannotSend($kind);

            if ($because !== null) {
                /*
                 * The whole post is refused rather than the one target dropped.
                 * Silently posting to two of three accounts is the outcome
                 * nobody asked for: the person meant all three, and finding out
                 * afterwards that Pinterest was skipped is worse than being told
                 * now, while the words are still in the box and a picture can
                 * still be attached.
                 */
                throw ValidationException::withMessages(['connections' => $because]);
            }
        }
    }

    private function presentTarget(SocialPostTarget $target, ?SocialConnection $connection): array
    {
        return [
            'id' => $target->getKey(),
            'connection_id' => $target->social_connection_id,
            'account' => $connection?->display_name,
            'provider' => $connection?->provider,
            'provider_label' => $connection?->providerLabel(),
            'status' => $target->status,
            'failure' => $target->failure,
            'sent_at' => $target->sent_at?->toIso8601String(),
            'url' => $target->url,
        ];
    }
}
