<?php

namespace Tests\Feature\TimeClock;

use App\Models\AutomationEvent;
use App\Models\Notification;
use App\Models\TimeClockDayApproval;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Work Hours › Daily approval (Shoval via Jose, 2026-10-22): Ohio's GM sees
 * yesterday's clock-ins, approves or adjusts each with a reason, and is told
 * every morning. Thursday 2026-10-22, 09:00 New York.
 */
class TimeClockApprovalTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_gm_sees_yesterday_approves_one_and_adjusts_one_with_a_reason(): void
    {
        [$alpha, $gm, $ana, $ben] = $this->tenant();
        $this->shift($alpha, $ana, '2026-10-21 12:00', '2026-10-21 20:00');
        $benShift = $this->shift($alpha, $ben, '2026-10-21 13:00', '2026-10-21 21:30');
        $this->shift($alpha, $ana, '2026-10-20 12:00', '2026-10-20 20:00'); // another day

        $day = $this->signedInAs($gm)->getJson('/api/v1/hr/time-clock/approvals')->assertOk()->json('data');
        $this->assertSame('2026-10-21', $day['date'], 'yesterday by default');
        $clocked = array_values(array_filter($day['rows'], fn ($r) => ! $r['no_show']));
        $this->assertEqualsCanonicalizing([$ana->id, $ben->id], array_column(array_column($clocked, 'person'), 'id'));
        $this->assertSame(['waiting', 'waiting'], array_column($clocked, 'status'));
        $this->assertSame(2, $day['counts']['waiting']);

        $this->signedInAs($gm)->postJson('/api/v1/hr/time-clock/approvals', ['user_id' => $ana->id, 'date' => '2026-10-21'])->assertOk();

        // An adjustment needs a reason, and keeps the original.
        $this->signedInAs($gm)->postJson("/api/v1/hr/time-clock/approvals/shifts/{$benShift->id}/adjust", ['clocked_out_at' => '2026-10-21 17:00'])
            ->assertStatus(422);
        $rows = collect($this->signedInAs($gm)->postJson("/api/v1/hr/time-clock/approvals/shifts/{$benShift->id}/adjust", [
            'clocked_out_at' => '2026-10-21 17:00', 'reason' => 'Left at 5, forgot to clock out.',
        ])->assertOk()->json('data.rows'))->keyBy('person.id');

        $this->assertSame('approved', $rows[$ana->id]['status']);
        $this->assertSame($gm->id, $rows[$ana->id]['approved_by']['id']);
        $adjustment = $rows[$ben->id]['adjustments'][0];
        $this->assertSame('5:30 PM', $adjustment['was_out']);
        $this->assertSame('5:00 PM', $adjustment['now_out']);
        $this->assertSame('Left at 5, forgot to clock out.', $adjustment['reason']);
        $this->assertSame('person', $adjustment['was_ended_by'], 'who ended it before the edit');

        $this->signedInAs($gm)->postJson('/api/v1/hr/time-clock/approvals/all', ['date' => '2026-10-21'])->assertOk();
        $this->assertSame(2, TimeClockDayApproval::query()->withoutGlobalScopes()->where('status', 'approved')->count());

        $keys = AutomationEvent::query()->withoutGlobalScopes()->pluck('event_key')->all();
        $this->assertContains('time_clock.shift_adjusted', $keys);
        $this->assertSame(2, collect($keys)->filter(fn ($k) => $k === 'time_clock.day_approved')->count());
    }

    /** A scheduled day with no punch is a no-show row, and Approve all leaves it alone. */
    public function test_a_scheduled_person_with_no_shifts_is_a_no_show(): void
    {
        [$alpha, $gm, $ana] = $this->tenant();
        $this->inTenant($alpha['org'], fn () => $ana->forceFill(['work_schedule' => ['days' => [
            'wednesday' => ['start' => '08:00', 'end' => '16:00'],
        ]]])->save());

        $row = collect($this->signedInAs($gm)->getJson('/api/v1/hr/time-clock/approvals?date=2026-10-21')->assertOk()->json('data.rows'))
            ->firstWhere('person.id', $ana->id);
        $this->assertTrue($row['no_show']);

        $this->signedInAs($gm)->postJson('/api/v1/hr/time-clock/approvals/all', ['date' => '2026-10-21'])->assertOk();
        $this->assertSame(0, TimeClockDayApproval::query()->withoutGlobalScopes()->where('user_id', $ana->id)->count());
    }

    public function test_approving_needs_the_permission(): void
    {
        [$alpha, , $ana] = $this->tenant();
        $hr = $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'hr@alpha.test');

        $this->signedInAs($hr)->getJson('/api/v1/hr/time-clock/approvals')->assertForbidden();

        // The tab alone reads the day but cannot approve.
        $viewer = $this->userWithRole($alpha, 'Viewer', ['work_hours.general.view', 'work_hours.all', 'work_hours.approval.view'], 'viewer@alpha.test');
        $this->signedInAs($viewer)->getJson('/api/v1/hr/time-clock/approvals')->assertOk();
        $this->signedInAs($viewer)->postJson('/api/v1/hr/time-clock/approvals/all', ['date' => '2026-10-21'])->assertForbidden();
        $this->signedInAs($ana)->getJson('/api/v1/hr/time-clock/approvals')->assertForbidden();
    }

    /** Each morning once, after the approval time, only where it is switched on. */
    public function test_the_morning_reminder_tells_the_approver_once(): void
    {
        [$alpha, $gm, $ana] = $this->tenant();
        $this->shift($alpha, $ana, '2026-10-21 12:00', '2026-10-21 20:00');

        Carbon::setTestNow(Carbon::parse('2026-10-22 11:30', 'UTC')); // 07:30 local, before 08:00
        $this->artisan('ezbizi:time-clock-approval-reminders')->assertSuccessful();
        $this->assertSame(0, $this->bell($gm));

        Carbon::setTestNow(Carbon::parse('2026-10-22 12:05', 'UTC')); // 08:05 local
        $this->artisan('ezbizi:time-clock-approval-reminders')->assertSuccessful();
        $this->artisan('ezbizi:time-clock-approval-reminders')->assertSuccessful();
        $this->assertSame(1, $this->bell($gm), 'once a day');
        $this->assertSame(0, $this->bell($ana));
        $this->assertSame(1, TimeClockDayApproval::query()->withoutGlobalScopes()->where('status', 'waiting')->count());
        $this->assertContains('time_clock.day_awaiting_approval', AutomationEvent::query()->withoutGlobalScopes()->pluck('event_key')->all());

        // Tapping it opens Work Hours › Daily approval on yesterday (design 136).
        $meta = $this->signedInAs($gm)->getJson('/api/v1/notifications')->assertOk()->json('data.0.meta');
        $this->assertSame('work_hours', $meta['module']);
        $this->assertSame('time_clock_day_approval', $meta['record']['type']);
        $this->assertSame('2026-10-21', $meta['record']['date']);

        // The control: a place with daily approval off is never reminded.
        [$beta, $otherGm, $bob] = $this->tenant('Beta', approval: false);
        $this->shift($beta, $bob, '2026-10-21 12:00', '2026-10-21 20:00');
        $this->artisan('ezbizi:time-clock-approval-reminders')->assertSuccessful();
        $this->assertSame(0, $this->bell($otherGm));
    }

    /** @return array{0: array, 1: User, 2: User, 3: User} */
    private function tenant(string $name = 'Alpha', bool $approval = true): array
    {
        if (! Carbon::hasTestNow()) {
            Carbon::setTestNow(Carbon::parse('2026-10-22 13:00', 'UTC'));
        }
        $t = $this->provisionTenant($name);
        $set = fn (string $k, mixed $v) => app(TenantContext::class)->run($t['org'], fn () => app(SettingsResolver::class)->set($k, $t['org'], $v));
        foreach (['time_clock.enabled' => true, 'timezone' => 'America/New_York', 'time_format' => '12h', 'time_clock.approval_enabled' => $approval] as $k => $v) {
            $set($k, $v);
        }
        $lower = strtolower($name);

        return [
            $t,
            $this->userWithRole($t, 'GM', ['work_hours.view', 'work_hours.general.view', 'work_hours.all', 'work_hours.approve'], "gm@{$lower}.test"),
            $this->userWithRole($t, 'Tech A', ['time_clock.view'], "ana@{$lower}.test"),
            $this->userWithRole($t, 'Tech B', ['time_clock.view'], "ben@{$lower}.test"),
        ];
    }

    private function shift(array $t, User $user, string $in, string $out): TimeClockShift
    {
        return $this->inTenant($t['org'], fn () => TimeClockShift::query()->create([
            'organization_id' => $t['org']->id, 'user_id' => $user->id,
            'clocked_in_at' => $in, 'clocked_out_at' => $out, 'ended_by' => TimeClockShift::ENDED_BY_PERSON,
        ]));
    }

    private function bell(User $user): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->count();
    }
}
