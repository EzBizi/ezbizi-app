<?php

namespace App\Support\Dashboard\Glances;

use App\Models\ServiceArea;
use App\Models\ServiceAreaSlot;
use App\Models\ServiceAreaZip;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\Overbooking;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * Scheduling box: how many jobs this business is SET UP to take, per weekday.
 *
 * ── It replaces a promise that had a clock on it ─────────────────
 *
 * The placeholder here read "Scheduling will show what is booked here once the
 * Schedule tab is live", which was two errors in one sentence. The Schedule
 * tab has been visible since 9d376605, so the condition read as already met to
 * anyone looking at it; and "what is booked" is a promise nothing can keep
 * until Scheduled Jobs exists, because nothing books a slot. Dashboard's owner
 * raised it — a sentence that will quietly become false is the same failure as
 * a stale release note.
 *
 * The fix is not a better sentence. Scheduling HAS something true to show
 * today: what the business has configured itself to take. That is real,
 * actionable, and answerable now.
 *
 * ── The noun again, deliberately ─────────────────────────────────
 *
 * Every number here is CONFIGURED capacity. Not booked, not remaining. This is
 * the same ruling Jose made for the Availability tab (spec §7): while nothing
 * subtracts bookings, a number called "remaining" would read full for ever, and
 * a confident false number is worse than an absent one. When Scheduled Jobs
 * lands, this box gains the booked count and the WORDING changes with it —
 * both in the same commit, so the label never outlives its meaning.
 *
 * ── Why SNAPSHOT, and not a window ───────────────────────────────
 *
 * `SNAPSHOT = true`, and the period chips deliberately do not move it. Not
 * because a snapshot is simpler: because capacity HAS no history. Editing an
 * area's hours rewrites its slot rows rather than appending to them — that is
 * the storage decision the whole module rests on — so there is no past to
 * bucket over. A period-sensitive box here would answer the same for every
 * chip while implying it had looked, which is the exact class of lie the
 * paragraph above exists to refuse.
 *
 * The axis is the WEEK, not time: seven bars, Monday to Sunday, because "which
 * day are we thin on" is the question a configured week can actually answer.
 */
final class SchedulingGlance extends DescribedGlance
{
    protected const KEY = 'scheduling';
    protected const LABEL = 'Scheduling';
    protected const PERMISSION = 'scheduling.view';
    protected const PRESENTATIONS = ['bar', 'pie', 'text'];
    protected const DEFAULT_PRESENTATION = 'bar';
    protected const SNAPSHOT = true;

    /**
     * Monday first, because a working week reads Mon–Sun. The KEYS stay
     * Carbon's weekday numbers (0 = Sunday) all the way to the database, so
     * nothing translates between two conventions.
     *
     * @var array<int, string>
     */
    private const WEEK = [
        1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
        5 => 'Fri', 6 => 'Sat', 0 => 'Sun',
    ];

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            // franchiseIds() and not companyIds(): it intersects reach in every
            // arm, and Phone shipped a cross-company leak on that difference.
            $reachable = ViewScope::forUser($user)->franchiseIds();

            $areaIds = ServiceArea::whereIn('franchise_id', $reachable)
                ->pluck('id');

            if ($areaIds->isEmpty()) {
                return $this->payload([
                    'empty' => true,
                    // "in this view", not "yet": this box counts only the
                    // franchises in `franchiseIds()` above, so it cannot see
                    // whether the ORGANIZATION has any. A reader looking at one
                    // franchise would have been told the company had none.
                    //
                    // DEPENDS ON THIS BOX STAYING SCOPED. If service areas ever
                    // become organization-wide, "in this view" becomes the
                    // needlessly hedged version and "yet" becomes correct —
                    // and nothing here would fail to tell you. The scoping is
                    // asserted in SchedulingGlanceTest.
                    'sentence' => 'No service areas in this view. Add one in '
                        .'Scheduling settings to say where this business works.',
                ]);
            }

            $perWeekday = ServiceAreaSlot::whereIn('service_area_id', $areaIds)
                ->selectRaw('weekday, SUM(max_jobs) as jobs, COUNT(*) as slots')
                ->groupBy('weekday')
                ->get()
                ->keyBy('weekday');

            $points = [];
            $slices = [];
            $totalJobs = 0;

            foreach (self::WEEK as $weekday => $label) {
                $jobs = (int) ($perWeekday[$weekday]->jobs ?? 0);
                $totalJobs += $jobs;

                // A closed day is a real zero and is DRAWN as one. Absence of
                // rows is how closure is stored; on a chart it has to occupy
                // its column, or a four-day week silently looks like a full one.
                $points[] = ['at' => $label, 'value' => $jobs];
                $slices[] = ['label' => $label, 'total' => $jobs];
            }

