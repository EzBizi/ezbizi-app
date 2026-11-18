<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\WorkizJob;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Scheduling\Openings;
use App\Support\Scheduling\RescheduleOffers;
use App\Support\Settings\SettingsResolver;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every minute: a Workiz job booked into a window that was already full when
 * it was booked alerts, once, whoever booked it and the branch's listed field
 * managers, and goes on the branch board (Jose / desk, 2026-11-17: Maryland's
 * 8-11 held 13 of 4; Availability showed it full each time).
 */
class SchedulingFlagFullWindows extends Command
{
    public const RECIPIENTS = 'scheduling.full_window_alert_to';

    protected $signature = 'scheduling:flag-full-windows {--minutes=10 : How far back to look}';

    protected $description = 'Alert when a Workiz job is booked into a window that was already full';

    public function handle(TenantContext $tenant, SettingsResolver $settings, NotificationDispatcher $notify, TaskBoards $boards, TaskWriter $tasks, Openings $openings): int
    {
        $rows = $tenant->runWithoutTenant(fn () => WorkizJob::query()
            ->where('updated_at', '>=', now()->subMinutes(max(1, (int) $this->option('minutes'))))
            ->whereNotNull('job_id')->where('scheduled_for', '>', now()->format('Y-m-d H:i:s'))->get());
        $flagged = 0;

        foreach ($rows->groupBy('organization_id') as $orgId => $jobs) {
            $organization = Organization::query()->withoutGlobalScopes()->find($orgId);
            if ($organization === null) {
                continue;
            }
            $tenant->run($organization, function () use ($jobs, $settings, $notify, $boards, $tasks, $openings, &$flagged) {
                foreach ($jobs as $w) {
                    // One ledger row per slot the job has held: a new slot is a new booking
                    // or a move (desk, 2026-11-17: moves alert too, once per move).
                    $job = Job::query()->find($w->job_id);
                    if ($job === null || $job->scheduled_for === null) {
                        continue;
                    }
                    $slot = 'slot:'.substr((string) $job->scheduled_for, 0, 16);
                    $seen = DB::table('workiz_job_flags')->where('workiz_job_id', $w->id)->where('issue', 'like', 'slot:%')->exists();
                    if (DB::table('workiz_job_flags')->insertOrIgnore(['workiz_job_id' => $w->id, 'issue' => $slot, 'created_at' => now()]) === 0) {
                        continue; // this slot already checked
                    }
                    // An old job seen for the first time (the nightly resync) only sets its baseline.
                    $moved = $seen;
                    if (! $moved && $job->created_at < now()->subDay()) {
                        continue;
                    }
                    $sentence = $this->check($w, $job, $openings, $moved);
                    if ($sentence === null) {
                        continue;
                    }
                    $flagged++;
                    $title = 'Job #'.($w->serial_id ?? $w->remote_id).': '.$sentence;
                    $franchise = Franchise::query()->find($w->franchise_id);

                    $emails = $franchise ? preg_split('/[\s,;]+/', mb_strtolower((string) $settings->get(self::RECIPIENTS, $franchise)), -1, PREG_SPLIT_NO_EMPTY) : [];
                    $to = User::query()->where('is_active', true)->whereIn(DB::raw('LOWER(email)'), $emails ?: ['-'])->pluck('id')
                        ->push(FlagWorkizJobs::booker($w)?->getKey())->filter()->unique()->values()->all();
                    if ($to !== []) {
                        $notify->dispatch(title: $title, subject: $job, source: 'system', userIds: $to, urgent: true);
                    }
                    if ($franchise && TaskBoards::hasPlaceBoard($franchise)) {
                        $tasks->create($boards->ensureFor($franchise), array_filter([
                            'title' => $title, 'about_type' => $job ? 'job' : null, 'about_id' => $job?->id,
                        ]), null);
                    }
                }
            });
        }

        $this->info($flagged.' full-window flag'.($flagged === 1 ? '' : 's').' raised.');

        return self::SUCCESS;
    }

    /** "8-11 AM already had 4 of 4 when it was booked. Next open that day: 2-5 PM." or null when it fit. */
    private function check(WorkizJob $w, Job $job, Openings $openings, bool $moved): ?string
    {
        $area = $job->service_area_id ? ServiceArea::query()->find($job->service_area_id) : null;
        if ($area === null) {
            return null;
        }
        $at = (string) $job->scheduled_for;
        $date = CarbonImmutable::parse(substr($at, 0, 10));
        $time = substr($at, 11, 5);
        $windows = $openings->days($area, $date, 1)[0]['windows'] ?? [];
        $window = collect($windows)->first(fn ($x) => $time >= $x['start_time'] && $time < $x['end_time']);
        $seats = $openings->jobsOn($area, $date);
        if ($window === null || ! $seats->contains(fn ($j) => $j->job_id === $job->id)) {
            return null; // no window, or not holding a seat (cancelled, excluded tag)
        }
        $created = fn ($j) => (is_string($j->payload) ? json_decode($j->payload, true) : (array) $j->payload)['CreatedDate'] ?? null;
        $mine = $created($w) ?? (string) $w->created_at;
        $before = $seats->filter(function ($j) use ($window, $created, $mine, $job, $moved) {
            $t = substr((string) $j->scheduled_for, 11, 5);

            // A move lands on everyone already there; a booking only on those booked before it.
            return $j->job_id !== $job->id && $t >= $window['start_time'] && $t < $window['end_time'] && ($moved || ($created($j) ?? '0') < $mine);
        })->count();
        if ($before < $window['capacity']) {
            return null;
        }
        $next = collect($windows)->first(fn ($x) => $x['remaining'] > 0);

        return RescheduleOffers::window($window['start_time'], $window['end_time'])." already had {$before} of {$window['capacity']} when it was ".($moved ? 'moved there' : 'booked').'. '
            .($next ? 'Next open that day: '.RescheduleOffers::window($next['start_time'], $next['end_time']).'.' : 'That day has no window left.');
    }
}
