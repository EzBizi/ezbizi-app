<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Job;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Dashboard\Widgets\JobsByStatus;
use App\Support\Dashboard\Widgets\Revenue;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Jobs box: status pie (snapshot), with today and upcoming as sentences.
 *
 * Slices reuse JobsByStatus so the vocabulary stays the organization's own.
 *
 * No money, deliberately: Jose ruled on 2026-09-29 that the dashboard shows no
 * financial information, because anybody who can open Jobs — a dispatcher —
 * sees this box. Revenue used to be a list and a per-currency line here; it
 * lives in Jobs and Reports now, behind their own gates. `line` left the
 * presentations with it, since the revenue series was the only thing it drew.
 */
final class JobsGlance extends DescribedGlance
{
    protected const KEY = 'jobs';
    protected const LABEL = 'Jobs';
    protected const PERMISSION = 'jobs.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';
    protected const SNAPSHOT = true;

    public function __construct(
        private readonly JobsByStatus $jobsByStatus,
        private readonly Revenue $revenue,
    ) {}

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        // Widgets and Job reads are tenant-scoped; callers (including tests)
        // may resolve outside a request the same way DashboardPeriod::for does.
        return app(TenantContext::class)->run($user->organization, function () use ($user, $period) {
            // Finished work belongs on the pie — the JobsByStatus option defaults
            // off for the old widget board, but this glance always shows the full
            // vocabulary once any job exists.
            $byStatus = $this->jobsByStatus->resolve($user, ['include_finished' => true]);
            $total = (int) ($byStatus['total'] ?? 0);

            // Used only for the wire now — the prompt and the revenue series it
            // once also fed left the dashboard on 2026-09-29 (no money here).
            // The $options argument is not consulted for it at all, which is
            // why it is not even captured by this closure.
            $earned = $this->revenue->earnedStatuses($user);

            $statusChoices = array_column($byStatus['rows'], 'status');

            if ($total === 0) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: $this->wireOptions($earned),
                    data: [
                        'empty' => true,
                        'sentence' => 'No jobs to show.',
                        'status_choices' => $statusChoices,
                    ],
                );
            }

            $slices = array_map(fn (array $row) => [
                'label' => $row['status'],
                'total' => (int) $row['total'],
            ], $byStatus['rows']);

            $data = [
                'empty' => false,
                'slices' => $slices,
                'status_choices' => $statusChoices,
            ];
            $sentences = [];

            // NO MONEY ON THE DASHBOARD — Jose, 2026-09-29: "do not show any
            // financial info on the dashboard widgets, for example dispatchers
            // may have access to jobs so they would see the widget." The box
            // is operational: status, today, coming up. Revenue, its line and
            // the "earned" lines were removed rather than hidden behind a
            // permission, because the ruling names the surface, not a role.
            // Revenue still lives in Jobs and Reports, where it is gated.

            $today = 0;
            $upcoming = 0;
            foreach ($this->scheduledInReach($user) as $job) {
                if ($this->isToday($job)) {
                    $today++;
                }
                if ($this->isUpcoming($job)) {
                    $upcoming++;
                }
            }

            if ($today === 1) {
                $sentences[] = '1 job today.';
            } elseif ($today > 1) {
                $sentences[] = "{$today} jobs today.";
            }

            if ($upcoming === 1) {
                $sentences[] = '1 job coming up.';
            } elseif ($upcoming > 1) {
                $sentences[] = "{$upcoming} jobs coming up.";
            }

            if ($sentences !== []) {
                $data['sentences'] = $sentences;
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: $this->wireOptions($earned),
                data: $data,
            );
        });
    }

    /**
     * What the payload says is selected: the ORGANIZATION's list.
     *
     * Not whatever the reader's saved layout holds. Echoing the layout after
     * the server stopped obeying it would draw a selection that decides
     * nothing.
     *
     * **MEASURED 2026-09-23, AND IT DOES NOT REACH THE CLIENT.**
     * `DashboardController:69` does `$payload['options'] = $options` after
     * resolving, so a glance's own options are always discarded in favour of
     * the saved layout's copy. Nothing is wrong today — with the chip strip
     * deleted (`e55c99a4`) nothing writes a per-user earned list, so the loop
     * is inert rather than lossy — but this method cannot ship.
     *
     * **AND THE OBVIOUS FIX IS THE §8.2 MISTAKE AGAIN, FROM THE OTHER SIDE.**
     * Dashboard measured it and wrote it at their line, `445765e7`; I had
     * assumed dropping :69 was all it needed and that is wrong. The client
     * echoes `options` straight back on the next layout PUT (`_entryFor`) and
     * `saveLayout` stores what it is sent — so letting the organization's value
     * through here would write it into EVERY user's stored preference, per
     * person, chosen by nobody. That is the same authority failure that made
     * the chips a deletion rather than a repoint.
     *
     * One key is carrying two facts: what the BOX is configured with, and what
     * this READER chose. The fix is two keys, which is a wire change and wants
     * deciding rather than patching. Until then this stays correct and
     * unreachable — do not "simplify" it away for being unobservable, and do
     * not reach for the one-line version of the fix.
     *
     * (Provenance, because `git log` on this file will not tell you: the
     * paragraphs above landed inside `498dd905`, whose subject is about a
     * customer duplicates panel. A peer's `git commit --amend` ran while my
     * commit was HEAD and replaced it, keeping the tree. Nothing is wrong with
     * the content; the SHA that carries it simply does not say so.)
     *
     * @param  list<string>  $earned
     */
    private function wireOptions(array $earned): array
    {
        return $earned === [] ? [] : ['earned_statuses' => $earned];
    }

    /**
     * Reachable jobs that have a wall time, with franchise TZ for today /
     * upcoming. Same load for both so a job later today can count twice.
     *
     * @return \Illuminate\Support\Collection<int, Job>
     */
    private function scheduledInReach(User $user)
    {
        return Job::query()
            ->whereIn('franchise_id', ViewScope::forUser($user)->franchiseIds())
            ->whereNotNull('scheduled_for')
            ->with(['franchise:id,timezone'])
            ->orderBy('scheduled_for')
            ->get();
    }

    private function isToday(Job $job): bool
    {
        $tz = $job->franchise?->timezone ?: 'UTC';
        $wall = $job->scheduled_for;

        // Convert into the franchise TZ before taking the calendar date.
        // toDateString() alone is the date in $wall's own timezone.
        return $wall !== null
            && $wall->copy()->timezone($tz)->toDateString() === now($tz)->toDateString();
    }

    private function isUpcoming(Job $job): bool
    {
        $tz = $job->franchise?->timezone ?: 'UTC';
        $wall = $job->scheduled_for;

        return $wall !== null && $wall->gte(now($tz));
    }
}
