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

/**
 * Editing and deleting a shift (Jose, 2026-09-23: "we should have a correction
 * system as well to be able to edit or eliminate shifts").
 *
 * He asked for it in the same message as the never-ending shift, and the two
 * belong together: the cap stops a forgotten punch running for ever, and this
 * is how the real number gets set afterwards. A cap is a policy, not a
 * measurement.
 */
class TimeClockCorrectionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_correction_sets_both_times_and_is_audited(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 21:00:10', '2026-09-23 13:00:10');

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", [
                'clocked_in_at' => '2026-09-22T21:00:10Z',
                'clocked_out_at' => '2026-09-22T21:30:00Z',
            ])
            ->assertOk();

        $fresh = $this->fresh($shift);
        $this->assertSame('2026-09-22 21:30:00', $fresh->clocked_out_at->toDateTimeString());
        $this->assertSame(TimeClockShift::ENDED_BY_CORRECTION, $fresh->ended_by);

        $entry = AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.corrected')->sole();
        $this->assertSame('2026-09-23T13:00:10Z', $entry->changes['before']['clocked_out_at']);
        $this->assertSame('2026-09-22T21:30:00Z', $entry->changes['after']['clocked_out_at']);
    }

    /** The reply is the week, because a correction moves three numbers the client cannot recompute. */
    public function test_the_answer_carries_the_recomputed_week(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00'); // 8h

        $week = $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}?on=2026-09-22", [
                'clocked_out_at' => '2026-09-22T17:00:00Z', // 4h
            ])
            ->assertOk()
            ->json('data');

        $person = collect($week['people'])->firstWhere('id', $tech->id);
        $this->assertSame(4 * 3600, $person['total_seconds']);
    }

    /** An open shift is closed by supplying the end — the commonest correction there is. */
    public function test_closing_a_forgotten_punch(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 21:00:10', null);

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", ['clocked_out_at' => '2026-09-22T22:00:00Z'])
            ->assertOk();

        $fresh = $this->fresh($shift);
        $this->assertSame('2026-09-22 22:00:00', $fresh->clocked_out_at->toDateTimeString());
        $this->assertSame(TimeClockShift::ENDED_BY_CORRECTION, $fresh->ended_by);
    }

    /** Absent is UNCHANGED, which is not the same as null. */
    public function test_a_start_only_correction_leaves_the_end_and_who_ended_it_alone(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $this->inTenant($alpha['org'], fn () => $shift->forceFill(['ended_by' => TimeClockShift::ENDED_BY_SCHEDULE])->save());

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", ['clocked_in_at' => '2026-09-22T14:00:00Z'])
            ->assertOk();

        $fresh = $this->fresh($shift);
        $this->assertSame('2026-09-22 14:00:00', $fresh->clocked_in_at->toDateTimeString());
        $this->assertSame('2026-09-22 21:00:00', $fresh->clocked_out_at->toDateTimeString());
        $this->assertSame(TimeClockShift::ENDED_BY_SCHEDULE, $fresh->ended_by, 'the schedule still ended it');
    }

    public function test_a_finished_shift_cannot_be_reopened(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", ['clocked_out_at' => null])
            ->assertStatus(422)
            ->assertJsonPath('errors.clocked_out_at.0', 'A finished shift cannot be reopened. Delete it instead.');

        $this->assertNotNull($this->fresh($shift)->clocked_out_at);
    }

    public function test_the_end_must_be_after_the_start_and_neither_in_the_future(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        Carbon::setTestNow(Carbon::parse('2026-09-23 12:00:00', 'UTC'));
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", ['clocked_out_at' => '2026-09-22T13:00:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clocked_out_at.0', 'Clock out must be after clock in.');

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", ['clocked_out_at' => '2026-09-24T13:00:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clocked_in_at.0', 'A shift cannot be in the future.');

        $this->assertSame('2026-09-22 21:00:00', $this->fresh($shift)->clocked_out_at->toDateTimeString());
    }

    /**
     * Two shifts at once is the same hour counted twice, and no total on Work
     * Hours could show that it had been.
     */
    public function test_a_correction_cannot_overlap_another_shift(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $morning = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 17:00:00');
        $this->row($alpha['org'], $tech, '2026-09-22 18:00:00', '2026-09-22 21:00:00');

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$morning->id}", ['clocked_out_at' => '2026-09-22T19:00:00Z'])
            ->assertStatus(422)
            ->assertJsonPath('errors.clocked_in_at.0', 'These times overlap another shift for the same person.');

        // Touching end-to-start is not overlapping.
        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$morning->id}", ['clocked_out_at' => '2026-09-22T18:00:00Z'])
            ->assertOk();
    }

    public function test_a_deletion_removes_the_row_and_leaves_the_trail(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        $this->signedInAs($manager)->deleteJson("/api/v1/hr/time-clock/shifts/{$shift->id}")->assertOk();

        $this->assertNull(TimeClockShift::query()->withoutGlobalScopes()->find($shift->getKey()));

        $entry = AuditEvent::query()->withoutGlobalScopes()->where('action', 'time_clock.deleted')->sole();
        $this->assertSame('2026-09-22T13:00:00Z', $entry->changes['before']['clocked_in_at']);
    }

    /**
     * Reading the hours and rewriting them are different authorities.
     *
     * The subject holds `hr.hours.view` specifically — the OTHER candidate —
     * because a fixture holding neither would be refused under either rule and
     * could not tell which one is in force.
     */
    public function test_the_work_hours_tab_alone_does_not_let_you_correct(): void
    {
        [$alpha, , $tech] = $this->tenant();
        $reader = $this->userWithRole($alpha, 'Dispatch', ['hr.hours.view'], 'dispatch@alpha.test');
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        // The positive control: this person CAN read the pane. Without it a
        // refusal below would prove only that the fixture reaches nothing.
        $this->signedInAs($reader)->getJson('/api/v1/hr/time-clock/week')->assertOk();

        $this->signedInAs($reader)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$shift->id}", ['clocked_out_at' => '2026-09-22T17:00:00Z'])
            ->assertForbidden();

        $this->signedInAs($reader)->deleteJson("/api/v1/hr/time-clock/shifts/{$shift->id}")->assertForbidden();

        $this->assertSame('2026-09-22 21:00:00', $this->fresh($shift)->clocked_out_at->toDateTimeString());
    }

    /** Somebody else's organization is not reachable by pasting an id. */
    public function test_another_tenants_shift_is_not_found(): void
    {
        [, $manager] = $this->tenant();
        $beta = $this->provisionTenant('Beta');
        $theirs = $this->row($beta['org'], $this->userWithRole($beta, 'Tech', ['time_clock.view']), '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        $this->signedInAs($manager)
            ->patchJson("/api/v1/hr/time-clock/shifts/{$theirs->id}", ['clocked_out_at' => '2026-09-22T17:00:00Z'])
            ->assertNotFound();

        $this->assertNotNull($this->fresh($theirs)->clocked_out_at);
    }

    /** @return array{0: array, 1: User, 2: User} */
    private function tenant(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('time_clock.enabled', $alpha['org'], true));

        return [
            $alpha,
            $this->userWithRole($alpha, 'Hr', ['hr.hours.view', 'hr.hours.all', 'hr.hours.correct'], 'hr@alpha.test'),
            $this->userWithRole($alpha, 'Tech', ['time_clock.view'], 'tech@alpha.test'),
        ];
    }

    private function row(Organization $org, User $user, string $in, ?string $out): TimeClockShift
    {
        return $this->inTenant($org, fn () => TimeClockShift::query()->create([
            'organization_id' => $org->id,
            'user_id' => $user->id,
            'clocked_in_at' => $in,
            'clocked_out_at' => $out,
        ]));
    }

    private function fresh(TimeClockShift $shift): TimeClockShift
    {
        return TimeClockShift::query()->withoutGlobalScopes()->findOrFail($shift->getKey());
    }
}
