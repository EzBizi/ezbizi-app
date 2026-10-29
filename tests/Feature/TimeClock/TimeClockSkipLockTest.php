<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** "Continue without clocking in" (Jose, 2026-10-28). */
class TimeClockSkipLockTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(array $permissions): User
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.lock_screen', true);
        // The schedule's zone is the place's setting, not work_schedule.timezone.
        $this->set($alpha['org'], 'timezone', 'America/New_York');
        $person = $this->userWithRole($alpha, 'Locked', $permissions);
        $days = array_fill_keys(
            ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'],
            ['start' => '08:00', 'end' => '17:00'],
        );
        $person = $this->inTenant($alpha['org'], function () use ($person, $days) {
            $person->forceFill(['work_schedule' => ['days' => $days]])->save();

            return $person->fresh();
        });
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC')); // Tuesday 10:00 in New York

        return $person;
    }

    private function clock(User $person): array
    {
        return $this->signedInAs($person)->getJson('/api/v1/time-clock/me')->assertOk()->json('data.time_clock');
    }

    public function test_a_person_with_the_permission_skips_for_the_rest_of_the_day(): void
    {
        $person = $this->person(['time_clock.lock_screen', 'time_clock.lock_screen_skip']);
        $this->assertTrue($this->clock($person)['lock']);
        $this->assertTrue($this->clock($person)['may_skip']);

        $this->signedInAs($person)->postJson('/api/v1/time-clock/skip-lock')
            ->assertOk()
            ->assertJsonPath('data.time_clock.lock', false)
            ->assertJsonPath('data.time_clock.open', null);

        $this->assertSame(1, AuditEvent::query()->where('action', 'time_clock.lock_skipped')
            ->where('actor_id', $person->getKey())->where('target_id', $person->getKey())->count());
        $this->assertSame(0, TimeClockShift::query()->withoutGlobalScopes()->where('user_id', $person->getKey())->count());

        // 23:30 local, still the same day: still off.
        Carbon::setTestNow(Carbon::parse('2026-09-09 03:30:00', 'UTC'));
        $this->assertFalse($this->clock($person)['lock']);

        // Next day, 08:00 local: locked again.
        Carbon::setTestNow(Carbon::parse('2026-09-09 12:00:00', 'UTC'));
        $this->assertTrue($this->clock($person)['lock']);
    }

    public function test_without_the_permission_it_is_refused_and_still_locks(): void
    {
        $person = $this->person(['time_clock.lock_screen']);
        $this->assertFalse($this->clock($person)['may_skip']);

        $this->signedInAs($person)->postJson('/api/v1/time-clock/skip-lock')->assertForbidden();

        $this->assertTrue($this->clock($person)['lock']);
        $this->assertSame(0, AuditEvent::query()->where('action', 'time_clock.lock_skipped')->count());
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
