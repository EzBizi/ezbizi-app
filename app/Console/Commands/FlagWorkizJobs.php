<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkizJob;
use App\Models\WorkizTeamMember;
use App\Support\Jobs\JobAddress;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every 15 minutes: Workiz jobs booked or changed lately whose address has no
 * house number, street or postal code, or that start on a Friday after the
 * 12-3 PM window, are flagged once each to whoever booked them and on the
 * branch's board (dispatchers + Jose, 2026-11-17). Each check is a setting.
 */
class FlagWorkizJobs extends Command
{
    public const FRIDAY_RULE = 'Friday jobs must be in the 12–3 window or earlier; confirm with the field manager or move it.';

    protected $signature = 'ezbizi:flag-workiz-jobs {--minutes=20 : How far back to look}
        {--future-only : Backfill: every job still scheduled ahead and not closed or cancelled, however old (Jose, 2026-11-17)}
        {--dry-run : Count what would be flagged; send and record nothing}';

    protected $description = 'Flag Workiz jobs with an incomplete address or a late Friday start';

    public function handle(TenantContext $tenant, SettingsResolver $settings, NotificationDispatcher $notify, TaskBoards $boards, TaskWriter $tasks): int
    {
        $since = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $flagged = 0;

        $dry = (bool) $this->option('dry-run');
        $rows = $tenant->runWithoutTenant(fn () => $this->option('future-only')
            ? WorkizJob::query()->where('scheduled_for', '>', now()->format('Y-m-d H:i:s'))
                ->where(fn ($q) => $q->whereNull('status')->orWhere(fn ($q) => $q
                    ->whereRaw("LOWER(status) NOT LIKE '%cancel%'")->whereRaw("LOWER(status) NOT LIKE 'done%'")
                    ->whereRaw("LOWER(status) NOT LIKE '%complete%'")->whereRaw("LOWER(status) NOT LIKE '%closed%'")))
                ->get()
            : WorkizJob::query()->where('updated_at', '>=', $since)->get());

        foreach ($rows->groupBy('organization_id') as $orgId => $jobs) {
            $organization = Organization::query()->withoutGlobalScopes()->find($orgId);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($organization, $jobs, $settings, $notify, $boards, $tasks, &$flagged, $dry) {
                $checkAddress = (bool) $settings->get('jobs.flag_incomplete_address', $organization);
                $checkFriday = (bool) $settings->get('jobs.flag_late_friday', $organization);

                foreach ($jobs as $w) {
                    $issues = [];
                    if ($checkAddress && ($problems = JobAddress::problems($w->address, $w->postal_code))) {
                        $issues['address'] = 'Address needs fixing ('.implode(', ', $problems).'): "'
                            .trim(implode(', ', array_filter([$w->address, $w->city, $w->postal_code]))).'"';
                    }
                    // Workiz's JobDateTime is the account's own wall clock: no zone to convert.
                    $at = $w->scheduled_for ? Carbon::parse($w->getRawOriginal('scheduled_for')) : null;
                    if ($checkFriday && $at && $at->isFriday() && $at->format('H:i') > '12:00' && $at->isFuture()) {
                        $issues['late_friday'] = self::FRIDAY_RULE;
                    }

                    foreach ($issues as $issue => $sentence) {
                        // The insert is the dedupe: a second sweep finds the row and skips.
                        if ($dry) {
                            $flagged += DB::table('workiz_job_flags')->where(['workiz_job_id' => $w->id, 'issue' => $issue])->exists() ? 0 : 1;

                            continue;
                        }
                        if (DB::table('workiz_job_flags')->insertOrIgnore(['workiz_job_id' => $w->id, 'issue' => $issue, 'created_at' => now()]) === 0) {
                            continue;
                        }
                        $flagged++;
                        $title = 'Job #'.($w->serial_id ?? $w->remote_id).': '.$sentence;

                        $booker = self::booker($w);
                        $job = $w->job_id ? Job::query()->find($w->job_id) : null;
                        if ($booker) {
                            $notify->dispatch(title: $title, subject: $job, source: 'system', userIds: [$booker->getKey()]);
                        }

                        self::flagTask($boards, $tasks, Franchise::query()->find($w->franchise_id), $title, self::WHY[$issue] ?? null, $job, [$booker?->getKey()]);
                    }
                }
            });
        }

        $this->info($flagged.' flag'.($flagged === 1 ? '' : 's').($dry ? ' would be raised.' : ' raised.'));

        return self::SUCCESS;
    }

    /** The one line that says what the flag asks for (desk 11-18: "what the point to them is"). */
    public const WHY = [
        'address' => 'Fix the address in Workiz so the tech can find it',
    ];

    /**
     * The flag on the branch's board, owned by the people its alert reaches (those the
     * board can take), made by EzBizi (desk 11-18: they had no owner and no maker).
     *
     * @param  list<?string>  $userIds
     */
    public static function flagTask(TaskBoards $boards, TaskWriter $tasks, ?Franchise $franchise, string $title, ?string $why, ?Job $job, array $userIds): void
    {
        if ($franchise === null || ! TaskBoards::hasPlaceBoard($franchise)) {
            return;
        }
        $board = $boards->ensureFor($franchise);
        $can = $boards->assignable($board)->pluck('id')->map(fn ($id) => (string) $id)->all();
        // Quiet: the alert above is the bell; the task must not ring a second one.
        $quiet = $tasks->quiet;
        $tasks->quiet = true;
        $tasks->create($board, array_filter([
            'title' => mb_substr($why ? $title.' · '.$why : $title, 0, 255),
            'about_type' => $job ? 'job' : null,
            'about_id' => $job?->id,
            'source' => 'flags',
            'assignee_user_ids' => array_values(array_intersect(array_map('strval', array_filter($userIds)), $can)) ?: null,
        ]), null);
        $tasks->quiet = $quiet;
    }

    /** Workiz CreatedBy → the team member's linked user, else a user of that name. */
    public static function booker(WorkizJob $w): ?User
    {
        $name = mb_strtolower(trim((string) $w->created_by));

        return $name === '' ? null : (User::query()->where('is_active', true)->find(
            WorkizTeamMember::query()->where('franchise_id', $w->franchise_id)
                ->whereRaw('LOWER(name) = ?', [$name])->value('user_id'))
            ?? User::query()->where('is_active', true)->whereRaw('LOWER(name) = ?', [$name])->first());
    }
}
