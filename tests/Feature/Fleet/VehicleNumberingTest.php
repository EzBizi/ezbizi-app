<?php

namespace Tests\Feature\Fleet;

use App\Models\Company;
use App\Support\Numbering\RecordNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class VehicleNumberingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_pool_vehicles_take_a_v_prefix_and_share_one_org_sequence(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $numbers = app(RecordNumberGenerator::class);

            $first = $numbers->next(RecordNumberGenerator::VEHICLES, $tenant['company']);
            $second = $numbers->next(RecordNumberGenerator::VEHICLES, $tenant['company']);

            $this->assertStringStartsWith('V-', $first);
            $this->assertNotSame($first, $second);

            $otherCompany = Company::create([
                'organization_id' => $tenant['org']->id,
                'name' => 'Alpha Pool Co',
            ]);

            $fromOtherCompany = $numbers->next(RecordNumberGenerator::VEHICLES, $otherCompany);

            // Format may come from the company; the counter is organization-wide,
            // so a second company must not restart at V-00001.
            $this->assertNotSame('V-00001', $fromOtherCompany);
            $this->assertSame(['V-00001', 'V-00002', 'V-00003'], [$first, $second, $fromOtherCompany]);
        });
    }

    // Audit 2026-11-27: the year is the business's, not UTC's.
    public function test_the_year_is_the_scopes_local_year(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $settings = app(\App\Support\Settings\SettingsResolver::class);
            $settings->set('numbering.include_year', $tenant['org'], true);
            $settings->set('timezone', $tenant['org'], 'America/New_York');

            // 03:00 UTC on Jan 1 is still Dec 31 in New York.
            $this->travelTo(\Illuminate\Support\Carbon::parse('2027-01-01 03:00:00', 'UTC'));

            $number = app(RecordNumberGenerator::class)->next(RecordNumberGenerator::VEHICLES, $tenant['company']);

            $this->assertStringStartsWith('V-2026-', $number);
        });
    }
}
