<?php

namespace Tests\Feature\TimeClock;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-01. Anisa worked the Tuesday of the A1 Tools switch-over and
 * never clocked in: HR can now Add shift, and she can request the day.
 * And the mark on a changed row: "Shift corrected" when it was asked for,
 * "Edited" when HR changed it unasked, nothing when you edit your own.
 */
class TimeClockAddShiftTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_hr_adds_a_missing_shift_and_it_reads_edited(): void
    {
        [$alpha, $hr, $tech] = $this->tenant();

        $this->signedInAs($hr)->postJson("/api/v1/hr/time-clock/people/{$tech->id}/shifts", [
            'clocked_in_at' => '2026-09-29T13:00:00Z',
            'clocked_out_at' => '2026-09-29T21:00:00Z',
        ])->assertOk();

        $shift = $this->shifts($tech)->sole();
        $this->assertSame('2026-09-29 13:00:00', $shift->clocked_in_at->toDateTimeString());
        $this->assertSame('2026-09-29 21:00:00', $shift->clocked_out_at->toDateTimeString());
        $this->assertSame('hr', $shift->edit_source);

        // Overlap is refused, as for an edit; an open shift cannot be added.
        $this->signedInAs($hr)->postJson("/api/v1/hr/time-clock/people/{$tech->id}/shifts", [
            'clocked_in_at' => '2026-09-29T20:00:00Z', 'clocked_out_at' => '2026-09-29T22:00:00Z',
        ])->assertStatus(422);
        $this->signedInAs($hr)->postJson("/api/v1/hr/time-clock/people/{$tech->id}/shifts", [
            'clocked_in_at' => '2026-09-30T13:00:00Z',
        ])->assertStatus(422);

        // Without manage there is no Add shift.
        $this->signedInAs($tech)->postJson("/api/v1/hr/time-clock/people/{$tech->id}/shifts", [
            'clocked_in_at' => '2026-09-28T13:00:00Z', 'clocked_out_at' => '2026-09-28T21:00:00Z',
        ])->assertForbidden();
    }

    public function test_a_missing_day_is_requested_and_approving_adds_it_as_corrected(): void
    {
        [$alpha, $hr, $tech] = $this->tenant();

        // Both times are needed for a day with no shift.
        $this->signedInAs($tech)->postJson('/api/v1/time-clock/corrections', [
            'clocked_in_at' => '2026-09-29T13:00:00Z', 'reason' => 'Worked, no clock.',
        ])->assertStatus(422);

        $id = $this->signedInAs($tech)->postJson('/api/v1/time-clock/corrections', [
            'clocked_in_at' => '2026-09-29T13:00:00Z',
            'clocked_out_at' => '2026-09-29T21:00:00Z',
            'reason' => 'The day we moved off A1 Tools.',
        ])->assertCreated()->assertJsonPath('data.shift_id', null)->json('data.id');

        $this->assertCount(0, $this->shifts($tech), 'nothing until approved');

        $this->signedInAs($hr)->postJson("/api/v1/hr/time-clock/corrections/{$id}/approve")->assertOk();

        $shift = $this->shifts($tech)->sole();
        $this->assertSame('request', $shift->edit_source);
        $this->assertSame('2026-09-29 21:00:00', $shift->clocked_out_at->toDateTimeString());
    }

    public function test_an_approved_edit_reads_corrected_and_hr_editing_unasked_reads_edited(): void
    {
        [$alpha, $hr, $tech] = $this->tenant();
        $asked = $this->row($alpha['org'], $tech, '2026-09-28 13:00:00', '2026-09-28 21:00:00');
        $unasked = $this->row($alpha['org'], $tech, '2026-09-29 13:00:00', '2026-09-29 21:00:00');

        $id = $this->signedInAs($tech)->postJson('/api/v1/time-clock/corrections', [
            'shift_id' => $asked->id,
            'clocked_in_at' => '2026-09-28T12:00:00Z',
            'clocked_out_at' => '2026-09-28T21:00:00Z',
            'reason' => 'Came in early.',
        ])->assertCreated()->json('data.id');
        $this->signedInAs($hr)->postJson("/api/v1/hr/time-clock/corrections/{$id}/approve")->assertOk();

        $this->signedInAs($hr)->patchJson("/api/v1/hr/time-clock/shifts/{$unasked->id}", [
            'clocked_in_at' => '2026-09-29T12:30:00Z',
        ])->assertOk();

        $this->assertSame('request', $this->fresh($asked)->edit_source, 'start-only, still marked');
        $this->assertSame('hr', $this->fresh($unasked)->edit_source);

        $week = $this->signedInAs($hr)->getJson('/api/v1/hr/time-clock/week?on=2026-09-28')->assertOk()->json('data.people');
        $shifts = collect($week)->firstWhere('id', $tech->id)['shifts'];
        $this->assertSame(['request', 'hr'], array_column($shifts, 'edited'));
    }

    /** "if i edit my own hours it just doesn't say anything" */
    public function test_editing_or_adding_your_own_hours_carries_no_mark(): void
    {
        [$alpha, $hr] = $this->tenant();
        $mine = $this->row($alpha['org'], $hr, '2026-09-28 13:00:00', '2026-09-28 21:00:00');
        $this->inTenant($alpha['org'], fn () => $mine->forceFill(['ended_by' => TimeClockShift::ENDED_BY_SCHEDULE])->save());

        $this->signedInAs($hr)->patchJson("/api/v1/hr/time-clock/shifts/{$mine->id}", [
            'clocked_out_at' => '2026-09-28T22:00:00Z',
        ])->assertOk();
        $this->signedInAs($hr)->postJson("/api/v1/hr/time-clock/people/{$hr->id}/shifts", [
            'clocked_in_at' => '2026-09-29T13:00:00Z', 'clocked_out_at' => '2026-09-29T21:00:00Z',
        ])->assertOk();

        foreach ($this->shifts($hr) as $shift) {
            $this->assertNull($shift->edit_source);
            $this->assertSame(TimeClockShift::ENDED_BY_PERSON, $shift->ended_by, 'not Auto, not Edited');
        }
    }

    /** @return array{0: array, 1: User, 2: User} */
    private function tenant(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('time_clock.enabled', $alpha['org'], true));

        return [
            $alpha,
            $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage', 'time_clock.view'], 'hr@alpha.test'),
            $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test'),
        ];
    }

    private function row(Organization $org, User $user, string $in, ?string $out): TimeClockShift
    {
        return $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id, 'user_id' => $user->id,
            'clocked_in_at' => $in, 'clocked_out_at' => $out,
            'ended_by' => $out === null ? null : TimeClockShift::ENDED_BY_PERSON,
        ]));
    }

    private function shifts(User $user)
    {
        return TimeClockShift::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('clocked_in_at')->get();
    }

    private function fresh(TimeClockShift $shift): TimeClockShift
    {
        return TimeClockShift::query()->withoutGlobalScopes()->findOrFail($shift->getKey());
    }
}
