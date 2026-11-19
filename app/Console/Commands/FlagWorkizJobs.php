<?php

namespace App\Console\Commands;

use App\Models\Franchise;
use App\Models\Job;
use App\Models\Organization;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
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
    public static function flagTask(TaskBoards $boards, TaskWriter $tasks, ?Franchise $franchise, string $title, ?string $why, ?Job $job, array $userIds, ?string $group = null, ?string $bookedBy = null): void
    {
        if ($franchise === null || ! TaskBoards::hasPlaceBoard($franchise)) {
            return;
        }
        $board = $boards->ensureFor($franchise);
        $can = $boards->assignable($board)->pluck('id')->map(fn ($id) => (string) $id)->all();
        // Quiet: the alert above is the bell; the task must not ring a second one.
        $quiet = $tasks->quiet;
        $tasks->quiet = true;
        $bookedBy = trim((string) $bookedBy) ?: null;
        // The job number lives in its columns, not the title (Jose 11-18); the alert keeps it.
        $workizId = preg_match('/^Job #(\d+):\s*/', $title, $m) ? $m[1] : null;
        $title = (string) preg_replace('/^Job #\d+:\s*/', '', $title);
        $values = [];
        if ($bookedBy) {
            $values[self::bookedByColumn($board)->id] = $bookedBy;
        }
        if ($workizId !== null && $job === null) { // with a job, TaskWriter fills both job-id columns
            $values[self::textColumn($board, TaskWriter::WORKIZ_JOB_COLUMN)->id] = $workizId;
        }
        $tasks->create($board, array_filter([
            'title' => mb_substr(implode(' · ', array_filter([$title, $why, $bookedBy ? 'booked by '.$bookedBy : null])), 0, 255),
            // Its own group when asked (Jose 11-18: overbooking warnings in "Overbooked").
            'group_id' => $group ? self::groupOn($board, $group)->id : null,
            // Who booked it, also when they have no EzBizi account (Jose 11-18).
            'values' => $values ?: null,
            'about_type' => $job ? 'job' : null,
            'about_id' => $job?->id,
            'source' => 'flags',
            'assignee_user_ids' => self::owners($board, $franchise, $userIds, $can) ?: null,
        ]), null);
        $tasks->quiet = $quiet;
    }

    public static function groupOn(TaskBoard $board, string $name): TaskGroup
    {
        return TaskGroup::query()->firstOrCreate(['board_id' => $board->id, 'name' => $name], [
            'organization_id' => $board->organization_id, 'colour' => '#E2445C',
            'position' => (int) TaskGroup::query()->where('board_id', $board->id)->max('position') + 1,
        ]);
    }

    public static function bookedByColumn(TaskBoard $board): TaskColumn
    {
        return self::textColumn($board, 'Booked by');
    }

    public static function textColumn(TaskBoard $board, string $name): TaskColumn
    {
        return TaskColumn::query()->firstOrCreate(['board_id' => $board->id, 'name' => $name], [
            'organization_id' => $board->organization_id, 'type' => 'text',
            'position' => (int) TaskColumn::query()->where('board_id', $board->id)->max('position') + 1,
        ]);
    }

    /**
     * Never no owner (desk 11-18): those alerted whom the board can take, else the
     * branch's listed field managers, else its General Manager (the board's
     * "General Manager - <name>" group).
     *
     * @param  list<?string>  $userIds
     * @param  list<string>  $can
     * @return list<string>
     */
    public static function owners(TaskBoard $board, Franchise $franchise, array $userIds, array $can): array
    {
        $pick = fn (array $ids) => array_values(array_unique(array_intersect(array_map('strval', array_filter($ids)), $can)));
        if ($out = $pick($userIds)) {
            return $out;
        }
        $emails = preg_split('/[\s,;]+/', mb_strtolower((string) app(SettingsResolver::class)->get(SchedulingFlagFullWindows::RECIPIENTS, $franchise)), -1, PREG_SPLIT_NO_EMPTY);
        if ($out = $pick(User::query()->where('is_active', true)->whereIn(DB::raw('LOWER(email)'), $emails ?: ['-'])->pluck('id')->all())) {
            return $out;
        }
        foreach (TaskGroup::query()->where('board_id', $board->id)->where('name', 'like', '%General Manager%')->pluck('name') as $group) {
            $first = trim((string) preg_replace('/^.*General Manager\s*[-–]\s*/iu', '', $group));
            $first = preg_split('/[\s(]+/', $first)[0] ?? '';
            if ($first !== '' && ($out = $pick(User::query()->where('is_active', true)->where('name', 'like', $first.'%')->pluck('id')->all()))) {
                return array_slice($out, 0, 1);
            }
        }

        return [];
    }

    /** Workiz CreatedBy → the team member's linked user, else a user of that name. */
    public static function booker(WorkizJob $w): ?User
    {
        $name = mb_strtolower(trim((string) $w->created_by));

        return $name === '' ? null : (User::query()->where('is_active', true)->find(
            WorkizTeamMember::query()->where('franchise_id', $w->franchise_id)
                ->whereRaw('LOWER(name) = ?', [$name])->value('user_id'))
            ?? User::query()->where('is_active', true)->whereRaw('LOWER(name) = ?', [$name])->first()
            ?? self::byFirstAndLast($name));
    }

    /**
     * Same first and last name, a middle name either side ignored (Jose 11-19:
     * "Sasha Solano" is "Sasha marie Solano"). Only a single active match counts.
     */
    public static function byFirstAndLast(string $name): ?User
    {
        $words = preg_split('/\s+/u', mb_strtolower(trim($name)), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return null;
        }
        [$first, $last] = [$words[0], end($words)];
        $hits = User::query()->where('is_active', true)->where('name', 'like', $first.'%'.$last)->get()
            ->filter(function (User $u) use ($first, $last) {
                $w = preg_split('/\s+/u', mb_strtolower(trim((string) $u->name)), -1, PREG_SPLIT_NO_EMPTY);

                return count($w) >= 2 && $w[0] === $first && end($w) === $last;
            });

        return $hits->count() === 1 ? $hits->first() : null;
    }
}
