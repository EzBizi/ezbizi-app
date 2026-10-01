<?php

namespace Tests\Feature\TimeClock;

use App\Models\Notification;
use App\Models\Organization;
use App\Models\TimeClockCorrectionRequest;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Asking for your own hours to be corrected, approved by whoever manages
 * Work Hours (Trey Brannan's suggestion, 2026-09-30).
 */
class TimeClockCorrectionRequestTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_person_asks_and_a_manager_approves_and_the_shift_changes(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        $id = $this->signedInAs($tech)
            ->postJson('/api/v1/time-clock/corrections', [
                'shift_id' => $shift->id,
                'clocked_in_at' => '2026-09-22T12:00:00Z',
                'clocked_out_at' => '2026-09-22T21:00:00Z',
                'reason' => 'Forgot to clock in when I got here.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        // Nothing moves until it is approved.
        $this->assertSame('2026-09-22 13:00:00', $this->fresh($shift)->clocked_in_at->toDateTimeString());

        // The manager is told; the person who asked is not told about their own ask.
        $this->assertSame(1, $this->bell($manager));
        $this->assertSame(0, $this->bell($tech));

        $this->signedInAs($manager)
            ->getJson('/api/v1/hr/time-clock/corrections')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'Forgot to clock in when I got here.');

        $this->signedInAs($manager)
            ->postJson("/api/v1/hr/time-clock/corrections/$id/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = $this->fresh($shift);
        $this->assertSame('2026-09-22 12:00:00', $fresh->clocked_in_at->toDateTimeString());
        $this->assertSame(1, $this->bell($tech));

        $this->signedInAs($manager)
            ->getJson('/api/v1/hr/time-clock/corrections')
            ->assertJsonCount(0, 'data');
    }

    /**
     * The shift you are in can be asked about too, for its start only (Jose,
     * 2026-10-01). A clock-out sent with it is ignored, and approving moves
     * the start and leaves it running.
     */
    public function test_a_running_shift_can_be_asked_about_for_its_start_only(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $in = now()->subHours(2)->startOfMinute();
        $shift = $this->row($alpha['org'], $tech, $in->toDateTimeString(), null);

        $id = $this->signedInAs($tech)
            ->postJson('/api/v1/time-clock/corrections', [
                'shift_id' => $shift->id,
                'clocked_in_at' => $in->copy()->subHour()->toIso8601ZuluString(),
                'clocked_out_at' => now()->subMinute()->toIso8601ZuluString(),
                'reason' => 'Got here an hour before I clocked in.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.id');

        $this->signedInAs($manager)->postJson("/api/v1/hr/time-clock/corrections/$id/approve")->assertOk();

        $fresh = $this->fresh($shift);
        $this->assertSame($in->copy()->subHour()->toDateTimeString(), $fresh->clocked_in_at->toDateTimeString());
        $this->assertNull($fresh->clocked_out_at, 'still on the clock');
    }

    public function test_a_denied_request_leaves_the_shift_alone_and_says_why(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $id = $this->ask($tech, $shift);

        $this->signedInAs($manager)
            ->postJson("/api/v1/hr/time-clock/corrections/$id/deny", ['note' => 'The camera shows 9am.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'denied');

        $this->assertSame('2026-09-22 13:00:00', $this->fresh($shift)->clocked_in_at->toDateTimeString());

        $this->signedInAs($tech)
            ->getJson('/api/v1/time-clock/corrections')
            ->assertJsonPath('data.0.decision_note', 'The camera shows 9am.');
    }

    public function test_you_can_only_ask_about_your_own_shift(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $theirs = $this->row($alpha['org'], $manager, '2026-09-22 13:00:00', '2026-09-22 21:00:00');

        $this->signedInAs($tech)
            ->postJson('/api/v1/time-clock/corrections', [
                'shift_id' => $theirs->id,
                'clocked_in_at' => '2026-09-22T12:00:00Z',
                'reason' => 'x',
            ])
            ->assertNotFound();
    }

    public function test_one_waiting_request_per_shift(): void
    {
        [$alpha, , $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $this->ask($tech, $shift);

        $this->signedInAs($tech)
            ->postJson('/api/v1/time-clock/corrections', [
                'shift_id' => $shift->id,
                'clocked_in_at' => '2026-09-22T11:00:00Z',
                'reason' => 'Again.',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_answering_needs_work_hours_manage(): void
    {
        [$alpha, , $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $id = $this->ask($tech, $shift);

        $this->signedInAs($tech)->getJson('/api/v1/hr/time-clock/corrections')->assertForbidden();
        $this->signedInAs($tech)->postJson("/api/v1/hr/time-clock/corrections/$id/approve")->assertForbidden();

        $this->assertSame('2026-09-22 13:00:00', $this->fresh($shift)->clocked_in_at->toDateTimeString());
    }

    public function test_an_approval_that_would_overlap_another_shift_is_refused_and_stays_waiting(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $this->row($alpha['org'], $tech, '2026-09-22 08:00:00', '2026-09-22 12:30:00');
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $id = $this->ask($tech, $shift); // asks for 12:00, which overlaps the morning

        $this->signedInAs($manager)
            ->postJson("/api/v1/hr/time-clock/corrections/$id/approve")
            ->assertStatus(422);

        $this->assertSame('pending', TimeClockCorrectionRequest::query()->withoutGlobalScopes()->find($id)->status);
    }

    public function test_the_week_carries_waiting_requests_for_a_manager_only(): void
    {
        [$alpha, $manager, $tech] = $this->tenant();
        $shift = $this->row($alpha['org'], $tech, '2026-09-22 13:00:00', '2026-09-22 21:00:00');
        $id = $this->ask($tech, $shift);

        $this->signedInAs($manager)
            ->getJson('/api/v1/hr/time-clock/week?on=2026-09-22')
            ->assertOk()
            ->assertJsonPath('data.correction_requests.0.id', $id);

        $viewer = $this->userWithRole($alpha, 'Viewer', ['work_hours.general.view', 'work_hours.all'], 'viewer@alpha.test');

        $this->signedInAs($viewer)
            ->getJson('/api/v1/hr/time-clock/week?on=2026-09-22')
            ->assertOk()
            ->assertJsonMissingPath('data.correction_requests');
    }

    private function ask(User $user, TimeClockShift $shift): string
    {
        return $this->signedInAs($user)
            ->postJson('/api/v1/time-clock/corrections', [
                'shift_id' => $shift->id,
                'clocked_in_at' => '2026-09-22T12:00:00Z',
                'clocked_out_at' => '2026-09-22T21:00:00Z',
                'reason' => 'Forgot to clock in.',
            ])
            ->assertCreated()
            ->json('data.id');
    }

    private function bell(User $user): int
    {
        return Notification::query()->withoutGlobalScopes()->where('user_id', $user->id)->count();
    }

    /** Same shape as TimeClockCorrectionTest's. */
    private function tenant(): array
    {
        $alpha = $this->provisionTenant('Alpha');
        app(TenantContext::class)->run($alpha['org'], fn () => app(SettingsResolver::class)
            ->set('time_clock.enabled', $alpha['org'], true));

        return [
            $alpha,
            $this->userWithRole($alpha, 'Hr', ['work_hours.general.view', 'work_hours.all', 'work_hours.manage'], 'hr@alpha.test'),
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
