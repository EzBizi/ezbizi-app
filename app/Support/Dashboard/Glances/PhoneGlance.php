<?php

namespace App\Support\Dashboard\Glances;

use App\Models\PhoneCall;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Phone\PhoneNumberAccess;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * Phone box: what happened on the phone this period, and what is waiting.
 *
 * ── Why answered / missed and not "calls" ────────────────────────
 *
 * A count of calls is a number nobody acts on. The question somebody has on
 * the home screen is whether the phone is being answered, and that is a
 * comparison — 40 of 44 reads differently from 40 of 90, and the bare 40 is
 * the same on both days.
 *
 * ── Why voicemails waiting is a sentence and not a slice ─────────
 *
 * It is not a share of the period's calls; it is a queue standing right now,
 * including messages left last month. Putting it in the pie would make the
 * slices add up to something that is not the total, which is a chart that
 * lies quietly.
 *
 * ── Why blocked calls appear at all ──────────────────────────────
 *
 * A blocked caller never rang a phone, so counting it as missed would put a
 * robocaller in the number somebody reads as "calls we let down". It gets its
 * own slice, and only when there are any, so a company that blocks nobody
 * never sees the word.
 *
 * ── Visibility ───────────────────────────────────────────────────
 *
 * Through [PhoneNumberAccess::readable], the same helper the Records screen
 * uses. A glance with its own scoping rule would be a second answer to "which
 * calls are mine", and the two would drift.
 */
final class PhoneGlance extends DescribedGlance
{
    protected const KEY = 'phone';
    protected const LABEL = 'Phone';
    protected const PERMISSION = 'phone.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';

    /**
     * False: the slices count the PERIOD, not the state of things now.
     *
     * "40 answered" is a fact about the chosen month and changes when the
     * month does. The voicemail sentence beneath it is a snapshot, and that is
     * said in its own words rather than by mislabelling the whole box.
     */
    protected const SNAPSHOT = false;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            $numberIds = app(PhoneNumberAccess::class)->readable($user)->pluck('id')->all();

            if ($numberIds === []) {
                return $this->payload([
                    'empty' => true,
                    // Describes the BOX, not the world — UI/UX's board-wide
                    // ruling of 2026-09-09 (b543a45a). "No phone lines yet"
                    // would be a claim about the organization that a scoped
                    // box cannot support: a franchise reader seeing a subset
                    // would be reading something false.
                    'sentence' => 'No phone lines to show.',
                ]);
            }

            $counts = $this->inboundIn($period, $numberIds);

            $answered = $this->answered($counts);

            $blocked = (int) ($counts[PhoneCall::STATUS_BLOCKED] ?? 0);

            $missed = $this->missed($counts);

            $waiting = PhoneCall::query()
                ->whereIn('number_id', $numberIds)
                ->where('voicemail', true)
                ->whereNull('dealt_at')
                ->count();

            if ((int) $counts->sum() === 0) {
                return $this->payload([
                    'empty' => true,
                    // Same rule, and the desk's sweep did not reach this one:
                    // "No calls came in" is a claim about the world, and for a
                    // franchise reader it is false whenever calls arrived on a
                    // line they cannot see. The period is named because the
                    // box has Week/Month/Quarter/Year chips — without it,
                    // somebody on Week cannot tell an empty week from an empty
                    // phone system.
                    'sentence' => $waiting > 0
                        ? $this->voicemailSentence($waiting)
                        : 'No calls to show for this period.',
                ]);
            }

            $data = [
                'empty' => false,
                'slices' => array_values(array_filter([
                    ['label' => 'Answered', 'total' => $answered],
                    ['label' => 'Missed', 'total' => $missed],

                    // Only when there are any: a company that blocks nobody
                    // has no reason to be shown a zero-width slice and a word
                    // it would have to go and look up.
                    $blocked > 0 ? ['label' => 'Blocked', 'total' => $blocked] : null,
                ])),
            ];

