<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\TimeClockPayrollController;
use App\Mail\PayrollSheetMail;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\EventEmitter;
use App\Support\Mail\MailDispatcher;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Reports\Xlsx;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\TimeClock;
use App\Support\TimeClock\TimeClockPayroll;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Work Hours › Payroll's two sends (Merav via Jose, 2026-11-19), every 15
 * minutes, each once a week:
 *
 *   Sunday 5 PM, the branch's own clock — each branch manager (holds
 *   work_hours.payroll at a company or branch, not organization-wide) hears
 *   their week is ready: in the bell, by email through their notification
 *   preferences, and as time_clock.payroll_ready for a rule.
 *
 *   Monday 8 AM, the organization's clock — time_clock.payroll_recipients get
 *   the whole company's week as a spreadsheet, a tab a branch plus All.
 */
class TimeClockPayrollSends extends Command
{
    protected $signature = 'ezbizi:time-clock-payroll';

    protected $description = 'Sunday: tell branch managers their payroll week is ready. Monday: email the company payroll sheet';

    public const REVIEW_AT = '17:00';

    public const OFFICE_AT = '08:00';

    public function handle(TenantContext $tenant, SettingsResolver $settings, TimeClock $clock, TimeClockPayroll $payroll,
        NotificationDispatcher $notify, EventEmitter $events, MailDispatcher $mail, AuditLogger $audit): int
    {
        foreach (Organization::query()->withoutGlobalScopes()->cursor() as $org) {
            $tenant->run($org, function () use ($org, $settings, $clock, $payroll, $notify, $events, $mail, $audit) {
                if (! $settings->get('time_clock.enabled', $org)) {
                    return;
                }
                try {
                    // Off until the Payroll screen ships with it (desk, 2026-11-19).
                    if ($settings->get('time_clock.payroll_manager_review', $org)) {
                        $this->managers($org, $settings, $clock, $payroll, $notify, $events);
                    }
                    $this->office($org, $settings, $clock, $payroll, $mail, $audit);
                } catch (Throwable $e) {
                    report($e);   // one organization's failure must not cost the others theirs
                }
            });
        }

        return self::SUCCESS;
    }

    private function managers(Organization $org, SettingsResolver $settings, TimeClock $clock, TimeClockPayroll $payroll,
        NotificationDispatcher $notify, EventEmitter $events): void
    {
        foreach (User::query()->where('is_active', true)->get() as $manager) {
            if (! $manager->hasPermission('work_hours.payroll')) {
                continue;
            }
            $place = $clock->placeOf($manager);
            if ($place instanceof Organization) {
                continue;   // organization-wide people get Monday's company sheet, not a branch's
            }
            $now = Carbon::now((string) $settings->get('timezone', $place));
            if (! $now->isSunday() || $now->format('H:i') < self::REVIEW_AT) {
                continue;
            }
            if (! Cache::add("time_clock.payroll_review:{$manager->id}:{$now->toDateString()}", true, now()->addDays(2))) {
                continue;
            }
            $week = $payroll->week($manager, $now->toDateString());
            $t = $week['totals'];
            if ($t['people'] === 0) {
                continue;
            }
            $notify->dispatch(
                title: "Payroll {$week['label']} is ready to approve",
                body: "{$t['people']} people, {$t['hours']} hours".($t['with_warnings'] > 0 ? ", {$t['with_warnings']} need a look" : '')
                    .'. Work Hours › Payroll: add bonuses or deductions, then approve.',
                subject: $manager,
                onlyTo: $manager,
                source: 'work_hours',
            );
            $events->emit('time_clock.payroll_ready', $manager, ['week_start' => $week['week_start'], 'people' => $t['people'],
                'waiting' => $t['waiting'], 'with_warnings' => $t['with_warnings']]);
        }
    }

    private function office(Organization $org, SettingsResolver $settings, TimeClock $clock, TimeClockPayroll $payroll,
        MailDispatcher $mail, AuditLogger $audit): void
    {
        $to = array_values(array_filter(preg_split('/[\s,;]+/', (string) $settings->get('time_clock.payroll_recipients', $org)), fn ($a) => filter_var($a, FILTER_VALIDATE_EMAIL)));
        $tz = (string) $settings->get('timezone', $org);
        $now = Carbon::now($tz);
        if ($to === [] || ! $now->isMonday() || $now->format('H:i') < self::OFFICE_AT
            || ! Cache::add("time_clock.payroll_office:{$org->id}:{$now->toDateString()}", true, now()->addDays(2))) {
            return;
        }
        $start = $clock->weekStartFor($now->copy()->subDay()->toDateString(), $tz, (string) $settings->get('time_clock.week_starts_on', $org));
        $week = $payroll->sheet(User::query()->where('is_active', true)->with('role:id,name')->orderBy('name')->get(), $start, $tz);
        if ($week['rows'] === []) {
            return;
        }
        $sheets = ['All' => TimeClockPayrollController::table($week['rows']), 'Tech expenses' => TimeClockPayrollController::expenses($week['rows'])];
        foreach (collect($week['rows'])->groupBy(fn ($r) => $r['person']['branch'] ?: $org->name)->sortKeys() as $branch => $rows) {
            $sheets[mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string) $branch), 0, 31)] = TimeClockPayrollController::table($rows->values()->all());
        }
        $mail->platformForTenant($org, new PayrollSheetMail($org->name, $week, Xlsx::write($sheets)), $to);
        $audit->log('time_clock.payroll_sent', $org, metadata: ['to' => $to, 'week_start' => $week['week_start'], 'rows' => count($week['rows'])]);
    }
}
