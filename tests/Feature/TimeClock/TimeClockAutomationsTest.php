<?php

namespace Tests\Feature\TimeClock;

use App\Models\AutomationEvent;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Models\User;
use App\Support\Automations\ActionRunner;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A rule may punch the clock (§5.5), added 2026-08-28 on the owner's ruling
 * that what an organization builds automations for is its own affair.
 *
 * The case that matters most here is not that it works — it is that a punch
 * made by a rule carries the causation chain. TimeClock raises its own trigger
 * on every punch, so "when somebody clocks in, clock them out" is a rule a
 * tenant can write in about nine seconds. Without the chain, the clocked_out
 * that action raises starts a fresh root at depth 0, re-enters the engine
 * looking person-initiated, and ping-pongs past both the depth cap and the
 * cascade budget.
 */
class TimeClockAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_closes_the_open_shift_of_the_person_it_is_about(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->enableClockIn($t['org']);
        $puncher = $this->userWithRole($t, 'Tech', ['time_clock.view']);

        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $result = $this->runAction('time_clock.clock_out', $puncher);

        $this->assertTrue($result['ok'], $result['detail']);

        $this->inTenant($t['org'], function () use ($puncher) {
            $shift = TimeClockShift::query()->where('user_id', $puncher->getKey())->first();
            $this->assertNotNull($shift->clocked_out_at, 'the rule should have closed the shift');
        });
    }

    /**
     * The one that stops A→B→A.
     *
     * Break it by dropping `causedBy:` from the emit in TimeClock::clockOut and
     * this goes red on depth — which is the whole reason the parameter exists.
     */
    public function test_a_punch_made_by_a_rule_carries_the_causation_chain(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->enableClockIn($t['org']);
        $puncher = $this->userWithRole($t, 'Tech', ['time_clock.view']);

        $this->signedInAs($puncher)->postJson('/api/v1/time-clock/clock-in')->assertOk();

        $cause = $this->inTenant($t['org'], fn () => AutomationEvent::query()
            ->where('event_key', 'time_clock.clocked_in')
            ->firstOrFail());

        $this->assertSame(0, (int) $cause->depth, 'a person’s own punch is the root');

        $this->runAction('time_clock.clock_out', $puncher, $cause);

        $this->inTenant($t['org'], function () use ($cause) {
            $raised = AutomationEvent::query()
                ->where('event_key', 'time_clock.clocked_out')
                ->firstOrFail();

            $this->assertSame(
                1,
                (int) $raised->depth,
                'a rule’s punch must be one level deeper than the event that caused it, '
                .'or the depth cap never counts it',
            );
            $this->assertSame(
                $cause->getKey(),
                $raised->causation_id,
                'the clocked_out has to name the event that caused it',
            );
            $this->assertNotNull(
                $raised->root_event_id,
                'without a root the cascade budget has nothing to bill against',
            );
        });
    }

    public function test_the_organization_switch_refuses_a_rule_the_same_way_it_refuses_a_person(): void
    {
        $t = $this->provisionTenant('Alpha');
        // Deliberately NOT enabled — the rule must inherit the same refusal the
        // button gets, rather than a rule being a way around the setting.
        $puncher = $this->userWithRole($t, 'Tech', ['time_clock.view']);

        $result = $this->runAction('time_clock.clock_in', $puncher);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('switched off', $result['detail']);

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, TimeClockShift::query()->count());
        });
    }

    public function test_a_subject_that_is_not_a_person_is_refused_rather_than_guessed_at(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->enableClockIn($t['org']);
        $franchise = $t['franchise'];

        $result = $this->inTenant($t['org'], function () use ($franchise) {
            $event = AutomationEvent::create([
                'event_key' => 'franchise.created',
                'subject_type' => Franchise::class,
                'subject_id' => $franchise->id,
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(['type' => 'time_clock.clock_out'], $event, $franchise);
        });

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not a person', $result['detail']);
    }

    /** @return array{type: string, ok: bool, detail: string} */
    private function runAction(string $type, User $subject, ?AutomationEvent $cause = null): array
    {
        return $this->inTenant($subject->organization, function () use ($type, $subject, $cause) {
            $event = $cause ?? AutomationEvent::create([
                'event_key' => 'user.activated',
                'subject_type' => User::class,
                'subject_id' => $subject->getKey(),
                'payload' => [],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);

            return app(ActionRunner::class)->run(['type' => $type], $event, $subject);
        });
    }

    private function enableClockIn(Organization $org): void
    {
        app(TenantContext::class)->run($org, function () use ($org) {
            app(SettingsResolver::class)->set('time_clock.enabled', $org, true);
        });
    }
}
