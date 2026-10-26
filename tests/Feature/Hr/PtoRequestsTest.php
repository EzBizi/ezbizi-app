<?php

namespace Tests\Feature\Hr;

use App\Models\AutomationEvent;
use App\Models\EmploymentEvent;
use App\Models\PtoUsed;
use App\Models\User;
use App\Support\Hr\PtoPolicy;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Staff ask for paid time off; whoever may edit Used decides (Jose, 2026-10-26). */
class PtoRequestsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: array, 1: User} */
    private function setUpPolicy(): array
    {
        Carbon::setTestNow('2026-10-21 12:00:00');
        $t = $this->provisionTenant('Alpha');
        $staff = $this->userWithRole($t, 'Technician', ['work_hours.pto.request']);
        $this->inTenant($t['org'], function () use ($t, $staff) {
            foreach (['pto.enabled' => true, 'pto.allowance' => 2, 'pto.allowance_period' => 'month', 'pto.reset' => 'anniversary'] as $k => $v) {
                app(SettingsResolver::class)->set($k, $t['org'], $v);
            }
            EmploymentEvent::create(['organization_id' => $t['org']->id, 'user_id' => $staff->id,
                'kind' => EmploymentEvent::STARTED, 'occurred_on' => '2025-04-01']);
        });

        return [$t, $staff];
    }

    private function events(): array
    {
        return AutomationEvent::withoutGlobalScopes()->pluck('event_key')->all();
    }

    public function test_a_request_is_approved_into_used_days(): void
    {
        [$t, $staff] = $this->setUpPolicy();

        $id = $this->signedInAs($staff)->postJson('/api/v1/hr/pto/requests', [
            'days' => [['date' => '2026-11-02', 'portion' => 1], ['date' => '2026-11-04', 'portion' => 0.5]],
            'note' => 'Family visit',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.would_leave', 10.5)->json('data.id');
        $this->assertContains('user.pto_requested', $this->events());

        // The approver sees it, and may decide it.
        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto/requests?status=pending')->json('data'))
            ->firstWhere('id', $id);
        $this->assertTrue($row['can_decide']);

        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/pto/requests/{$id}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(2, $this->inTenant($t['org'], fn () => PtoUsed::where('user_id', $staff->id)->count()));
        $this->assertSame(10.5, $this->inTenant($t['org'], fn () => app(PtoPolicy::class)->for($staff)['left']));
        $this->assertContains('user.pto_approved', $this->events());
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/pto/requests/{$id}/deny", ['reason' => 'x'])->assertStatus(422);
    }

    public function test_more_than_is_left_is_refused_unless_the_place_allows_negative(): void
    {
        [$t, $staff] = $this->setUpPolicy();
        $days = array_map(fn ($d) => ['date' => sprintf('2026-11-%02d', $d), 'portion' => 1], range(1, 13));

        $this->signedInAs($staff)->postJson('/api/v1/hr/pto/requests', ['days' => $days])->assertStatus(422);

        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('pto.allow_negative', $t['org'], true));
        $this->signedInAs($staff)->postJson('/api/v1/hr/pto/requests', ['days' => $days])->assertCreated();
    }

    public function test_deny_needs_a_reason_and_only_the_asker_cancels(): void
    {
        [$t, $staff] = $this->setUpPolicy();
        $ask = fn (string $date) => $this->signedInAs($staff)->postJson('/api/v1/hr/pto/requests',
            ['days' => [['date' => $date, 'portion' => 1]]])->assertCreated()->json('data.id');

        $first = $ask('2026-11-02');
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/pto/requests/{$first}/deny")->assertStatus(422);
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/pto/requests/{$first}/deny", ['reason' => 'Busy week'])
            ->assertOk()->assertJsonPath('data.reason', 'Busy week');
        $this->assertContains('user.pto_denied', $this->events());

        $second = $ask('2026-11-09');
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/pto/requests/{$second}/cancel")->assertForbidden();
        $this->signedInAs($staff)->postJson("/api/v1/hr/pto/requests/{$second}/cancel")
            ->assertOk()->assertJsonPath('data.status', 'cancelled');
    }

    public function test_without_the_permission_or_deciding_your_own_it_is_refused(): void
    {
        [$t] = $this->setUpPolicy();
        $none = $this->userWithRole($t, 'Visitor', ['jobs.view']);
        $this->signedInAs($none)->postJson('/api/v1/hr/pto/requests', ['days' => [['date' => '2026-11-02', 'portion' => 1]]])
            ->assertForbidden();

        $this->inTenant($t['org'], fn () => EmploymentEvent::create(['organization_id' => $t['org']->id,
            'user_id' => $t['admin']->id, 'kind' => EmploymentEvent::STARTED, 'occurred_on' => '2025-04-01']));
        $own = $this->signedInAs($t['admin'])->postJson('/api/v1/hr/pto/requests',
            ['days' => [['date' => '2026-11-02', 'portion' => 1]]])->assertCreated()->json('data.id');
        $this->signedInAs($t['admin'])->postJson("/api/v1/hr/pto/requests/{$own}/approve")->assertForbidden();
    }
}
