<?php

namespace Tests\Feature\Fleet;

use App\Support\Fleet\VehicleFinancials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Money leaves the payload unless the caller holds fleet_management.financials.
 */
class VehicleFinancialsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * Every `_cents` column on a fleet table must be a financial key.
     *
     * The hand-written list below it drifts in ONE direction only: it goes red
     * when a key is removed from keys(), and stays green forever when a money
     * column is ADDED to the schema and nobody adds it to keys(). That is the
     * silent direction, and it had already happened — `toll_flat_rate_cents`
     * shipped on 2026-08-28 and never reached that list. It is stripped today
     * because the person who added the column remembered, which is not a
     * guarantee, and the test claiming to cover "every money column" could not
     * see the one it was missing.
     *
     * Derived from the schema so the next one cannot be forgotten quietly.
     */
    public function test_every_cents_column_on_a_fleet_table_is_a_financial_key(): void
    {
        $keys = VehicleFinancials::keys();

        $money = [];
        foreach ([
            'vehicles',
            'vehicle_ownership_periods',
            'vehicle_insurance_policies',
            'vehicle_assignments',
            'vehicle_service_visits',
            'vehicle_payments',
        ] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                if (str_ends_with($column, '_cents')) {
                    $money[$column] = $table;
                }
            }
        }

        // Control: a derivation that found nothing would pass every assertion
        // below it. purchase_price_cents has been there since the module
        // shipped, so its absence means the reading broke, not the schema.
        $this->assertArrayHasKey(
            'purchase_price_cents',
            $money,
            'schema reading failed — this test proves nothing until it works',
        );

        foreach ($money as $column => $table) {
            $this->assertContains(
                $column,
                $keys,
                "{$table}.{$column} is money and is not stripped without "
                    .'fleet_management.financials — add it to VehicleFinancials::keys()',
            );
        }
    }

    public function test_keys_include_every_money_column_and_related_terms(): void
    {
        $keys = VehicleFinancials::keys();

        foreach ([
            'purchase_price_cents',
            'loan_monthly_payment_cents',
            'rental_rate_cents',
            'rental_deposit_cents',
            'transport_cost_cents',
            'premium_cents',
            'amount_cents',
            'lienholder',
            'loan_account',
            'loan_payoff_date',
            'rental_rate_period',
            'rental_account_number',
            'rental_payment_terms',
            'rental_payment_method',
            'premium_period',
        ] as $key) {
            $this->assertContains($key, $keys, "missing financial key: {$key}");
        }
    }

    public function test_strip_removes_cents_fields_and_payments_without_financials(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['fleet_management.view']);
        $money = $this->userWithRole($tenant, 'Money', [
            'fleet_management.view',
            'fleet_management.financials',
        ]);

        $payload = [
            'id' => 'veh-1',
            'plate' => 'ABC-123',
            'purchase_price_cents' => 2500000,
            'lienholder' => 'First National',
            'premium_cents' => 12000,
            'payments' => [
                ['amount_cents' => 5000],
            ],
            'ownership' => [
                'kind' => 'owned',
                'purchase_price_cents' => 2500000,
                'counterparty' => 'Dealer',
            ],
        ];

        $this->inTenant($tenant['org'], function () use ($viewer, $money, $payload) {
            $stripped = VehicleFinancials::strip($payload, $viewer->fresh());

            $this->assertArrayNotHasKey('purchase_price_cents', $stripped);
            $this->assertArrayNotHasKey('lienholder', $stripped);
            $this->assertArrayNotHasKey('premium_cents', $stripped);
            $this->assertArrayNotHasKey('payments', $stripped);
            $this->assertSame('ABC-123', $stripped['plate']);
            $this->assertSame('Dealer', $stripped['ownership']['counterparty']);
            $this->assertArrayNotHasKey('purchase_price_cents', $stripped['ownership']);

            $kept = VehicleFinancials::strip($payload, $money->fresh());
            $this->assertSame(2500000, $kept['purchase_price_cents']);
            $this->assertArrayHasKey('payments', $kept);
            $this->assertSame(2500000, $kept['ownership']['purchase_price_cents']);
        });
    }
}
