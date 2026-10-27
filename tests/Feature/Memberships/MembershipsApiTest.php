<?php

namespace Tests\Feature\Memberships;

use App\Models\AutomationEvent;
use App\Models\Customer;
use App\Models\Membership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Customers › Memberships (spec 2026-10-05): plans, members, the yearly clock, the reminder. */
class MembershipsApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $alpha;
    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->provisionTenant('Alpha');
        $this->customer = $this->aCustomer($this->alpha['franchise']->id);
    }

    private function plan(string $billing = 'yearly', int $price = 24000): string
    {
        return $this->signedInAs($this->alpha['admin'])->postJson('/api/v1/memberships/plans', [
            'name' => "Plan {$billing} {$price}", 'price_minor' => $price, 'billing' => $billing,
        ])->assertCreated()->json('data.id');
    }

    private function join(string $plan, array $extra = []): array
    {
        return $this->signedInAs($this->alpha['admin'])->postJson('/api/v1/memberships', [
            'customer_id' => $this->customer, 'membership_plan_id' => $plan, ...$extra,
        ])->assertCreated()->json('data');
    }

    public function test_joining_starts_a_one_year_clock_and_raises_member_joined(): void
    {
        $data = $this->join($this->plan(), ['starts_on' => '2026-10-05']);

        $this->assertSame('2026-10-05', $data['starts_on']);
        $this->assertSame('2027-10-04', $data['ends_on']);
        $this->assertSame('new', $data['enrollment']);
        $event = AutomationEvent::withoutGlobalScopes()->where('event_key', 'membership.created')->sole();
        $this->assertSame('A Customer', $event->payload['after']['customer_name']);
        $this->assertSame('October 4, 2027', $event->payload['after']['ends_on_text']);
    }

    public function test_a_second_year_is_a_renewal_and_the_list_shows_the_latest(): void
    {
        $plan = $this->plan();
        $this->join($plan, ['starts_on' => '2025-10-05']);
        $this->join($plan, ['starts_on' => '2026-10-05']);

        $rows = $this->signedInAs($this->alpha['admin'])->getJson('/api/v1/memberships')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('renewal', $rows[0]['enrollment']);
        $this->assertArrayHasKey('customer_phone', $rows[0]);
        $this->assertArrayHasKey('customer_email', $rows[0]);

        $years = $this->signedInAs($this->alpha['admin'])->getJson('/api/v1/memberships?customer_id='.$this->customer)->json('data');
        $this->assertSame(['2026-10-05', '2025-10-05'], array_column($years, 'starts_on'), 'One customer: every year, newest first.');
    }

    public function test_due_paid_and_estimated_gross(): void
    {
        $starts = CarbonImmutable::today()->subMonths(2)->subDays(10)->toDateString();
        $this->join($this->plan('monthly', 12000), ['starts_on' => $starts, 'paid_minor' => 1000]);

        $row = $this->signedInAs($this->alpha['admin'])->getJson('/api/v1/memberships')->json('data.0');
        // Three months begun at 1,000 each, 1,000 paid.
        $this->assertSame(2000, $row['due_minor']);
        $this->assertSame(1000, $row['paid_minor']);
        $this->assertSame(12000, $row['estimated_gross_minor'], 'No job amounts: the plan alone.');

        $yearly = $this->join($this->plan('yearly', 30000));
        $this->assertSame(30000, $yearly['due_minor']);
    }

    public function test_amounts_are_null_without_financials(): void
    {
        $this->join($this->plan());
        $clerk = $this->userWithRole($this->alpha, 'Clerk', ['customers.view', 'memberships.view', 'memberships.members.view']);

        $row = $this->signedInAs($clerk)->getJson('/api/v1/memberships')->assertOk()->json('data.0');
        $this->assertNull($row['due_minor']);
        $this->assertNotNull($row['plan_name']);
    }

    public function test_members_tab_permission_is_required(): void
    {
        $clerk = $this->userWithRole($this->alpha, 'Clerk', ['customers.view', 'memberships.view']);
        $this->signedInAs($clerk)->getJson('/api/v1/memberships')->assertForbidden();
        $this->signedInAs($clerk)->postJson('/api/v1/memberships/plans', ['name' => 'X', 'price_minor' => 1, 'billing' => 'yearly'])->assertForbidden();
    }

    public function test_the_reminder_is_raised_once_a_month_before_the_end(): void
    {
        $plan = $this->plan();
        // Ends six weeks from today.
        $this->join($plan, ['starts_on' => CarbonImmutable::today()->subYear()->addWeeks(6)->toDateString()]);

        $count = fn () => AutomationEvent::withoutGlobalScopes()->where('event_key', 'membership.renewal_due')->count();

        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->assertSame(0, $count(), 'Six weeks out: not yet.');

        CarbonImmutable::setTestNow(CarbonImmutable::today()->addWeeks(3));
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->artisan('memberships:remind-renewals')->assertSuccessful();
        $this->assertSame(1, $count());
        $this->assertNotNull(Membership::withoutGlobalScopes()->first()->reminded_at);
        CarbonImmutable::setTestNow();
    }

    public function test_a_plan_names_its_workiz_job_type(): void
    {
        $id = $this->plan();
        $this->signedInAs($this->alpha['admin'])->patchJson("/api/v1/memberships/plans/{$id}", ['workiz_job_type' => 'Chimney Sweep'])
            ->assertOk()->assertJsonPath('data.job_type', 'Chimney Sweep')->assertJsonPath('data.workiz_job_type', 'Chimney Sweep');
        $this->signedInAs($this->alpha['admin'])->patchJson("/api/v1/memberships/plans/{$id}", ['job_type' => 'Inspection'])
            ->assertOk()->assertJsonPath('data.job_type', 'Inspection');
    }

    private function aCustomer(string $franchiseId): string
    {
        return $this->inTenant($this->alpha['org'], fn () => Customer::create([
            'owning_franchise_id' => $franchiseId,
            'number' => 'C-'.bin2hex(random_bytes(3)),
            'name' => 'A Customer',
        ])->id);
    }
}
