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
            $connections = SocialConnection::query()->get()
                ->filter(fn (SocialConnection $c) => $this->access->inView($user, $c));

            $visible = $connections->map(fn (SocialConnection $c) => $c->getKey())->values()->all();

            // Broken (a refresh failed) and revoked (the owner removed the app
            // on the network) both mean nothing posts there until somebody
            // reconnects it.
            $disconnected = $connections
                ->filter(fn (SocialConnection $c) => $c->status !== SocialConnection::STATUS_CONNECTED)
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
                /*
                 * "in this view", not "yet" — UI/UX's ruling of 2026-09-09
                 * (`30ff2369`): a glance sentence describes the BOX, not the
                 * world.
                 *
                 * This box is scoped by `inView` above, so "yet" told a branch
                 * manager the BUSINESS had published nothing when all they
                 * were being shown was their own slice of it. The sibling
                 * sentence twenty lines up already said "in this view" and was
                 * the form the board settled on, which is the part worth
                 * knowing: the two sentences in this one file were one
                 * compliant and one not.
                 *
                 * The word "yet" is not banned. It is honest about the box's
                 * OWN rows — Leads keeps "N leads not yet dealt with." The
                 * test is what the sentence claims, not which words it uses.
                 */
                return $this->payload([
                    'empty' => true,
                    'sentence' => 'Nothing posted or scheduled in this view.',
                ]);
            }

            $failed = $this->countIn($visible, SocialPostTarget::STATUS_FAILED, $period);

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

            /*
             * ── The ALL-CLEAR sentences are the dangerous ones ───────
             *
             * These two fire when there IS data and it is all fine, which
             * makes them a worse class than the empty state fixed above. An
             * empty state that overclaims tells somebody there is nothing and
             * they go and look. An all-clear tells somebody NOTHING NEEDS
             * THEIR ATTENTION, and they do not look. The failure mode is
             * silence rather than confusion.
             *
             * "Everything sent went out." overclaimed on TWO axes, not one.
             * `$failed` is scoped by `inView` AND windowed to the period, so
             * the sentence promised a branch reader that nothing had failed
             * anywhere, ever — while a refused post on an account they cannot
             * see sat in the tab, and a failure last month sat outside the
             * window. Both qualifiers are now in the words.
             *
             * `$waiting` is scoped but NOT windowed, deliberately (see the
             * class docblock), so its sentence carries only the scope. Do not
             * add a period to it to make the two look alike — it would be
             * false.
             *
             * The COUNT branches are left alone. "1 post did not reach its
             * account." describes the box's own rows, which is what the rule
             * permits; and the third sentence names the period for the box.
             * Neither of these was a wrong TENSE the way Leads' "No star
             * ratings yet." was — those reviews were present and simply
             * unrated, so nothing was being awaited. These two were plain
             * scope claims.
             */
            $sentences = [
                $failed === 0
                    ? "Nothing in this view failed {$period->phrase()}."
                    : ($failed === 1
                        ? '1 post did not reach its account.'
                        : "{$failed} posts did not reach their accounts."),
                $waiting === 0
                    ? 'Nothing in this view is waiting to go out.'
                    : ($waiting === 1
                        ? '1 post is waiting to go out.'
                        : "{$waiting} posts are waiting to go out."),
                $inPeriod === 1
                    ? "1 published {$period->phrase()}."
                    : "{$inPeriod} published {$period->phrase()}.",
            ];

            $points = [];
            $slices = [];

            foreach ($counts as $at => $value) {
                $points[] = ['at' => $at, 'value' => $value];
                $slices[] = ['label' => $at, 'total' => $value];
            }

            $previous = $period->previous();

            /*
             * Rows that need somebody, most broken first: a failure already
             * happened, a disconnected account will fail every post sent to
             * it, and a post still waiting on one is the failure about to be.
             */
            $stranded = $disconnected === [] ? 0 : (int) $this->inReach($disconnected)
                ->whereIn('status', [SocialPostTarget::STATUS_SCHEDULED, SocialPostTarget::STATUS_QUEUED])
                ->count();
            $broken = count($disconnected);

            $attention = array_values(array_filter([
                $failed === 0 ? null : GlancePayload::attention('bad', $sentences[0]),
                $broken === 0 ? null : GlancePayload::attention('bad', $broken === 1
                    ? '1 account needs reconnecting.'
                    : "{$broken} accounts need reconnecting."),
                $stranded === 0 ? null : GlancePayload::attention('caution', $stranded === 1
                    ? '1 waiting post is on an account that needs reconnecting.'
                    : "{$stranded} waiting posts are on accounts that need reconnecting."),
            ]));

            return $this->payload([
                'empty' => false,
                'series' => [['label' => 'Published', 'points' => $points]],
                'slices' => $slices,
                'sentences' => $sentences,
                // Published and failed compare with the same elapsed span one
                // period back. Waiting is not windowed (see the class
                // docblock), so it has no earlier self to compare with.
                'figures' => [
                    GlancePayload::figure(
                        'published',
                        'published '.$period->phrase(),
                        $inPeriod,
                        $this->countIn($visible, SocialPostTarget::STATUS_SENT, $previous),
                    ),
                    GlancePayload::figure(
                        'failed',
                        'failed',
                        $failed,
                        $this->countIn($visible, SocialPostTarget::STATUS_FAILED, $previous),
                    ),
                    GlancePayload::figure('waiting', 'waiting', $waiting),
                ],
                ...($attention === [] ? [] : ['attention' => $attention]),
            ]);
        });
    }

    /**
     * Targets in one status whose `sent_at` falls in a window, bounded on both
     * ends. The period's bounds are wall times in the view timezone and
     * `sent_at` is stored UTC, so they are bound as UTC instants.
     *
     * @param  string[]  $visible
     */
    private function countIn(array $visible, string $status, DashboardPeriod $period): int
    {
        return (int) $this->inReach($visible)
            ->where('status', $status)
            ->whereBetween('sent_at', [$period->startsOn->copy()->utc(), $period->endsOn->copy()->utc()])
            ->count();
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

    /** @return array<string, int> */
    private function emptyBuckets(DashboardPeriod $period): array
    {
        return $period->emptyBuckets();
    }
}