            if ($waiting > 0) {
                $data['sentences'] = [$this->voicemailSentence($waiting)];
            }

            // Answered and missed compare with the same elapsed span one
            // period back. Voicemails waiting is a queue standing now, with
            // no earlier self to compare with.
            $previous = $this->inboundIn($period->previous(), $numberIds);

            $attention = $this->attention($numberIds);
            if ($attention !== []) {
                $data['attention'] = $attention;
            }

            $data['figures'] = [
                GlancePayload::figure('answered', 'answered '.$period->phrase(), $answered, $this->answered($previous)),
                GlancePayload::figure('missed', 'missed', $missed, $this->missed($previous)),
                GlancePayload::figure('voicemail', 'to deal with', $waiting),
            ];

            return $this->payload($data);
        });
    }

    /**
     * Inbound calls in a window, counted by status.
     *
     * startsOn is a wall clock in the viewer's timezone; created_at is stored
     * UTC. Bound as instants, or a franchise an hour behind counts yesterday's
     * calls as today's.
     *
     * @param  list<int|string>  $numberIds
     * @return Collection<string, int>
     */
    private function inboundIn(DashboardPeriod $period, array $numberIds): Collection
    {
        return PhoneCall::query()
            ->whereIn('number_id', $numberIds)
            ->where('direction', PhoneCall::DIRECTION_INBOUND)
            ->whereBetween('created_at', [
                $period->startsOn->copy()->utc(),
                $period->endsOn->copy()->utc(),
            ])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
    }

    /** @param  Collection<string, int>  $counts */
    private function answered(Collection $counts): int
    {
        return (int) ($counts[PhoneCall::STATUS_COMPLETED] ?? 0)
            + (int) ($counts[PhoneCall::STATUS_IN_PROGRESS] ?? 0);
    }

    /**
     * Everything else that arrived and did not get answered. Written as a
     * subtraction rather than a list of statuses so a status added later
     * cannot quietly fall out of the total — which is how a chart comes to
     * show 38 of 44 with no sixth slice to explain the gap.
     *
     * @param  Collection<string, int>  $counts
     */
    private function missed(Collection $counts): int
    {
        return (int) $counts->sum()
            - $this->answered($counts)
            - (int) ($counts[PhoneCall::STATUS_BLOCKED] ?? 0);
    }

    private function voicemailSentence(int $waiting): string
    {
        return $waiting === 1
            ? '1 voicemail to deal with.'
            : "{$waiting} voicemails to deal with.";
    }

    /**
     * What needs somebody, worst first: a phone account that stopped working
     * (no call reaches its lines), then voicemails left over a day, then the
     * newer ones. Counts only, at most three (Dashboard, 2026-10-05).
     *
     * @param  list<string>  $numberIds
     * @return list<array{tone: string, text: string}>
     */
    private function attention(array $numberIds): array
    {
        $broken = \App\Models\PhoneConnection::query()
            ->whereIn('id', \App\Models\PhoneNumber::query()->whereIn('id', $numberIds)->select('connection_id'))
            ->where('status', \App\Models\PhoneConnection::STATUS_BROKEN)
            ->count();

        $waiting = PhoneCall::query()
            ->whereIn('number_id', $numberIds)
            ->where('voicemail', true)
            ->whereNull('dealt_at');
        $old = (clone $waiting)->where('created_at', '<', now()->subDay())->count();
        $new = $waiting->where('created_at', '>=', now()->subDay())->count();

        return array_values(array_filter([
            $broken === 0 ? null : GlancePayload::attention('bad', $broken === 1
                ? '1 phone account is not working'
                : $broken.' phone accounts are not working'),
            $old === 0 ? null : GlancePayload::attention('bad', $old === 1
                ? '1 voicemail waiting over a day'
                : $old.' voicemails waiting over a day'),
            $new === 0 ? null : GlancePayload::attention('caution', $new === 1
                ? '1 new voicemail waiting'
                : $new.' new voicemails waiting'),
        ]));
    }
}
