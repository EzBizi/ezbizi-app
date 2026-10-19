<?php

namespace App\Http\Controllers\Api;

use App\Jobs\PublishSocialTarget;
use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\SocialLibraryItem;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Files\UploadLimits;
use App\Support\Newsletter\NewsletterAccess;
use App\Support\Social\Publishing\DeletesPosts;
use App\Support\Social\Publishing\GoogleBusinessProfilePublisher;
use App\Support\Social\Publishing\PostPayload;
use App\Support\Social\Publishing\Publishers;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Social\SocialMediaKind;
use App\Support\Social\SocialProvider;
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

    /** The Posts tiles, in the order the screen draws them. */
    private const TILES = ['scheduled', 'sent', 'failed'];

    /** Business Profile's call-to-action buttons (v4 localPosts actionType). */
    private const CTA_TYPES = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

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

        $query = SocialPost::query()
            ->whereHas('targets', fn ($q) => $q->whereIn('social_connection_id', $visible->keys()));

        // The Posts tiles count every post this view can see, not the twenty
        // drawn, so a tile can never say fewer than the list it filters
        // (Mia, 2026-10-02, Posts layout A). Counted before `status` narrows.
        $tiles = [];
        foreach (self::TILES as $status) {
            $tiles[$status] = $this->inStatus(clone $query, $status, $visible->keys()->all())->count();
        }

        $status = $request->query('status');
        if (in_array($status, self::TILES, true)) {
            $this->inStatus($query, $status, $visible->keys()->all());
        }

        $posts = $query
            ->with(['targets', 'author:id,name'])
            ->latest()
            // ponytail: the twenty most recent, no paging. This is the "did it
            // send" list under the composer, not an archive; the tiles above
            // carry the whole count and filter on the server.
            ->limit(20)
            ->get();

        return response()->json([
            'meta' => ['tiles' => $tiles],
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
            'connections' => ['required_without:places', 'array'],
            'connections.*' => ['string'],
            // Business Profile listings, by BusinessLocation id: a place is a
            // target of its own, and one Google account can hold many.
            'places' => ['nullable', 'array'],
            'places.*' => ['string'],
            'cta_type' => ['nullable', 'string', 'in:'.implode(',', self::CTA_TYPES)],
            'cta_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            // A picture or video from the standard library, in place of an upload.
            'library_item' => ['nullable', 'string'],
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
            'connections.required_without' => 'Tick at least one account or place to post to.',
            'cta_url.url' => 'A button needs a full https:// address.',
            'media.mimes' => 'Social networks take pictures and video: '
                .implode(', ', SocialMediaKind::extensions()).'.',
            'media.max' => $this->limits->tooLargeMessage(),
            'scheduled_for.date_format' => 'That is not a time this can read. '
                .'Pick the day and time again.',
        ]);

        $file = $request->file('media');
        $libraryItem = null;

        if ($file === null && ($data['library_item'] ?? null) !== null) {
            $libraryItem = NewsletterAccess::visible(SocialLibraryItem::query(), $user)->find($data['library_item'])
                ?? throw ValidationException::withMessages(['library_item' => 'That picture is no longer in the library for this view.']);
        }
        $schedule = $this->schedule($request, $data['scheduled_for'] ?? null);

        if ($file === null && $libraryItem === null && trim((string) ($data['body'] ?? '')) === '') {
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
            filePath: $file !== null ? 'upload.'.$file->extension() : $libraryItem?->path,
        ))->kind();

        if (($data['cta_type'] ?? null) !== null && $data['cta_type'] !== 'CALL' && ($data['cta_url'] ?? null) === null) {
            throw ValidationException::withMessages(['cta_url' => 'A button needs a full https:// address.']);
        }

        $connections = $this->resolve($request, $data['connections'] ?? []);
        $places = $this->resolvePlaces($request, $data['places'] ?? []);

        $refused = $this->whatEachNetworkRefuses($connections, $kind)
            + $this->whatEachPlaceRefuses($places, $kind);

        /*
         * Jose, 2026-10-15: "if I choose 7 accounts but 2 aren't able to post
         * the other 5 should still go through". Only when NONE can take it is
         * the post refused, every reason named, so nothing is written.
         */
        if (count($refused) === $connections->count() + $places->count()) {
            throw ValidationException::withMessages(['connections' => implode(' ', array_unique($refused))]);
        }

        $cta = array_filter([
            'cta_type' => $data['cta_type'] ?? null,
            'cta_url' => $data['cta_url'] ?? null,
        ]);

        $options = $this->tiktokChoices($request, $connections);

        $post = DB::transaction(function () use ($connections, $places, $cta, $data, $file, $libraryItem, $user, $schedule, $options, $refused) {
            $post = new SocialPost([
                'body' => $data['body'] ?? null,
                'title' => $data['title'] ?? null,
                'created_by' => $user->getKey(),
                'scheduled_for' => $schedule['for'],
                'scheduled_timezone' => $schedule['timezone'],
            ]);

            if ($file === null && $libraryItem !== null) {
                // The post keeps its own copy, so removing the library item
                // later cannot take a scheduled post's picture away.
                $post->media_disk = $this->files->defaultDisk();
                $post->media_path = $this->files->putContents(
                    (string) $this->files->contents($libraryItem->path, $libraryItem->disk),
                    $this->files->directoryFor('social', $user->organization_id),
                    pathinfo($libraryItem->path, PATHINFO_EXTENSION),
                    $post->media_disk,
                );
            }

            if ($file !== null) {
                $post->media_disk = $this->files->defaultDisk();
                $post->media_path = $this->files->put(
                    $file,
                    $this->files->directoryFor('social', $user->organization_id),
                );
            }

            $post->save();

            foreach ($connections as $connection) {
                $because = $refused[$connection->getKey()] ?? null;

                // An account that cannot take this post is written as failed,
                // with its reason, so it shows under Needs attention instead
                // of quietly not being there.
                $target = $post->targets()->create([
                    'social_connection_id' => $connection->getKey(),
                    'options' => $options[$connection->getKey()] ?? null,
                    'status' => match (true) {
                        $because !== null => SocialPostTarget::STATUS_FAILED,
                        $schedule['for'] === null => SocialPostTarget::STATUS_QUEUED,
                        default => SocialPostTarget::STATUS_SCHEDULED,
                    },
                ]);

                if ($because !== null) {
                    $target->forceFill(['failure' => $because])->save();
                }
            }

            foreach ($places as $place) {
                $because = $refused[$place->getKey()] ?? null;

                $target = $post->targets()->create([
                    'social_connection_id' => $place->connection_id,
                    'options' => [
                        'gbp_location' => GoogleBusinessProfilePublisher::v4Name($place, $place->connection),
                        'gbp_title' => $place->title,
                    ] + $cta,
                    'status' => match (true) {
                        $because !== null => SocialPostTarget::STATUS_FAILED,
                        $schedule['for'] === null => SocialPostTarget::STATUS_QUEUED,
                        default => SocialPostTarget::STATUS_SCHEDULED,
                    },
                ]);

                if ($because !== null) {
                    $target->forceFill(['failure' => $because])->save();
                }
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
                if ($target->status === SocialPostTarget::STATUS_QUEUED) {
                    PublishSocialTarget::dispatch($target->getKey());
                }
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
     * POST /api/v1/social/posts/{post}/targets/{target}/retry
     *
     * Send one failed account again. The job never retries on its own (see
     * PublishSocialTarget: an unknown outcome might already be live), so this is
     * the person deciding it did not land. Only a FAILED target: the flip from
     * failed to queued is one conditional UPDATE, so a double click queues one
     * send, and a sent post can never be pushed out twice from here.
     */
    public function retry(Request $request, SocialPost $post, SocialPostTarget $target): JsonResponse
    {
        $user = $request->user();

        abort_unless(
            $user->hasPermission('marketing.edit'),
            Status::HTTP_FORBIDDEN,
            Refusal::permission('publish to a social account')->message(),
        );

        $connection = $target->connection;

        abort_if(
            $target->social_post_id !== $post->getKey()
                || $connection === null
                || ! $this->access->inView($user, $connection),
            Status::HTTP_NOT_FOUND,
        );

        $flipped = SocialPostTarget::query()
            ->whereKey($target->getKey())
            ->where('status', SocialPostTarget::STATUS_FAILED)
            ->update(['status' => SocialPostTarget::STATUS_QUEUED, 'failure' => null]);

        if ($flipped === 0) {
            throw ValidationException::withMessages([
                'target' => 'Only a post that failed can be sent again. This one is '.$target->status.'.',
            ]);
        }

        PublishSocialTarget::dispatch($target->getKey());

        $this->audit->log('social.post_retried', $post, [
            'account' => $connection->display_name,
        ], actor: $user);

        return response()->json(['data' => $this->presentTarget($target->fresh(), $connection)]);
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
    /*
     * ── Managing a post after Publish (A1 poster takeover, 2026-10-15) ──
     *
     * Edit, reschedule and cancel act on a post whose every account is still
     * scheduled: once any account has it, the words are out and changing them
     * here would describe a post nobody can see. Dismiss clears a failed
     * account from the list; delete takes a sent one down from the network
     * where its API allows (DeletesPosts). All are marketing.edit, audited.
     */

    /** PATCH social/posts/{post}: words and/or time, while still scheduled. */
    public function update(Request $request, SocialPost $post): JsonResponse
    {
        $user = $this->mayManage($request);
        $this->assertStillScheduled($user, $post);

        $data = $request->validate([
            'body' => ['sometimes', 'nullable', 'string', 'max:63206'],
            'scheduled_for' => ['sometimes', 'required', 'string'],
        ]);

        if (array_key_exists('body', $data)) {
            $post->body = $data['body'];
        }

        if (array_key_exists('scheduled_for', $data)) {
            $schedule = $this->schedule($request, $data['scheduled_for']);
            $post->forceFill([
                'scheduled_for' => $schedule['for'],
                'scheduled_timezone' => $schedule['timezone'],
            ]);
        }

        $this->audit->logChange('social.post_edited', $post, $user);
        $post->save();

        $post->load('targets.connection');

        return response()->json(['data' => [
            'id' => $post->getKey(),
            'body' => $post->body,
            'scheduled_for' => $post->scheduled_for,
            'targets' => $post->targets->map(fn (SocialPostTarget $t) => $this->presentTarget($t, $t->connection))->all(),
        ]]);
    }

    /** DELETE social/posts/{post}: cancel a post nobody has received yet. */
    public function cancel(Request $request, SocialPost $post): JsonResponse
    {
        $user = $this->mayManage($request);
        $this->assertStillScheduled($user, $post);

        $this->audit->log('social.post_cancelled', $post, ['body' => mb_substr((string) $post->body, 0, 200)], actor: $user);
        $post->delete();

        return response()->json(['data' => ['id' => $post->getKey()]]);
    }

    /** POST social/posts/{post}/targets/{target}/dismiss: a failed account, cleared. */
    public function dismiss(Request $request, SocialPost $post, SocialPostTarget $target): JsonResponse
    {
        $user = $this->mayManage($request);
        $connection = $this->targetInView($user, $post, $target);

        if ($target->status !== SocialPostTarget::STATUS_FAILED) {
            throw ValidationException::withMessages(['target' => 'Only an account that failed can be dismissed.']);
        }

        $this->audit->log('social.target_dismissed', $post, ['account' => $connection->display_name], actor: $user);
        $target->delete();

        return response()->json(['data' => ['id' => $target->getKey()]]);
    }

    /** DELETE social/posts/{post}/targets/{target}/remote: take it down from the network. */
    public function deleteRemote(Request $request, SocialPost $post, SocialPostTarget $target): JsonResponse
    {
        $user = $this->mayManage($request);
        $connection = $this->targetInView($user, $post, $target);

        if ($target->status !== SocialPostTarget::STATUS_SENT || ! is_string($target->remote_id) || $target->remote_id === '') {
            throw ValidationException::withMessages(['target' => 'Only a post that went out can be deleted from '.$connection->providerLabel().'.']);
        }

        $publisher = Publishers::for($connection->providerEnum());

        if (! $publisher instanceof DeletesPosts) {
            throw ValidationException::withMessages([
                'target' => $connection->providerLabel().' does not let EzBizi delete posts. Delete it in '.$connection->providerLabel().' itself.',
            ]);
        }

        try {
            $publisher->deleteRemote($connection, $target->remote_id);
        } catch (SocialPublishFailed $e) {
            throw ValidationException::withMessages(['target' => $e->getMessage()]);
        }

        $target->forceFill(['status' => SocialPostTarget::STATUS_REMOVED, 'url' => null])->save();
        $this->audit->log('social.post_deleted_remotely', $post, [
            'account' => $connection->display_name, 'remote_id' => $target->remote_id,
        ], actor: $user);

        return response()->json(['data' => $this->presentTarget($target->fresh(), $connection)]);
    }

    private function mayManage(Request $request): User
    {
        $user = $request->user();

        abort_unless($user->hasPermission('marketing.edit'), Status::HTTP_FORBIDDEN,
            Refusal::permission('change a social post')->message());

        return $user;
    }

    /** Every account of the post in view, and every one still waiting for its time. */
    private function assertStillScheduled(User $user, SocialPost $post): void
    {
        $targets = $post->targets()->with('connection')->get();

        abort_if($targets->isEmpty() || $targets->contains(
            fn (SocialPostTarget $t) => $t->connection === null || ! $this->access->inView($user, $t->connection),
        ), Status::HTTP_NOT_FOUND);

        if ($targets->contains(fn (SocialPostTarget $t) => $t->status !== SocialPostTarget::STATUS_SCHEDULED)) {
            throw ValidationException::withMessages([
                'post' => 'This post has already started going out, so it can no longer be edited or cancelled.',
            ]);
        }
    }

    private function targetInView(User $user, SocialPost $post, SocialPostTarget $target): SocialConnection
    {
        $connection = $target->connection;

        abort_if(
            $target->social_post_id !== $post->getKey()
                || $connection === null
                || ! $this->access->inView($user, $connection),
            Status::HTTP_NOT_FOUND,
        );

        return $connection;
    }

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
     * What the person chose for each TikTok account, as TikTok's Direct Post
     * rules require: who sees it (never defaulted), comments/duets/stitches
     * (off unless ticked), and whether it promotes their own brand or someone
     * else's. Drafts need none of it - the creator decides inside TikTok.
     *
     * Sent as `tiktok[<connection id>][privacy_level]` and so on.
     *
     * @param  Collection<int, SocialConnection>  $connections
     * @return array<string, array<string, mixed>>
     */
    private function tiktokChoices(Request $request, Collection $connections): array
    {
        if (! Publishers::tiktokIsAudited()) {
            return [];
        }

        $choices = [];

        foreach ($connections as $connection) {
            if ($connection->providerEnum() !== SocialProvider::TikTok) {
                continue;
            }

            $key = 'tiktok.'.$connection->getKey();
            $data = $request->validate([
                $key.'.privacy_level' => ['required', 'string', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY'],
                $key.'.allow_comment' => ['sometimes', 'boolean'],
                $key.'.allow_duet' => ['sometimes', 'boolean'],
                $key.'.allow_stitch' => ['sometimes', 'boolean'],
                $key.'.disclose' => ['sometimes', 'boolean'],
                $key.'.your_brand' => ['sometimes', 'boolean'],
                $key.'.branded_content' => ['sometimes', 'boolean'],
            ], [
                $key.'.privacy_level.required' => 'Choose who can see the post on '.$connection->display_name.'.',
            ]);

            $chosen = (array) data_get($data, $key, []);
            $disclose = filter_var($chosen['disclose'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $yourBrand = $disclose && filter_var($chosen['your_brand'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $branded = $disclose && filter_var($chosen['branded_content'] ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($disclose && ! $yourBrand && ! $branded) {
                throw ValidationException::withMessages([
                    $key => 'You said this promotes a brand. Tick "Your brand", "Branded content" or both.',
                ]);
            }

            if ($branded && $chosen['privacy_level'] === 'SELF_ONLY') {
                throw ValidationException::withMessages([
                    $key => 'Branded content cannot be private on TikTok. Choose who else can see it.',
                ]);
            }

            $choices[$connection->getKey()] = [
                'privacy_level' => $chosen['privacy_level'],
                'allow_comment' => filter_var($chosen['allow_comment'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'allow_duet' => filter_var($chosen['allow_duet'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'allow_stitch' => filter_var($chosen['allow_stitch'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'your_brand' => $yourBrand,
                'branded_content' => $branded,
            ];
        }

        return $choices;
    }

    /**
     * @param  Collection<int, SocialConnection>  $connections
     */
    /** @return array<string, string> connection id => why that network cannot take this post */
    private function whatEachNetworkRefuses(Collection $connections, SocialMediaKind $kind): array
    {
        $refused = [];

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
                $refused[$connection->getKey()] = $because;
            }
        }

        return $refused;
    }

    /**
     * Business Profile listings this view may post to, for the composer.
     * `postable` is false for a listing Google is not showing (suspended,
     * unverified, unpublished), which the composer leaves unticked.
     */
    public function places(Request $request): JsonResponse
    {
        $rows = $this->placesInView($request->user());

        $rows = $rows->sortBy(fn (BusinessLocation $l) => [$l->branchName() ?? '', $l->label()])->values();

        return response()->json(['data' => $rows->map(fn (BusinessLocation $l) => [
            'id' => $l->getKey(),
            'title' => $l->title,
            // "A1 Chimney · Stamford, CT": the name to draw, and the branch
            // to group under (Jose, 2026-10-19).
            'label' => $l->label(),
            'branch' => $l->branchName(),
            'address' => $l->addressLine(),
            'connection_id' => $l->connection_id,
            'state' => $l->verification_state,
            'postable' => $l->isLive(),
        ])->values()->all()]);
    }

    /** @return Collection<int, BusinessLocation> */
    private function placesInView(User $user): Collection
    {
        return BusinessLocation::query()->with(['connection', 'franchise', 'company'])->orderBy('title')->get()
            ->filter(fn (BusinessLocation $l) => $l->connection !== null
                && $l->connection->providerEnum() === SocialProvider::GoogleBusinessProfile
                && $this->access->inView($user, $l->connection))
            ->values();
    }

    /** @return Collection<int, BusinessLocation> */
    private function resolvePlaces(Request $request, array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $found = $this->placesInView($request->user())->whereIn('id', $ids)->values();

        if ($found->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'places' => 'One of those places is not connected in this view any more. Reload Marketing and tick them again.',
            ]);
        }

        return $found;
    }

    /** @return array<string, string> location id => why it cannot take this post */
    private function whatEachPlaceRefuses(Collection $places, SocialMediaKind $kind): array
    {
        $refused = [];

        foreach ($places as $place) {
            $because = SocialProvider::GoogleBusinessProfile->refuses($kind)
                ?? ($place->isLive() ? null
                    : $place->title.' is not live on Google (it is '.strtolower((string) $place->verification_state)
                        .' or unpublished), so Google would not show the post. Nothing was sent there.');

            if ($because !== null) {
                $refused[$place->getKey()] = $because;
            }
        }

        return $refused;
    }

    /**
     * Narrow to posts in one tile, judged on the targets THIS view can see -
     * the same targets the list shows, so the pill and the tile agree.
     *
     * The same reading as the screen's colour for a post: any failed account
     * makes it failed; every account waiting for its time makes it scheduled;
     * otherwise, once nothing is still queued, it went out. A post still in
     * the queue is in no tile: it is neither out nor refused yet.
     *
     * @param  list<string>  $visible
     */
    private function inStatus($query, string $status, array $visible)
    {
        $targets = fn ($q) => $q->whereIn('social_connection_id', $visible);

        return match ($status) {
            'failed' => $query->whereHas('targets', fn ($q) => $targets($q)
                ->where('status', SocialPostTarget::STATUS_FAILED)),
            'scheduled' => $query->whereDoesntHave('targets', fn ($q) => $targets($q)
                ->where('status', '!=', SocialPostTarget::STATUS_SCHEDULED)),
            'sent' => $query
                ->whereDoesntHave('targets', fn ($q) => $targets($q)
                    ->whereIn('status', [SocialPostTarget::STATUS_FAILED, SocialPostTarget::STATUS_QUEUED]))
                ->whereHas('targets', fn ($q) => $targets($q)
                    ->where('status', '!=', SocialPostTarget::STATUS_SCHEDULED)),
        };
    }

    private function presentTarget(SocialPostTarget $target, ?SocialConnection $connection): array
    {
        return [
            'id' => $target->getKey(),
            'connection_id' => $target->social_connection_id,
            // A Business Profile target is one listing of the account: name the place.
            'account' => ($target->options['gbp_title'] ?? null) ?: $connection?->display_name,
            'provider' => $connection?->provider,
            'provider_label' => $connection?->providerLabel(),
            'status' => $target->status,
            'failure' => $target->failure,
            'sent_at' => $target->sent_at?->toIso8601String(),
            'url' => $target->url,
            // The server's answer, so the Delete button never drifts from what works.
            'can_delete' => $target->status === SocialPostTarget::STATUS_SENT
                && Publishers::for($connection?->providerEnum()) instanceof DeletesPosts,
        ];
    }
}
