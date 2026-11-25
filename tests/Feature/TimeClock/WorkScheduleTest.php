<?php

namespace Tests\Feature\TimeClock;

use App\Support\Settings\SettingsResolver;
use App\Support\TimeClock\WorkSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class WorkScheduleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:10'],
        'wednesday' => ['start' => '08:00', 'end' => '17:15'],
        'thursday' => ['start' => '08:00', 'end' => '17:07'],
        'friday' => ['start' => '08:00', 'end' => '16:35'],
        'saturday' => null,
        'sunday' => null,
    ];

    public function test_window_for_resolves_today_in_the_schedule_zone_across_midnight(): void
    {
        $schedule = ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK];

        // 03:00 UTC Tuesday 2026-09-08 is 22:00 Monday in UTC-5: Monday's window.
        $window = WorkSchedule::windowFor($schedule, Carbon::parse('2026-09-08 03:00:00', 'UTC'));

        $this->assertSame('2026-09-07 13:00:00', $window['start_at']->toDateTimeString());
        $this->assertSame('2026-09-07 22:00:00', $window['end_at']->toDateTimeString());
        $this->assertSame('08:00', $window['start']);
        $this->assertSame('17:00', $window['end']);

        // Saturday in that zone: no window.
        $this->assertNull(WorkSchedule::windowFor($schedule, Carbon::parse('2026-09-12 15:00:00', 'UTC')));
        $this->assertNull(WorkSchedule::windowFor(null, Carbon::parse('2026-09-08 15:00:00', 'UTC')));
    }

    public function test_validate_refuses_overnight_and_missing_days(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('crosses midnight');

        WorkSchedule::validate(['monday' => ['start' => '22:00', 'end' => '06:00']] + self::WEEK);
    }

    public function test_validate_refuses_a_missing_weekday(): void
    {
        $this->expectException(ValidationException::class);

        WorkSchedule::validate(array_diff_key(self::WEEK, ['sunday' => null]));
    }

    public function test_hr_edit_saves_a_schedule_and_snapshots_the_view_zone(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $hr = $this->userWithRole($alpha, 'Hr', ['hr.records.view', 'hr.edit']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('timezone', $alpha['org'], 'Etc/GMT+6'));

        $this->signedInAs($hr)
            ->patchJson("/api/v1/hr/people/{$person->id}/schedule", ['days' => self::WEEK, 'timezone' => 'Europe/Madrid'])
            ->assertOk()
            ->assertJsonPath('data.work_schedule.timezone', 'Etc/GMT+6')
            ->assertJsonPath('data.work_schedule.days.friday.end', '16:35')
            ->assertJsonPath('data.work_schedule.days.saturday', null);

        $this->assertSame('Etc/GMT+6', $person->fresh()->work_schedule['timezone']);
    }

    public function test_a_reader_without_hr_edit_cannot_set_a_schedule(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $reader = $this->userWithRole($alpha, 'Reader', ['hr.records.view']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->signedInAs($reader)
            ->patchJson("/api/v1/hr/people/{$person->id}/schedule", ['days' => self::WEEK])
            ->assertForbidden();

        $this->assertNull($person->fresh()->work_schedule);
    }

    public function test_hr_edit_toggles_the_exemption(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $hr = $this->userWithRole($alpha, 'Hr', ['hr.records.view', 'hr.edit']);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $this->signedInAs($hr)
            ->patchJson("/api/v1/hr/people/{$person->id}", ['lock_screen_exempt' => true])
            ->assertOk();

        $this->assertTrue($person->fresh()->lock_screen_exempt);

        $row = collect($this->signedInAs($hr)->getJson('/api/v1/hr/people')->json('data'))
            ->firstWhere('id', $person->id);
        $this->assertTrue($row['lock_screen_exempt']);
        $this->assertNull($row['work_schedule']);
    }

    /**
     * Jose, 2026-11-25: "Disable people changing their own Work Schedule for
     * all roles except Administrator". The organization's switch still has to
     * be on; then only an Administrator may change their own. A manager
     * still sets other people's.
     */
    public function test_only_an_administrator_changes_their_own_schedule_and_managers_still_set_others(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $tech = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $manager = $this->userWithRole($alpha, 'Manager', ['work_hours.view', 'work_hours.general.view', 'work_hours.all', 'work_hours.manage']);
        $admin = $alpha['admin'];

        $this->signedInAs($admin)->patchJson('/api/v1/me/schedule', ['days' => self::WEEK])->assertForbidden();   // switch off
        $this->inTenant($alpha['org'], fn () => app(SettingsResolver::class)->set('time_clock.self_schedule', $alpha['org'], true));

        $this->signedInAs($tech)->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('can_edit_schedule', false);
        $this->signedInAs($tech)->patchJson('/api/v1/me/schedule', ['days' => self::WEEK])->assertForbidden();

        $this->signedInAs($admin)->getJson('/api/v1/me/profile')->assertOk()->assertJsonPath('can_edit_schedule', true);
        $this->signedInAs($admin)->patchJson('/api/v1/me/schedule', ['days' => self::WEEK])
            ->assertOk()->assertJsonPath('work_schedule.days.monday.start', '08:00');

        // A manager sets someone else's as before, but not their own through the same door.
        $this->signedInAs($manager)->patchJson("/api/v1/hr/people/{$tech->id}/schedule", ['days' => self::WEEK])->assertOk();
        $this->signedInAs($manager)->patchJson("/api/v1/hr/people/{$manager->id}/schedule", ['days' => self::WEEK])->assertForbidden();
    }
}
