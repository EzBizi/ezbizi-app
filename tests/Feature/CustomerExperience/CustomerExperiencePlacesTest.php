<?php

namespace Tests\Feature\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\Complaint;
use App\Models\Franchise;
use App\Models\Review;
use App\Models\ServiceArea;
use App\Models\ServiceAreaZip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Jose, 2026-10-22: "Customer Experience entire tab is also not respecting org -
 * company - franchise ownership". A listing sits at its branch, its reviews and
 * complaints sit there with it, and every list is cut to the place in view.
 */
class CustomerExperiencePlacesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private Franchise $north;

    private Franchise $south;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        $this->north = $this->t['franchise'];
        $this->inTenant($this->t['org'], function () {
            $this->south = Franchise::create(['organization_id' => $this->t['org']->id, 'company_id' => $this->north->company_id,
                'name' => 'South', 'is_active' => true]);
            foreach ([[$this->north, '10001'], [$this->south, '20002']] as [$f, $zip]) {
                $area = ServiceArea::create(['organization_id' => $this->t['org']->id, 'franchise_id' => $f->id, 'name' => $f->name]);
                ServiceAreaZip::create(['organization_id' => $this->t['org']->id, 'franchise_id' => $f->id,
                    'service_area_id' => $area->id, 'zip' => $zip]);
            }
            // Two listings filed at the company, as a company-wide connection files them.
            foreach ([['1', '10001-1234', 'North St'], ['2', '20002', 'South St'], ['3', null, 'Nowhere']] as [$n, $zip, $title]) {
                BusinessLocation::create(['organization_id' => $this->t['org']->id, 'company_id' => $this->north->company_id,
                    'remote_name' => "locations/{$n}", 'title' => $title, 'postal_code' => $zip]);
                $review = Review::create(['organization_id' => $this->t['org']->id, 'company_id' => $this->north->company_id,
                    'remote_name' => "accounts/9/locations/{$n}/reviews/r{$n}", 'location_name' => "accounts/9/locations/{$n}",
                    'reviewer_name' => "Reviewer {$n}", 'rating' => 1, 'reviewed_at' => now()]);
                Complaint::create(['organization_id' => $this->t['org']->id, 'company_id' => $this->north->company_id,
                    'kind' => 'bad_review', 'source_type' => 'review', 'source_id' => $review->id, 'status' => 'new',
                    'rating' => 1, 'raised_at' => now()]);
            }
        });
    }

    private function names(User $user, string $url, string $key): array
    {
        return collect($this->signedInAs($user)->getJson($url)->assertOk()->json('data'))->pluck($key)->sort()->values()->all();
    }

    public function test_the_backfill_files_listings_by_zip_and_their_reviews_and_complaints_follow(): void
    {
        $this->artisan('ezbizi:cx-places', ['organization' => $this->t['org']->id, '--dry-run' => true])->assertSuccessful();
        $this->assertNull(BusinessLocation::withoutGlobalScopes()->where('title', 'North St')->value('franchise_id'), 'dry run');

        $this->artisan('ezbizi:cx-places', ['organization' => $this->t['org']->id])->assertSuccessful();

        $this->assertSame($this->north->id, BusinessLocation::withoutGlobalScopes()->where('title', 'North St')->value('franchise_id'));
        $this->assertSame($this->south->id, BusinessLocation::withoutGlobalScopes()->where('title', 'South St')->value('franchise_id'));
        $this->assertNull(BusinessLocation::withoutGlobalScopes()->where('title', 'Nowhere')->value('franchise_id'), 'no ZIP, left for a person');
        $this->assertSame($this->south->id, Review::withoutGlobalScopes()->where('reviewer_name', 'Reviewer 2')->value('franchise_id'));
        $this->assertSame(2, Complaint::withoutGlobalScopes()->whereNotNull('franchise_id')->count());
    }

    /**
     * A branch view is that branch (the house rule: nobody is counted at the company
     * above it) - never a sibling's, and not a listing still waiting for a branch.
     */
    public function test_every_list_is_cut_to_the_branch_in_view(): void
    {
        $this->artisan('ezbizi:cx-places', ['organization' => $this->t['org']->id])->assertSuccessful();
        $north = $this->userWithRole($this->t, 'North', ['reviews.view', 'business_profile.view',
            'customer_experience.complaints.view'], null, $this->north);

        $this->assertSame(['Reviewer 1'], $this->names($north, '/api/v1/reviews', 'reviewer_name'));
        $this->assertSame(['North St'], $this->names($north, '/api/v1/business-profile/locations', 'title'));
        $this->assertSame(['Reviewer 1'], $this->names($north, '/api/v1/complaints?status=all', 'title'));
        $this->assertSame(['North St'], collect($this->signedInAs($north)->getJson('/api/v1/business-profile/performance')
            ->assertOk()->json('data.by_location'))->pluck('name')->sort()->values()->all());

        // The control: the organization sees all three.
        $this->assertCount(3, $this->names($this->t['admin'], '/api/v1/reviews', 'reviewer_name'));

        // The VIEW, not only reach: an administrator looking at the South branch
        // reaches everything and is shown South's (the case reach alone cannot cut).
        foreach (['/api/v1/reviews' => 'reviewer_name', '/api/v1/complaints?status=all' => 'title',
            '/api/v1/business-profile/locations' => 'title'] as $url => $key) {
            $rows = collect($this->signedInAs($this->t['admin'])->withHeader('X-EzBizi-View', 'franchise:'.$this->south->id)
                ->getJson($url)->assertOk()->json('data'))->pluck($key)->all();
            $this->assertSame([$key === 'title' && str_contains($url, 'locations') ? 'South St' : 'Reviewer 2'], $rows, $url);
        }
    }

    /** A person assigning a listing a branch moves its reviews and complaints with it. */
    public function test_assigning_a_listing_files_its_reviews_there(): void
    {
        $id = BusinessLocation::withoutGlobalScopes()->where('title', 'Nowhere')->value('id');
        $this->signedInAs($this->t['admin'])->patchJson("/api/v1/business-profile/locations/{$id}", ['franchise_id' => $this->south->id])->assertOk();

        $this->assertSame($this->south->id, Review::withoutGlobalScopes()->where('reviewer_name', 'Reviewer 3')->value('franchise_id'));
        $this->assertSame(1, Complaint::withoutGlobalScopes()->where('franchise_id', $this->south->id)->count());
    }
}
