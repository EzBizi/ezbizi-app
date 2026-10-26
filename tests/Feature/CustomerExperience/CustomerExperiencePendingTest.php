<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\WebSite;
use App\Models\WebValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Settings > Pending, Customer Experience's two gaps (desk, 2026-10-26). */
class CustomerExperiencePendingTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function keysFor(string $placeName): array
    {
        $group = collect($this->getJson('/api/v1/settings/pending')->assertOk()->json('data'))
            ->firstWhere('place.name', $placeName);

        return collect($group['items'] ?? [])->pluck('key')->all();
    }

    public function test_a_branch_with_no_review_link_is_a_gap_until_its_site_has_one(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin']);
        $branch = $t['franchise']->name;

        $this->assertContains('branch.review_link', $this->keysFor($branch));

        $site = $this->postJson('/api/v1/web/sites', ['company_id' => $t['company']->id, 'host' => 'alpha.test'])
            ->assertCreated()->json('data.id');
        $this->inTenant($t['org'], fn () => WebValue::create(['site_id' => $site, 'path' => '', 'key' => 'review_link',
            'value' => 'https://alpha.test/reviews/']));

        $this->assertNotContains('branch.review_link', $this->keysFor($branch));
    }

    public function test_a_listing_with_no_branch_is_a_gap_at_the_organization_until_assigned(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin']);
        $id = $this->inTenant($t['org'], fn () => BusinessLocation::create(['organization_id' => $t['org']->id,
            'company_id' => $t['company']->id, 'remote_name' => 'locations/1', 'title' => 'North'])->id);

        $this->assertContains('business_profile.unassigned', $this->keysFor('Alpha'));

        $this->patchJson("/api/v1/business-profile/locations/{$id}", ['franchise_id' => $t['franchise']->id])->assertOk();

        $this->assertNotContains('business_profile.unassigned', $this->keysFor('Alpha'));
    }
}
