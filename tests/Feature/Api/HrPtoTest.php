<?php

namespace Tests\Feature\Api;

use App\Models\EmploymentEvent;
use App\Support\Settings\SettingsResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /hr/pto — "how much paid pto we all have and when it can be used".
 *
 * Toula Elwell's suggestion (A1-Group, 2026-09-08), approved by Jose
 * 2026-09-17. The permission split here is the part worth testing hardest:
 * YOUR OWN balance is not an HR permission, and `hr.view` is what adds
 * everybody else's. Gating the whole thing would have answered her question
 * only for the managers, which is the half she could already get by asking one.
 */
class HrPtoTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function policy(array $tenant, array $values): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $values) {
            foreach ($values as $key => $value) {
                app(SettingsResolver::class)->set($key, $tenant['org'], $value);
            }
        });
    }

    private function started(array $tenant, string $userId, string $on): void
    {
        $this->inTenant($tenant['org'], fn () => EmploymentEvent::create([
            'organization_id' => $tenant['org']->id,
            'user_id' => $userId,
            'kind' => EmploymentEvent::STARTED,
            'occurred_on' => $on,
        ]));
    }

    /**
     * Somebody with no HR permission at all still gets their own days.
     *
     * The case that distinguishes: if this were gated like the rest of the HR
     * group, a technician would open the screen that exists to tell them their
     * own leave and be refused. Her suggestion came from an office employee,
     * not a manager.
     */
    public function test_a_person_with_no_hr_permission_sees_their_own_row(): void
    {
        Carbon::setTestNow('2026-09-17 12:00:00');

        $t = $this->provisionTenant('Alpha');
        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 90]);

        $technician = $this->userWithRole($t, 'Technician', ['customers.view']);
        $this->started($t, $technician->getKey(), '2026-01-06');

        $body = $this->signedInAs($technician)
            ->getJson('/api/v1/hr/pto')
            ->assertOk()
            ->json();

        $this->assertSame($technician->getKey(), $body['me']);
        $this->assertCount(1, $body['data'], 'They were shown somebody else, or nobody.');
        $this->assertSame(6, $body['data'][0]['allowance']);
        $this->assertSame('2026-04-06', $body['data'][0]['eligible_from']);
        $this->assertTrue($body['data'][0]['eligible']);
    }

    /**
     * And the same person is shown ONLY themselves.
     *
     * The control for the test above: without it, a response listing the whole
     * organization would also satisfy "they see their own row".
     */
    public function test_that_person_is_not_shown_anybody_else(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6]);

        $technician = $this->userWithRole($t, 'Technician', ['customers.view']);

        $names = collect(
            $this->signedInAs($technician)->getJson('/api/v1/hr/pto')->assertOk()->json('data')
        )->pluck('name');

        $this->assertSame([$technician->name], $names->all());
        $this->assertNotContains($t['admin']->name, $names->all());
    }

    /** Somebody who keeps the people list sees the people they can reach. */
    public function test_hr_view_adds_everybody_in_reach(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6]);

        $this->userWithRole($t, 'Technician', ['customers.view']);

        $names = collect(
            $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json('data')
        )->pluck('name');

        $this->assertContains('Technician', $names->all());
        $this->assertContains($t['admin']->name, $names->all());
    }

    /**
     * The organization's state is sent once, not repeated on every row.
     *
     * "This company has not set up paid time off" is a state of the whole
     * screen — the settings are organization-scoped, so it can never be one
     * person's state beside somebody else's.
     */
    public function test_the_policy_block_carries_the_whole_organizations_answer(): void
    {
        $t = $this->provisionTenant('Alpha');

        $body = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json();

        $this->assertFalse($body['policy']['enabled']);
        $this->assertSame(0, $body['policy']['allowance']);

        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 90]);

        $body = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json();

        $this->assertTrue($body['policy']['enabled']);
        $this->assertSame(6, $body['policy']['allowance']);
        $this->assertSame(90, $body['policy']['waiting']);
    }

    /**
     * Both numbers carry their unit to the screen, unconverted.
     *
     * Without the unit the number is not an answer: "1" is a day a month or a
     * day a year, and "3" is three days or three months. The screen cannot
     * work either out, and a default of days-and-years would be right for most
     * companies and silently wrong for the ones that accrue.
     */
    public function test_the_units_travel_with_the_numbers(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->policy($t, [
            'pto.enabled' => true,
            'pto.allowance' => 1,
            'pto.allowance_period' => 'month',
            'pto.waiting' => 3,
            'pto.waiting_unit' => 'month',
            'pto.accrue_after' => 3,
            'pto.accrue_after_unit' => 'month',
        ]);

        $body = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json();

        $this->assertSame('month', $body['policy']['allowance_period']);
        $this->assertSame('month', $body['policy']['waiting_unit']);
        $this->assertSame(1, $body['policy']['allowance'], 'Twelve would be the server annualising it.');
        $this->assertSame(3, $body['policy']['waiting'], 'Ninety would be the server converting months to days.');
        $this->assertSame(3, $body['policy']['accrue_after']);
        $this->assertSame('month', $body['policy']['accrue_after_unit']);
    }

    /** The policy block carries the defaults when nothing was chosen. */
    public function test_the_units_default_to_days_and_years(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6]);

        $body = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json();

        $this->assertSame('year', $body['policy']['allowance_period']);
        $this->assertSame('day', $body['policy']['waiting_unit']);
        $this->assertSame(0, $body['policy']['accrue_after']);
        $this->assertSame('day', $body['policy']['accrue_after_unit']);
    }

    /**
     * No start date is null, not a date, and not eligible.
     *
     * Everybody on the books before employment_events has no start row. The
     * screen says it cannot tell rather than counting from something invented.
     */
    public function test_somebody_with_no_start_date_gets_nulls_rather_than_a_guess(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6, 'pto.waiting' => 90]);

        $row = collect(
            $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json('data')
        )->firstWhere('id', $t['admin']->getKey());

        $this->assertNull($row['started_on']);
        $this->assertNull($row['eligible_from']);
        $this->assertFalse($row['eligible']);
    }

    /**
     * `taken` is null rather than zero, and that is not pedantry.
     *
     * Nothing in EzBizi records a day off yet. Zero would claim they have
     * taken none; null says we do not keep it, which is what the em dash in
     * the drawn column means.
     */
    public function test_taken_is_unknown_rather_than_zero(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->policy($t, ['pto.enabled' => true, 'pto.allowance' => 6]);

        $row = $this->signedInAs($t['admin'])->getJson('/api/v1/hr/pto')->assertOk()->json('data.0');

        $this->assertArrayHasKey('taken', $row);
        $this->assertNull($row['taken']);
    }
}
