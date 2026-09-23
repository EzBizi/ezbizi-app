<?php

namespace Tests\Feature\TimeClock;

use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class CloseEndedShiftsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_only_past_due_open_shifts_are_closed_and_stamped(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $a = $this->userWithRole($alpha, 'A', ['time_clock.view']);
        $b = $this->userWithRole($beta, 'B', ['time_clock.view']);
        $c = $this->userWithRole($alpha, 'C', ['time_clock.view']);
        $d = $this->userWithRole($alpha, 'D', ['time_clock.view']);

        Carbon::setTestNow(Carbon::parse('2026-09-08 22:00:30', 'UTC'));

        $due = $this->row($alpha['org'], $a, '2026-09-08 22:00:00');          // past due, alpha
        $dueElsewhere = $this->row($beta['org'], $b, '2026-09-08 21:59:00');  // past due, beta
        $future = $this->row($alpha['org'], $c, '2026-09-08 22:01:00');       // not yet
        $unscheduled = $this->row($alpha['org'], $d, null);                    // never

        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        // The END it was sweeping, not the half-minute the scheduler took to
        // get there (2026-09-23). The difference is seconds here and DAYS on a
        // row the sweep could not see until a backfill gave it an end, which
        // is where crediting now() would have invented forty hours.
        $this->assertSame('2026-09-08 22:00:00', $this->fresh($due)->clocked_out_at->toDateTimeString());
        $this->assertSame(TimeClockShift::ENDED_BY_SCHEDULE, $this->fresh($due)->ended_by);
        $this->assertSame(TimeClockShift::ENDED_BY_SCHEDULE, $this->fresh($dueElsewhere)->ended_by);
        $this->assertNull($this->fresh($future)->clocked_out_at);
        $this->assertNull($this->fresh($unscheduled)->clocked_out_at);

        $this->assertSame(2, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.clocked_out')->count());
        $this->assertSame(2, AutomationEvent::query()->withoutGlobalScopes()->where('event_key', 'time_clock.clocked_out')->count());
    }

    public function test_a_second_run_finds_nothing(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $a = $this->userWithRole($alpha, 'A', ['time_clock.view']);
        Carbon::setTestNow(Carbon::parse('2026-09-08 22:00:30', 'UTC'));
        $this->row($alpha['org'], $a, '2026-09-08 22:00:00');

        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();
        $this->artisan('ezbizi:close-ended-shifts')->assertSuccessful();

        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.clocked_out')->count());
    }

    private function row(Organization $org, User $user, ?string $end): TimeClockShift
    {
        return $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => '2026-09-08 13:00:00',
            'scheduled_end_at' => $end,
        ]));
    }

    private function fresh(TimeClockShift $shift): TimeClockShift
    {
        return TimeClockShift::query()->withoutGlobalScopes()->findOrFail($shift->getKey());
    }
}
