<?php

namespace Tests\Feature\Phone;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\PhoneConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-11: Communication › Users for EverDoors listed A-1 Chimney's staff. */
class PhonePeopleInViewTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_company_view_lists_only_people_who_work_there(): void
    {
        $t = $this->provisionTenant();
        $sibling = $this->inTenant($t['org'], function () use ($t) {
            $company = Company::create(['organization_id' => $t['org']->id, 'name' => 'Sibling']);
            PhoneConnection::factory()->create(['scope_type' => PhoneConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id]);

            return Franchise::create(['organization_id' => $t['org']->id, 'company_id' => $company->id, 'name' => 'Sibling F1']);
        });
        $here = $this->userWithRole($t, 'Works Here', ['phone.view'], onlyAt: $t['franchise']);
        $there = $this->userWithRole($t, 'Works There', ['phone.view'], onlyAt: $sibling);

        foreach (['/api/v1/phone/extensions' => 'name', '/api/v1/phone/members' => 'people.*.name'] as $url => $path) {
            $inCompany = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'company:'.$t['company']->id)
                ->getJson($url)->assertOk();
            $names = $url === '/api/v1/phone/extensions' ? collect($inCompany->json('data'))->pluck('name')->all() : collect($inCompany->json('data.people'))->pluck('name')->all();
            $this->assertContains('Works Here', $names, $url);
            $this->assertNotContains('Works There', $names, $url);

            // The organization view still lists everybody.
            $all = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')->getJson($url)->assertOk();
            $names = $url === '/api/v1/phone/extensions' ? collect($all->json('data'))->pluck('name')->all() : collect($all->json('data.people'))->pluck('name')->all();
            $this->assertContains('Works There', $names, $url);
        }
    }
}
