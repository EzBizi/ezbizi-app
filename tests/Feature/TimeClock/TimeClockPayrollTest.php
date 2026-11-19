<?php

namespace Tests\Feature\TimeClock;

use App\Mail\PayrollSheetMail;
use App\Models\Customer;
use App\Models\Job;
use App\Models\JobPart;
use App\Models\Notification;
use App\Models\PayRate;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Work Hours › Payroll (Merav via Jose, 2026-11-19). The week Monday Oct 19 –
 * Sunday Oct 25 2026, New York, at Alpha F1.
 */
class TimeClockPayrollTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = '2026-10-19';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_week_pays_rate_times_hours_or_the_salary_plus_bonus_minus_deductions_and_says_what_is_missing(): void
    {
        [$t, $manager, $hourly, $salaried, $unrated] = $this->tenant();
        $this->shift($t, $hourly, '2026-10-19 12:00', '2026-10-19 20:00');   // 8 h
        $this->shift($t, $hourly, '2026-10-20 12:00', '2026-10-20 21:00');   // 9 h
        $this->shift($t, $unrated, '2026-10-21 12:00', null);                 // still in, no rate
        $this->rate($t, $hourly, 'hourly', '25.00', 'hour');
        $this->rate($t, $salaried, 'salary', '52000', 'year');

        $rows = fn () => collect($this->signedInAs($manager)->getJson('/api/v1/hr/time-clock/payroll?week='.self::WEEK)->assertOk()->json('data.rows'))
            ->keyBy('person.id');

        $this->assertSame([17, 425], [$rows()[$hourly->id]['hours'], $rows()[$hourly->id]['pay']]);
        $this->assertSame(1000, $rows()[$salaried->id]['pay'], '52,000 a year is 1,000 a week');
        $this->assertNull($rows()[$unrated->id]['pay']);
        // Business hours stand in for a personal schedule, so the weekdays not worked are no-shows too.
        $this->assertEqualsCanonicalizing(['no_rate', 'open_shift', 'no_show'], $rows()[$unrated->id]['warnings']);
        $this->assertNotContains('no_rate', $rows()[$hourly->id]['warnings']);

        $this->signedInAs($manager)->putJson("/api/v1/hr/time-clock/payroll/{$hourly->id}", [
            'week' => self::WEEK, 'bonus' => 50, 'deductions' => 20, 'note' => 'Saturday job; uniform',
        ])->assertOk();
        $this->assertSame(455, $rows()[$hourly->id]['pay']);

        // A part the technician paid for that week is reimbursed in the pay, line by line;
        // one already reimbursed is not (Merav, 2026-11-19).
        $this->inTenant($t['org'], function () use ($t, $hourly) {
            $job = Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => Customer::create([
                'owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1', 'name' => 'A Customer'])->id,
                'number' => 'JOB-1', 'title' => 'Cap', 'status' => 'Done', 'amount_minor' => 0, 'currency' => 'USD']);
            foreach ([null, '2026-10-22 15:00'] as $reimbursed) {
                JobPart::create(['organization_id' => $t['org']->id, 'job_id' => $job->id, 'kind' => 'part', 'item' => 'Chimney cap',
                    'quantity' => 2, 'price_minor' => 1500, 'paid_by_user_id' => $hourly->id, 'paid_at' => '2026-10-21 15:00', 'reimbursed_at' => $reimbursed]);
            }
        });
        $this->assertSame([30, 485], [$rows()[$hourly->id]['tech_expenses'], $rows()[$hourly->id]['pay']]);
        $this->assertSame(['JOB-1', 'Chimney cap', 30], array_values(array_intersect_key($rows()[$hourly->id]['expense_lines'][0], array_flip(['job_number', 'item', 'amount']))));

        $this->signedInAs($manager)->postJson('/api/v1/hr/time-clock/payroll/approve-all', ['week' => self::WEEK])->assertOk();
        $this->assertSame(['approved'], $rows()->pluck('status')->unique()->values()->all());

        // A change after approval puts it back to waiting.
        $this->signedInAs($manager)->putJson("/api/v1/hr/time-clock/payroll/{$hourly->id}", ['week' => self::WEEK, 'bonus' => 60])->assertOk();
        $this->assertSame('waiting', $rows()[$hourly->id]['status']);

        // Money: the payroll grant, not hours.
        $this->signedInAs($this->userWithRole($t, 'Hours only', ['work_hours.view', 'work_hours.general.view', 'work_hours.all'], 'h@alpha.test'))
            ->getJson('/api/v1/hr/time-clock/payroll')->assertForbidden();
    }

    public function test_sunday_at_five_the_branch_manager_hears_once_and_monday_the_office_gets_the_sheet(): void
    {
        [$t, $manager, $hourly] = $this->tenant();
        $this->shift($t, $hourly, '2026-10-19 12:00', '2026-10-19 20:00');
        $this->rate($t, $hourly, 'hourly', '25.00', 'hour');
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('time_clock.payroll_recipients', $t['org'], 'merav@alpha.test, payroll@alpha.test'));
        Mail::fake();
        $bell = fn () => Notification::query()->withoutGlobalScopes()->where('user_id', $manager->id)->where('title', 'like', 'Payroll%')->count();

        Carbon::setTestNow(Carbon::parse('2026-10-25 20:45', 'UTC'));   // Sunday 4:45 PM
        $this->artisan('ezbizi:time-clock-payroll')->assertSuccessful();
        $this->assertSame(0, $bell());

        Carbon::setTestNow(Carbon::parse('2026-10-25 21:05', 'UTC'));   // Sunday 5:05 PM
        $this->artisan('ezbizi:time-clock-payroll')->assertSuccessful();
        $this->artisan('ezbizi:time-clock-payroll')->assertSuccessful();
        $this->assertSame(1, $bell(), 'once');
        Mail::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-10-26 12:05', 'UTC'));   // Monday 8:05 AM
        $this->artisan('ezbizi:time-clock-payroll')->assertSuccessful();
        $this->artisan('ezbizi:time-clock-payroll')->assertSuccessful();
        Mail::assertSent(PayrollSheetMail::class, 1);
        Mail::assertSent(PayrollSheetMail::class, fn (PayrollSheetMail $m) => $m->hasTo('merav@alpha.test')
            && $m->week['week_start'] === self::WEEK && $m->week['totals']['pay'] == 200 && str_starts_with($m->xlsx, 'PK'));
    }

    /** @return array{0: array, 1: User, 2: User, 3: User, 4: User} */
    private function tenant(): array
    {
        $t = $this->provisionTenant('Alpha');
        foreach (['time_clock.enabled' => true, 'timezone' => 'America/New_York', 'time_clock.week_starts_on' => 'monday'] as $k => $v) {
            $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set($k, $t['org'], $v));
        }
        $at = $t['franchise'];

        return [
            $t,
            $this->userWithRole($t, 'Local Manager', ['work_hours.view', 'work_hours.general.view', 'work_hours.all', 'work_hours.payroll'], 'gm@alpha.test', $at),
            $this->userWithRole($t, 'Tech A', ['time_clock.view'], 'a@alpha.test', $at),
            $this->userWithRole($t, 'Office B', ['time_clock.view'], 'b@alpha.test', $at),
            $this->userWithRole($t, 'Tech C', ['time_clock.view'], 'c@alpha.test', $at),
        ];
    }

    private function shift(array $t, User $user, string $in, ?string $out): void
    {
        $this->inTenant($t['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $t['org']->id, 'user_id' => $user->id, 'clocked_in_at' => $in, 'clocked_out_at' => $out,
            'scheduled_end_at' => $out ?? '2026-10-22 02:00', 'ended_by' => $out === null ? null : TimeClockShift::ENDED_BY_PERSON,
        ]));
    }

    private function rate(array $t, User $user, string $basis, string $amount, string $period): void
    {
        $this->inTenant($t['org'], fn () => PayRate::create(['organization_id' => $t['org']->id, 'user_id' => $user->id,
            'basis' => $basis, 'amount' => $amount, 'period' => $period, 'effective_on' => '2026-01-01']));
    }
}
