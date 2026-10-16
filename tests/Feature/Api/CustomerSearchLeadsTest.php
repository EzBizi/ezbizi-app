<?php

namespace Tests\Feature\Api;

use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * GET /customers?search= also lists matching leads (Jose, 2026-10-16: a caller
 * who was only an LS Call lead was invisible to the dispatcher's search).
 * LeadSearchTest pins the lead rules; this pins the wiring.
 */
class CustomerSearchLeadsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_search_finds_the_caller_who_is_only_a_lead(): void
    {
        $t = $this->provisionTenant('Alpha', 'a@alpha.test');
        $this->inTenant($t['org'], function () use ($t) {
            Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'source' => Lead::SOURCE_RINGCENTRAL, 'remote_id' => 'r1', 'lead_type' => 'PHONE_CALL',
                'contact_name' => 'James Quinn', 'phone' => '+18582542275', 'phone_digits' => '18582542275',
                'status' => Lead::STATUS_NEW, 'received_at' => now()]);
            Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1', 'name' => 'Someone Else']);
        });

        $response = $this->signedInAs($t['admin'])->getJson('/api/v1/customers?search='.urlencode('(858) 254-2275'))->assertOk();

        $this->assertSame([], $response->json('data'));
        $this->assertSame(['James Quinn'], array_column($response->json('leads'), 'name'));

        // No search, no leads: the plain list is not a lead list.
        $this->assertSame([], $this->signedInAs($t['admin'])->getJson('/api/v1/customers')->assertOk()->json('leads'));
    }
}
