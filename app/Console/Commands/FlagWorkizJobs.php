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

    protected $signature = 'ezbizi:flag-workiz-jobs {--minutes=20 : How far back to look}';

    protected $description = 'Flag Workiz jobs with an incomplete address or a late Friday start';

    public function handle(TenantContext $tenant, SettingsResolver $settings, NotificationDispatcher $notify, TaskBoards $boards, TaskWriter $tasks): int
    {
        $since = now()->subMinutes(max(1, (int) $this->option('minutes')));
        $flagged = 0;

        $rows = $tenant->runWithoutTenant(fn () => WorkizJob::query()->where('updated_at', '>=', $since)->get());

        foreach ($rows->groupBy('organization_id') as $orgId => $jobs) {
            $organization = Organization::query()->withoutGlobalScopes()->find($orgId);
            if ($organization === null) {
                continue;
            }

            $tenant->run($organization, function () use ($organization, $jobs, $settings, $notify, $boards, $tasks, &$flagged) {
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
                        if (DB::table('workiz_job_flags')->insertOrIgnore(['workiz_job_id' => $w->id, 'issue' => $issue, 'created_at' => now()]) === 0) {
                            continue;
                        }
                        $flagged++;
                        $title = 'Job #'.($w->serial_id ?? $w->remote_id).': '.$sentence;

                        // Workiz CreatedBy → the team member's linked user, else a user of that name.
                        $name = mb_strtolower(trim((string) $w->created_by));
                        $booker = $name === '' ? null : (User::query()->where('is_active', true)->find(
                            WorkizTeamMember::query()->where('franchise_id', $w->franchise_id)
                                ->whereRaw('LOWER(name) = ?', [$name])->value('user_id'))
                            ?? User::query()->where('is_active', true)->whereRaw('LOWER(name) = ?', [$name])->first());
                        $job = $w->job_id ? Job::query()->find($w->job_id) : null;
                        if ($booker) {
                            $notify->dispatch(title: $title, subject: $job, source: 'system', userIds: [$booker->getKey()]);
                        }

                        $franchise = Franchise::query()->find($w->franchise_id);
                        if ($franchise && TaskBoards::hasPlaceBoard($franchise)) {
                            $tasks->create($boards->ensureFor($franchise), array_filter([
                                'title' => $title,
                                'about_type' => $job ? 'job' : null,
                                'about_id' => $job?->id,
                            ]), null);
                        }
                    }
                }
            });
        }

        $this->info($flagged.' flag'.($flagged === 1 ? '' : 's').' raised.');

        return self::SUCCESS;
    }
}
