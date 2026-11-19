<?php

namespace App\Http\Controllers\Api;

use App\Jobs\PublishSocialTarget;
use App\Models\BusinessLocation;
use App\Models\SocialPostTarget;
use App\Support\Audit\AuditLogger;
use App\Support\Social\SocialConnectionAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The weekly AI Google posts waiting for a person (Jose, 2026-11-03): read the
 * preview, Approve sends it through Social's publisher, Disapprove drops it.
 * auto_post per listing skips the queue; it stays off until Jose says so.
 */
class BusinessProfilePostQueueController extends Controller
{
    /** GET /business-profile/post-queue */
    public function index(Request $request, SocialConnectionAccess $access): JsonResponse
    {
        $this->authorize('viewAny', BusinessLocation::class);
        $user = $request->user();
        $places = BusinessLocation::query()->get()->keyBy(fn ($l) => $l->connection
            ? \App\Support\Social\Publishing\GoogleBusinessProfilePublisher::v4Name($l, $l->connection) : $l->id);

        $rows = SocialPostTarget::query()->where('status', SocialPostTarget::STATUS_AWAITING_APPROVAL)
            ->with(['post', 'connection'])->oldest()->get()
            // The view, not the organization: a company sees its own listings' drafts only.
            ->filter(fn (SocialPostTarget $t) => $t->connection !== null && $access->inView($user, $t->connection))
            ->map(function (SocialPostTarget $t) use ($places) {
                $place = $places[$t->options['gbp_location'] ?? ''] ?? null;

                return ['id' => $t->id, 'body' => $t->post?->body, 'created_at' => $t->created_at?->toIso8601String(),
                    'listing' => ['id' => $place?->id, 'name' => $place?->label() ?? ($t->options['gbp_title'] ?? null),
                        'address' => $place ? trim(implode(', ', array_filter([$place->city, $place->state]))) : null,
                        'auto_post' => (bool) $place?->auto_post]];
            });

        return response()->json(['data' => $rows->values()->all()]);
    }

    /** POST /business-profile/post-queue/{target}/approve {body?}: an edit is allowed before it goes. */
    public function approve(Request $request, SocialPostTarget $target): JsonResponse
    {
        $place = $this->awaiting($target);
        $input = $request->validate(['body' => ['nullable', 'string', 'min:20', 'max:1500'], 'publish_now' => ['sometimes', 'boolean']]);
        abort_unless($place->isLive(), 422, $place->title.' is not live on Google, so Google would not show the post. Nothing was sent.');
        // Approving is not releasing (suggestion 01a1ef57): a post with a time
        // ahead is approved FOR that time and the sweep sends it then; one
        // whose time has passed is refused until somebody picks a new time;
        // one with no time goes now, as the button says.
        $due = $target->post?->dueAt();
        abort_if($due !== null && $due->lte(now()), 422,
            'Its planned time has passed. Pick a new time, then approve it.');
        // No time set: approving publishes now, so the person is told first and
        // says so (Jose, 2026-11-18). Without `publish_now` nothing changes.
        if ($due === null && ! $request->boolean('publish_now')) {
            $account = ($target->options['gbp_title'] ?? null) ?: $place->title;

            return response()->json([
                'code' => 'needs_publish_now_confirm',
                'message' => "This post has no time set — approving publishes it now to {$account}.",
                'accounts' => [$account],
            ], 422);
        }
        $next = $due === null ? SocialPostTarget::STATUS_QUEUED : SocialPostTarget::STATUS_SCHEDULED;
        // One decision per draft: two Approves (two tabs, two people) flip it once and send once.
        if (! $this->decide($target, ['status' => $next])) {
            abort(409, 'This post was already decided.');
        }
        if (($input['body'] ?? null) !== null) {
            $target->post->forceFill(['body' => trim($input['body'])])->save();
        }
        app(AuditLogger::class)->log('business_profile.post_approved', $place);
        if ($next === SocialPostTarget::STATUS_QUEUED) {
            PublishSocialTarget::dispatch($target->getKey());
        }
        $this->writeNext($place);

        return response()->json(['data' => ['id' => $target->id, 'status' => $next,
            'scheduled_for' => $target->post?->scheduled_for]]);
    }

    /** POST /business-profile/post-queue/{target}/disapprove {reason?} */
    public function disapprove(Request $request, SocialPostTarget $target): JsonResponse
    {
        $place = $this->awaiting($target);
        $input = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        if (! $this->decide($target, ['status' => SocialPostTarget::STATUS_DISAPPROVED, 'failure' => $input['reason'] ?? null])) {
            abort(409, 'This post was already decided.');
        }
        app(AuditLogger::class)->log('business_profile.post_disapproved', $place, metadata: ['reason' => $input['reason'] ?? null]);
        $this->writeNext($place);

        return response()->json(['data' => ['id' => $target->id, 'status' => SocialPostTarget::STATUS_DISAPPROVED]]);
    }

    /** Conditional UPDATE: true only for the request that moved it out of awaiting_approval. */
    private function decide(SocialPostTarget $target, array $values): bool
    {
        $moved = SocialPostTarget::query()->whereKey($target->getKey())
            ->where('status', SocialPostTarget::STATUS_AWAITING_APPROVAL)
            ->update($values) === 1;
        if ($moved) {
            SocialPostTarget::announce($target->getKey());
        }

        return $moved;
    }

    /** PUT /business-profile/locations/{location}/auto-post {on}: skip the queue for this listing. */
    public function autoPost(Request $request, BusinessLocation $location): JsonResponse
    {
        $this->authorize('update', $location);
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        if ($location->auto_post !== $on) {
            $location->forceFill(['auto_post' => $on])->save();
            app(AuditLogger::class)->log($on ? 'business_location.auto_post_on' : 'business_location.auto_post_off', $location);
        }

        return response()->json(['data' => ['id' => $location->id, 'auto_post' => $location->auto_post]]);
    }

    /** The listing's next draft, written after the response so the button answers at once. */
    private function writeNext(BusinessLocation $place): void
    {
        // Ids, not models: a model would be restored before the tenant is back in context.
        [$orgId, $placeId] = [app(\App\Support\Tenancy\TenantContext::class)->idOrFail(), $place->id];
        dispatch(fn () => app(\App\Support\Tenancy\TenantContext::class)->run(\App\Models\Organization::query()->findOrFail($orgId),
            fn () => \App\Console\Commands\WeeklyBusinessProfilePosts::draftFor(
                BusinessLocation::query()->with(['connection', 'company', 'franchise'])->findOrFail($placeId))))->afterResponse();
    }

    private function awaiting(SocialPostTarget $target): BusinessLocation
    {
        abort_unless($target->status === SocialPostTarget::STATUS_AWAITING_APPROVAL, 409, 'This post was already decided.');
        $place = BusinessLocation::query()->where('connection_id', $target->social_connection_id)->get()
            ->first(fn ($l) => \App\Support\Social\Publishing\GoogleBusinessProfilePublisher::v4Name($l, $l->connection) === ($target->options['gbp_location'] ?? null));
        abort_if($place === null, 404);
        $this->authorize('update', $place);

        return $place;
    }
}
