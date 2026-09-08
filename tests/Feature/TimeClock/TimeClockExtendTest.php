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

class TimeClockExtendTest extends TestCase
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

    public function test_extend_moves_the_end_by_the_org_length_and_counts(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $this->set($alpha['org'], 'time_clock.extension_minutes', 45);
        $person = $this->scheduledPerson($alpha);
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk()->json('data.time_clock');
        $this->assertSame('2026-09-08T22:45:00Z', $block['scheduled_end_at']);

        $block = $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk()->json('data.time_clock');
        $this->assertSame('2026-09-08T23:30:00Z', $block['scheduled_end_at']);

        $open = $this->inTenant($alpha['org'], fn () => TimeClockShift::openFor($person));
        $this->assertSame(2, $open->extensions);

        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.extended')->count());
    }

    public function test_extend_is_refused_without_a_scheduled_shift(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        $response = $this->signedInAs($person)->postJson('/api/v1/time-clock/extend');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('You are not on a scheduled shift.', $response->getContent());

        // Open but unscheduled (no work_schedule at all): same sentence.
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertStatus(422);
    }

    public function test_extend_works_after_the_grant_is_taken_away(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->set($alpha['org'], 'time_clock.enabled', true);
        $person = $this->scheduledPerson($alpha);
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $this->inTenant($alpha['org'], fn () => $person->role->syncPermissionKeys([]));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk();
    }

    private function scheduledPerson(array $alpha): User
    {
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);

        return $this->inTenant($alpha['org'], function () use ($person) {
            $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save();

            return $person->fresh();
        });
    }

    private function set(Organization $org, string $key, mixed $value): void
    {
        app(TenantContext::class)->run($org, fn () => app(SettingsResolver::class)->set($key, $org, $value));
    }
}