            $areas = $areaIds->count();
            $zips = ServiceAreaZip::whereIn('service_area_id', $areaIds)->count();
            $sentences = $this->sentences(
                areas: $areas,
                zips: $zips,
                unconfigured: $this->areasWithNoHours($areaIds),
                totalJobs: $totalJobs,
            );

            return $this->payload([
                'empty' => false,
                'series' => [['label' => 'Jobs a day', 'points' => $points]],
                'slices' => $slices,
                'sentences' => $sentences,
                // Set here, not derived (GlancePayload::withNotes): "2 service
                // areas have no hours set" shares a number and its words with
                // the areas figure, so the derived notes would drop it. The
                // figures say capacity, areas and zips; these lines are the rest.
                'notes' => array_values(array_filter(
                    $sentences,
                    fn (string $s) => ! str_starts_with($s, 'Set up to take ') && ! str_contains($s, ' covering '),
                )),
                // Configured, like every number here: "set up" in the label
                // so the headline cannot read as bookings. No history, so no
                // previous (see SNAPSHOT above).
                'figures' => [
                    GlancePayload::figure('capacity', ($totalJobs === 1 ? 'job' : 'jobs').' a week set up', $totalJobs),
                    GlancePayload::figure('areas', $areas === 1 ? 'service area' : 'service areas', $areas),
                    GlancePayload::figure('zips', $zips === 1 ? 'zip code' : 'zip codes', $zips),
                ],
            ] + array_filter(['attention' => $this->attention($areaIds, $user)]));
        });
    }

    /**
     * What the numbers mean, ordered by what the reader can act on.
     *
     * An area with no hours is first because it is a hole somebody can close
     * this afternoon, and because it is invisible on the chart — it contributes
     * zero to every bar and looks exactly like a business that is simply quiet.
     *
     * @return string[]
     */
    private function sentences(int $areas, int $zips, int $unconfigured, int $totalJobs): array
    {
        $out = [];

        if ($unconfigured > 0) {
            $out[] = $unconfigured === 1
                ? '1 service area has no hours set, so it can take nothing.'
                : "{$unconfigured} service areas have no hours set, so they can take nothing.";
        }

        // Only when there is a number to give. The zero case used to read "No
        // hours set yet, so nothing can be taken." and was BOTH a claim about
        // the organization from a scoped box AND a duplicate: `max_jobs` has a
        // `min:1` validator, so a total of zero means no slot rows exist at
        // all, which means every area is unconfigured, which means the sentence
        // above has already said it and said it with a count.
        //
        // Two sentences saying one thing is how a reader learns to skim the
        // box.
        if ($totalJobs > 0) {
            $out[] = "Set up to take {$totalJobs} "
                .($totalJobs === 1 ? 'job' : 'jobs').' a week.';
        }

        $out[] = sprintf(
            '%d service %s covering %d zip %s.',
            $areas,
            $areas === 1 ? 'area' : 'areas',
            $zips,
            $zips === 1 ? 'code' : 'codes',
        );

        // Says plainly that these are not bookings. Without it the box reads as
        // a workload, which is the misreading the whole module guards against.
        $out[] = 'These are the numbers configured, not what is booked.';

        return $out;
    }

    /**
     * What needs somebody (Dashboard, 2026-10-05): BOOKINGS, unlike every
     * number above, counted the way Scheduling › Overbooking counts them.
     * Overbooked from today, because an overbooked today still needs solving;
     * fully booked from tomorrow, because that is what can still be offered.
     * An area with no hours stays a note: some are left empty on purpose, and
     * a row that never clears teaches the reader to skip the rows.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $areaIds
     * @return list<array{tone: string, text: string}>
     */
    private function attention($areaIds, User $user): array
    {
        $areas = ServiceArea::whereIn('id', $areaIds)->get();
        $tomorrow = Openings::tomorrow((string) app(SettingsResolver::class)->get('timezone', $user->organization));
        $check = app(Overbooking::class);

        $days = collect($check->overbooked($areas, $tomorrow->subDay(), 7))->pluck('date')->unique()->count();
        $full = count($check->fullyBooked($areas, $tomorrow, Overbooking::FULL_DAYS));

        return array_values(array_filter([
            $days === 0 ? null : GlancePayload::attention('bad', "Overbooked on {$days} of the next 7 days"),
            $full === 0 ? null : GlancePayload::attention('caution', $full === 1
                ? '1 service area with no spot for '.Overbooking::FULL_DAYS.'+ days'
                : "{$full} service areas with no spot for ".Overbooking::FULL_DAYS.'+ days'),
        ]));
    }

    /** @param \Illuminate\Support\Collection<int, string> $areaIds */
    private function areasWithNoHours($areaIds): int
    {
        $withHours = ServiceAreaSlot::whereIn('service_area_id', $areaIds)
            ->distinct()
            ->count('service_area_id');

        return $areaIds->count() - $withHours;
    }
}
