<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\TimeClockShift;
use App\Support\Modules\ModuleRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * §2.2 for a module whose only write hides behind an action named `view`.
 *
 * `time_clock.view` means "may punch": clocking in and out creates and closes
 * time_clock_shifts rows. ModuleDefinition::writesRecords() cannot see that
 * (view is read-only by name), so the registry gate never asks whether shifts
 * are audited. They are — every punch, extension and server close writes a
 * row — and this file is where that is pinned rather than assumed.
 */
class TimeClockAuditTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const WEEK = [
        'monday' => ['start' => '08:00', 'end' => '17:00'], 'tuesday' => ['start' => '08:00', 'end' => '17:00'],
        'wednesday' => ['start' => '08:00', 'end' => '17:00'], 'thursday' => ['start' => '08:00', 'end' => '17:00'],
        'friday' => ['start' => '08:00', 'end' => '17:00'], 'saturday' => null, 'sunday' => null,
    ];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_persons_punch_extend_and_clock_out_each_write_an_audit_row_naming_them(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)->set('time_clock.enabled', $alpha['org'], true));
        $person = $this->userWithRole($alpha, 'Tech', ['time_clock.view']);
        $this->inTenant($alpha['org'], fn () => $person->forceFill(['work_schedule' => ['timezone' => 'Etc/GMT+5', 'days' => self::WEEK]])->save());
        Carbon::setTestNow(Carbon::parse('2026-09-08 14:00:00', 'UTC'));

        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-in')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/extend')->assertOk();
        $this->signedInAs($person)->postJson('/api/v1/time-clock/clock-out')->assertOk();

        $shift = $this->inTenant($alpha['org'], fn () => TimeClockShift::query()->where('user_id', $person->id)->firstOrFail());

        foreach (['time_clock.clocked_in', 'time_clock.extended', 'time_clock.clocked_out'] as $action) {
            $row = AuditEvent::query()->withoutGlobalScopes()->where('action', $action)->first();

            $this->assertNotNull($row, "$action was not audited");
            $this->assertSame('user', $row->actor_type, $action);
            $this->assertSame($person->id, $row->actor_id, $action);
            $this->assertSame($shift->id, $row->target_id, $action);
            $this->assertSame($alpha['org']->id, $row->organization_id, $action);
        }
    }

    public function test_the_registry_gate_cannot_see_the_write_so_this_file_is_the_gate(): void
    {
        $module = app(ModuleRegistry::class)->get('time_clock');

        // Every action is read-only BY NAME; the write is behind `view`.
        $this->assertFalse($module->writesRecords());
        $this->assertNull($module->auditExemptBecause, 'not exempt: the punches ARE audited, see above');
        $this->assertContains('time_clock', $module->auditPrefixes());
    }
}
