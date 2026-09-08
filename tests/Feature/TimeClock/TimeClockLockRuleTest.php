<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The four gates of design 2026-09-08 §3.8, one at a time. */
class TimeClockLockRuleTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'],
        'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'],
        'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'],
        'saturday' => null,
        'sunday' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array, 1: User} */
    private function lockedPerson(
        array $permissions = ['time_clock.lock_screen'],
        bool $lockSetting = true,
        bool $exempt = false,
    ): array {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.lock_screen', $lockSetting);
        $person = $this->userWithRole($alpha, 'Locked', $permissions);
        $person = $this->inTenant($alpha['org'], function () use ($person, $exempt) {
            $person->forceFill([
                'work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK],
                'lock_screen_exempt' => $exempt,
            ])->save();

            return $person->fresh();
        });
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC')); // Tuesday 09:00 local

        return [$alpha, $person];
    }

    private function lock(User $person): bool
    {
        return $this->signedInAs($person)->getJson('/api/v1/me')->assertOk()->json('data.time_clock.lock');
    }

    public function test_all_gates_true_and_not_clocked_in_locks(): void
    {
        [, $person] = $this->lockedPerson();
        $this->assertTrue($this->lock($person));
    }

    public function test_an_open_shift_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->assertFalse($this->lock($person));
    }

    public function test_org_lock_off_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson(lockSetting: false);
        $this->assertFalse($this->lock($person));
    }

    public function test_view_without_lock_screen_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson(permissions: ['time_clock.view']);
        $this->assertFalse($this->lock($person));
    }

    public function test_an_exempt_person_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson(exempt: true);
        $this->assertFalse($this->lock($person));
    }

    public function test_a_day_off_does_not_lock(): void
    {
        [, $person] = $this->lockedPerson();
        Carbon::setTestNow(Carbon::parse('2026-09-12 15:00:00', 'UTC')); // Saturday
        $this->assertFalse($this->lock($person));
    }

    public function test_after_the_scheduled_end_still_locks_on_a_working_day(): void
    {
        [, $person] = $this->lockedPerson();
        Carbon::setTestNow(Carbon::parse('2026-09-08 23:30:00', 'UTC')); // Tuesday 18:30 local
        $this->assertTrue($this->lock($person));
    }

    public function test_clock_in_switched_off_for_the_organization_does_not_lock(): void
    {
        [$alpha, $person] = $this->lockedPerson();
        $this->set($alpha['org'], 'time_clock.enabled', false);
        $this->assertFalse($this->lock($person));
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
