<?php

namespace Tests\Feature\TimeClock;

use App\Models\AutomationEvent;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-11-13: "disable auto clock out for Technicians, it needs to be
 * Manual for that role. and also send a whatsapp to the area manager". A
 * technician in at 7, out at 6 against a 5 o'clock end: the schedule does not
 * close the shift, and the branch's manager hears In 7:00 AM, Out 6:00 PM
 * (1 h past schedule), 11 h. Wednesday 2026-10-21, New York.
 */
class TimeClockManualClockOutTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_technician_is_not_clocked_out_by_the_schedule_and_the_manager_hears_the_times(): void
    {
        [$alpha, $tech, $manager, $office] = $this->tenant();

        Carbon::setTestNow(Carbon::parse('2026-10-21 11:00:00', 'UTC')); // 7:00 AM
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        Carbon::setTestNow(Carbon::parse('2026-10-21 21:30:00', 'UTC')); // 5:30 PM, past the 5 o'clock end
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->assertNotNull($this->open($alpha, $tech), 'the schedule does not close a technician');

        Carbon::setTestNow(Carbon::parse('2026-10-21 22:00:00', 'UTC')); // 6:00 PM
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $event = $this->raised($alpha, $tech);
        $this->assertNotNull($event, 'the finished shift is raised for the managers\' rule');
        $this->assertSame([$tech->id, "{$tech->name} · Alpha", '7:00 AM', '6:00 PM (1 h past schedule)', '11 h'],
            [$event->payload['user_id'], $event->payload['who'], $event->payload['clocked_in'], $event->payload['clocked_out'], $event->payload['total']]);
        $this->assertStringStartsWith("👷 {$tech->name} · Alpha\n🟢 In: 7:00 AM\n", $event->payload['summary_lines']);

        // The control: the office person's role is not manual, so the schedule
        // closes them and nothing is raised.
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'UTC'));
        $this->signedInAs($office)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        Carbon::setTestNow(Carbon::parse('2026-10-22 21:01:00', 'UTC'));
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->assertNull($this->open($alpha, $office));
        $this->assertNull($this->raised($alpha, $office));
    }

    /** @return array{0: array, 1: User, 2: User, 3: User} */
    private function tenant(): array
    {
        $t = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($t, 'Technician', ['time_clock.view'], 'tech@alpha.test');
        $manager = $this->userWithRole($t, 'Local Manager', ['time_clock.view', 'work_hours.view', 'work_hours.approve'], 'gm@alpha.test');
        $office = $this->userWithRole($t, 'Office', ['time_clock.view'], 'office@alpha.test');
        $week = ['days' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], ['start' => '08:00', 'end' => '17:00'])];

        $this->inTenant($t['org'], function () use ($t, $tech, $office, $week) {
            $set = fn (string $k, mixed $v) => app(SettingsResolver::class)->set($k, $t['org'], $v);
            foreach (['time_clock.enabled' => true, 'timezone' => 'America/New_York', 'time_format' => '12h',
                'time_clock.manual_clock_out_roles' => [$tech->role_id]] as $k => $v) {
                $set($k, $v);
            }
            $tech->forceFill(['work_schedule' => $week])->save();
            $office->forceFill(['work_schedule' => $week])->save();
        });

        return [$t, $tech, $manager, $office];
    }

    private function raised(array $t, $user): ?AutomationEvent
    {
        return app(TenantContext::class)->run($t['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'time_clock.technician_clocked_out')->where('subject_id', $user->id)->first());
    }

    private function open(array $t, $user): ?TimeClockShift
    {
        return app(TenantContext::class)->run($t['org'], fn () => TimeClockShift::query()->open()->where('user_id', $user->id)->first());
    }
}
