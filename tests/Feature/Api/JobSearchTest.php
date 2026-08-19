<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jobs list search — the screens cannot filter if the list cannot be asked.
 *
 * Title, job number, and customer name/number. Reach still applies: a search
 * does not leak another franchise's job, and it does not leak another
 * organization's. Empty search is the unfiltered list, not "match nothing".
 */
class JobSearchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_search_matches_number_title_and_customer_and_does_not_leak(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta  = $this->provisionTenant('Beta');

        $this->signedInAs($alpha['admin']);

        $alice = $this->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Alice Roofing',
        ])->assertCreated()->json('data');

        $bob = $this->postJson('/api/v1/customers', [
            'owning_franchise_id' => $alpha['franchise']->id,
            'name' => 'Bob Masonry',
        ])->assertCreated()->json('data');

        $crown = $this->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $alice['id'],
            'title'        => 'Crown inspection',
            'status'       => 'scheduled',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/jobs', [
            'franchise_id' => $alpha['franchise']->id,
            'customer_id'  => $bob['id'],
            'title'        => 'Flue sweep',
            'status'       => 'scheduled',
        ])->assertCreated();

        $this->signedInAs($beta['admin']);

        $outsider = $this->postJson('/api/v1/customers', [
            'owning_franchise_id' => $beta['franchise']->id,
            'name' => 'Alice Roofing',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/jobs', [
            'franchise_id' => $beta['franchise']->id,
            'customer_id'  => $outsider,
            'title'        => 'Crown inspection',
            'status'       => 'scheduled',
        ])->assertCreated();

        $this->signedInAs($alpha['admin']);

        $byNumber = $this->getJson('/api/v1/jobs?search='.urlencode($crown['number']))
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byNumber);
        $this->assertSame($crown['id'], $byNumber[0]['id']);

        $byTitle = $this->getJson('/api/v1/jobs?search=flue')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byTitle);
        $this->assertSame('Flue sweep', $byTitle[0]['title']);

        $byCustomer = $this->getJson('/api/v1/jobs?search=Alice')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $byCustomer);
        $this->assertSame($alice['id'], $byCustomer[0]['customer']['id']);

        $this->getJson('/api/v1/jobs?search=')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }
}
