<?php

namespace Tests\Feature\Fleet;

use App\Models\Vehicle;
use App\Models\VehicleInsurancePolicy;
use App\Models\VehiclePayment;
use App\Support\Time\ViewTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Fleet's General tab (Mia, 2026-10-02): what is on the road, what needs
 * attention, and what it cost - the last only with financials.
 */
class FleetSummaryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_counts_the_register_and_names_what_needs_attention(): void
    {
        // Pinned INSIDE the window that used to fail every night: 01:30 UTC is
        // still the previous evening in the company's zone. The payment was
        // dated by the UTC clock, so between 00:00 and 04:00 UTC it landed the
        // day after the month's end in the company's zone and the total read
        // 0 (gate, 0.0.0364). The summary was right: a payment's day is the
        // company's calendar day.
        $this->travelTo(Carbon::parse('2026-10-15 01:30:00', 'UTC'));

        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $today = Carbon::now(ViewTimezone::forUser($t['admin']))->toDateString();

            // Control: if the company's zone were UTC this test would no
            // longer sit on the edge it exists to hold, and would say so.
            $this->assertNotSame(
                now('UTC')->toDateString(),
                $today,
                'the pinned instant must fall on different days in UTC and the company zone',
            );

            $overdue = Vehicle::factory()->create([
                'company_id' => $t['company']->id, 'status' => 'active',
                'next_service_on' => now()->subDays(3)->toDateString(),
            ]);
            Vehicle::factory()->create(['company_id' => $t['company']->id, 'status' => 'out_of_service']);
            Vehicle::factory()->create(['company_id' => $t['company']->id, 'status' => 'sold']);

            VehicleInsurancePolicy::create([
                'organization_id' => $t['org']->id, 'vehicle_id' => $overdue->id,
                'carrier' => 'Acme', 'started_on' => now()->subYear()->toDateString(),
                'expires_on' => now()->addDays(10)->toDateString(),
            ]);

            VehiclePayment::create([
                'organization_id' => $t['org']->id, 'vehicle_id' => $overdue->id,
                'category' => 'maintenance', 'amount_cents' => 12500,
                'occurred_on' => $today, 'method' => 'credit_card',
            ]);
        });

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/vehicles/summary?period=month')
            ->assertOk()
            ->json();

        // Sold is out of the register.
        $this->assertSame(2, $body['health']['vehicles']);
        $this->assertSame(1, $body['health']['out_of_service']);

        $warnings = collect($body['warnings'])->keyBy('key');
        $this->assertSame(1, $warnings['service_overdue']['count']);
        $this->assertSame(1, $warnings['insurance_expiring']['count']);
        $this->assertSame(1, $warnings['no_insurance']['count']);
        $this->assertSame('service_overdue', $body['warnings'][0]['key']);
        $this->assertArrayNotHasKey('service_due_soon', $warnings->all(), 'overdue is not also counted as due soon');

        $this->assertSame(12500, $body['spending']['total_minor']);
        $this->assertSame('maintenance', $body['spending']['categories'][0]['key']);
        $this->assertSame(12500, array_sum($body['spending']['points']));
    }

    public function test_without_financials_there_is_no_spending_at_all(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], fn () => Vehicle::factory()->create(['company_id' => $t['company']->id]));

        $viewer = $this->userWithRole($t, 'Viewer', ['fleet_management.view', 'fleet_management.general.view']);

        $body = $this->signedInAs($viewer)->getJson('/api/v1/vehicles/summary')->assertOk()->json();

        $this->assertArrayNotHasKey('spending', $body);
        $this->assertFalse($body['health']['empty']);
    }

    public function test_an_empty_register_says_so(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/vehicles/summary')
            ->assertOk()
            ->assertJsonPath('health.empty', true)
            ->assertJsonPath('health.sentence', 'No vehicles to show.');
    }
}
