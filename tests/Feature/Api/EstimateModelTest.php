<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Estimate;
use App\Support\Numbering\RecordNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** An estimate is numbered from its own series and totals its own lines (spec 2026-09-29 §4). */
class EstimateModelTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_an_estimate_is_numbered_from_its_own_series_and_totals_its_lines(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $estimate = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-1', 'name' => 'A Customer']);
            $number = app(RecordNumberGenerator::class)->next(RecordNumberGenerator::ESTIMATES, $alpha['franchise']);
            $e = Estimate::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => $number, 'status' => 'draft', 'currency' => 'USD']);
            $e->lines()->create(['name' => 'Sweep', 'quantity' => 2, 'unit_price_minor' => 15000, 'position' => 0]);
            $e->lines()->create(['name' => 'Cap', 'quantity' => 1, 'unit_price_minor' => 8950, 'position' => 1]);
            $e->recomputeTotal();

            return $e->fresh();
        });

        $this->assertNotSame('', $estimate->number);
        $this->assertSame(38950, $estimate->total_minor);
    }

    /** A fractional quantity rounds per line, the way an invoice prints it. */
    public function test_a_fractional_quantity_rounds_per_line(): void
    {
        $alpha = $this->provisionTenant('Alpha');

        $total = $this->inTenant($alpha['org'], function () use ($alpha) {
            $customer = Customer::create(['owning_franchise_id' => $alpha['franchise']->id,
                'number' => 'C-1', 'name' => 'A Customer']);
            $e = Estimate::create(['franchise_id' => $alpha['franchise']->id, 'customer_id' => $customer->id,
                'number' => 'E-1', 'status' => 'draft', 'currency' => 'USD']);
            $e->lines()->create(['name' => 'Liner', 'quantity' => 1.5, 'unit_price_minor' => 333, 'position' => 0]);
            $e->lines()->create(['name' => 'Liner', 'quantity' => 1.5, 'unit_price_minor' => 333, 'position' => 1]);
            $e->recomputeTotal();

            return $e->fresh()->total_minor;
        });

        // 1.5 x 333 = 499.5 -> 500 per line, 1000; rounding the sum once would give 999.
        $this->assertSame(1000, $total);
    }
}
