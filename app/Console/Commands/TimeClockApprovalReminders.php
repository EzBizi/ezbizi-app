<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\TimeClockApprovals;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Each approver's daily "yesterday's hours are waiting" (Daily approval,
 * 2026-10-22). Runs every 15 minutes; for each person holding
 * work_hours.approve whose place has the approval switched on, once their
 * local approval time has passed, it opens yesterday's waiting days and
 * sends one notification. Once per approver per day.
 */
class TimeClockApprovalReminders extends Command
{
    protected $signature = 'ezbizi:time-clock-approval-reminders';

    protected $description = 'Tell each Work Hours approver about yesterday\'s hours waiting for approval';

    public function handle(TenantContext $tenant, SettingsResolver $settings, TimeClock $clock, TimeClockApprovals $approvals, NotificationDispatcher $notify): int
    {
        $sent = 0;

        foreach (User::query()->withoutGlobalScopes()->where('is_active', true)->with('organization')->cursor() as $approver) {
            if ($approver->organization === null) {
                continue;
            }
            $sent += $tenant->run($approver->organization, function () use ($approver, $settings, $clock, $approvals, $notify) {
                if (! $approver->hasPermission('work_hours.approve')) {
                    return 0;
                }
                $place = $clock->placeOf($approver);
                if (! $settings->get('time_clock.approval_enabled', $place)) {
                    return 0;
                }
                $now = Carbon::now((string) $settings->get('timezone', $place));
                $at = (string) $settings->get('time_clock.approval_time', $place);
                if ($now->format('H:i') < $at) {
                    return 0;
                }
                $key = "time_clock.approval_reminded:{$approver->id}:{$now->toDateString()}";
                if (! Cache::add($key, true, now()->addDays(2))) {
                    return 0;
                }

                $yesterday = $now->copy()->subDay()->startOfDay();
                $waiting = $approvals->openWaiting($approver, $yesterday);
                if ($waiting->isEmpty()) {
                    return 0;
                }

                $n = $waiting->count();
                $notify->dispatch(
                    title: ($n === 1 ? "1 person's hours" : "{$n} people's hours").' from '.$yesterday->format('l').' are waiting for approval',
                    body: $waiting->map(fn ($row) => $row->user?->name)->filter()->join(', '),
                    subject: $waiting->first(),
                    onlyTo: $approver,
                );

                return 1;
            });
        }

        $this->line("sent: {$sent}");

        return self::SUCCESS;
    }
}
