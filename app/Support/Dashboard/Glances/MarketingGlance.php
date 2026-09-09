<?php

namespace App\Support\Dashboard\Glances;

use App\Models\SocialConnection;
use App\Models\SocialPostTarget;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Social\SocialConnectionAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Marketing box: what went out, what is still waiting, and what silently did not.
 *
 * ── It leads with failures, not with the total ───────────────────
 *
 * A published count is the number people expect and the one nobody acts on. A
 * FAILED publish is the expensive one, and it is expensive precisely because
 * it is quiet: the post exists, the composer shows it, and the only place the
 * refusal appears is a badge on one target inside the tab. A business can
 * believe it posted all week. So failures lead, waiting comes second because
 * it is what goes wrong next, and the total comes last.
 *
 * Same ordering rule as {@see ReviewsGlance}: by what the reader can change,
 * not by what is conventional.
 *
 * ── The grain is the TARGET, not the post ────────────────────────
 *
 * One post to three accounts is three publications, three separate refusals
 * and three separate results. Counting posts would report that same post as
 * one success while two of its three networks rejected it.
 *
 * ── Newsletter is deliberately not here ──────────────────────────
 *
 * Newsletter keeps its own dashboard inside its tab, with a calendar of what
 * is scheduled. A second summary one click from the real one drifts from it,
 * and the drift is invisible because nobody puts the two on screen together.
 * That was a decision, not an omission, and it is still the right one.
 *
 * ── Windowed, not a snapshot ─────────────────────────────────────
 *
 * SNAPSHOT = false: published is a figure about a period and must move with
 * the chips. Waiting to go out is a fact about the future and does not, so it
 * is a SENTENCE rather than part of the series — a snapshot number drawn
 * inside a windowed chart is how a box comes to disagree with its own axis.
 */
final class MarketingGlance extends DescribedGlance
{
    protected const KEY = 'marketing';

    protected const LABEL = 'Marketing';

    protected const PERMISSION = 'marketing.view';

    protected const PRESENTATIONS = ['line', 'bar', 'text'];

    protected const DEFAULT_PRESENTATION = 'line';

    protected const SNAPSHOT = false;

    public function __construct(
        private readonly SocialConnectionAccess $access,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            /*
             * §3 is enforced on the CONNECTION, which is where the account is
             * filed, and not on the post. A post has no scope of its own — it
             * reaches whichever accounts it was sent to — so asking it where it
             * belongs would invent an answer.
             *
             * This is the same visible set the Marketing tab itself builds, and
             * deliberately so: a box that counted posts the tab will not show
             * is a box nobody can reconcile.
             */
            $visible = SocialConnection::query()->get()
                ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c))
                ->map(fn (SocialConnection $c) => $c->getKey())
                ->values()
                ->all();

            if ($visible === []) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => 'No accounts connected in this view.',
                ]);
            }

            $from = $period->startsOn->copy()->utc();
            $to = $period->endsOn->copy()->utc();

            $sentEver = (int) $this->inReach($visible)
                ->where('status', SocialPostTarget::STATUS_SENT)
                ->count();

            $waiting = (int) $this->inReach($visible)
                ->whereIn('status', [SocialPostTarget::STATUS_SCHEDULED, SocialPostTarget::STATUS_QUEUED])
                ->count();

            if ($sentEver === 0 && $waiting === 0) {
                return $this->payload([
                    'empty' => true,
                    'sentence' => 'Nothing posted or scheduled yet.',
                ]);
            }

            $failed = (int) $this->inReach($visible)
                ->where('status', SocialPostTarget::STATUS_FAILED)
                ->whereBetween('sent_at', [$from, $to])
                ->count();

            $published = $this->inReach($visible)
                ->where('status', SocialPostTarget::STATUS_SENT)
                ->whereBetween('sent_at', [$from, $to])
                ->get(['id', 'sent_at']);

            $counts = $this->emptyBuckets($period);

            foreach ($published as $target) {
                $at = $target->sent_at;

                if (! $at instanceof Carbon) {
                    continue;
                }

                $bucket = $period->bucket($at);
                $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
            }

            $inPeriod = array_sum($counts);

            $sentences = [
                $failed === 0
                    ? 'Everything sent went out.'
                    : ($failed === 1
                        ? '1 post did not reach its account.'
                        : "{$failed} posts did not reach their accounts."),
                $waiting === 0
                    ? 'Nothing waiting to go out.'
                    : ($waiting === 1
                        ? '1 post is waiting to go out.'
                        : "{$waiting} posts are waiting to go out."),
                $inPeriod === 1
                    ? "1 published this {$period->key}."
                    : "{$inPeriod} published this {$period->key}.",
            ];

            $points = [];
            $slices = [];

            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                $slices[] = ['label' => $at, 'total' => $value];
            }

            return $this->payload([
                'empty' => false,
                'series' => [['label' => 'Published', 'points' => $points]],
                'slices' => $slices,
                'sentences' => $sentences,
            ]);
        });
    }

    /**
     * Targets on an account this view can see.
     *
     * `scheduled` and `queued` are both counted as waiting, and the difference
     * is not the reader's problem here: one waits for its time and the other
     * for the worker, and from a dashboard both are "not out yet". The tab
     * draws that distinction where it matters.
     *
     * @param  string[]  $visible
     */
    private function inReach(array $visible): Builder
    {
        return SocialPostTarget::query()->whereIn('social_connection_id', $visible);
    }

    /** @param  array<string, mixed>  $data */
    private function payload(array $data): GlancePayload
    {
        return new GlancePayload(
            key: $this->key(),
            label: $this->label(),
            presentation: $this->defaultPresentation(),
            allowedPresentations: $this->allowedPresentations(),
            snapshot: $this->snapshot(),
            comingSoon: false,
            options: [],
            data: $data,
        );
    }

    /** @return array<string, int> */
    private function emptyBuckets(DashboardPeriod $period): array
    {
        $out = [];
        $cursor = $period->startsOn->copy()->startOfDay();
        $end = $period->endsOn->copy()->startOfDay();

        while ($cursor->lte($end)) {
            $out[$period->bucket($cursor)] = 0;
            $cursor->addDay();
        }

        return $out;
    }
}
