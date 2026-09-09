<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\ReviewsGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Reviews glance, and three sentences that spoke for the whole business.
 *
 * ── Why this file exists ──
 *
 * "No reviews yet." was cited to UI/UX as evidence that the board already
 * wrote the compliant plain form, and the ruling went the wrong way for two
 * hours on the strength of it. It was the defect standing where the proof
 * should have been: a claim about the ORGANIZATION, made by a box scoped to
 * one reader's franchise reach. It had no test, which is how it lasted long
 * enough to be quoted.
 *
 * The one that matters most is not the empty state. "Every review has been
 * answered." is the sentence that tells somebody nothing needs their
 * attention, and an unanswered one-star sits in public at the top of the
 * listing while they believe it.
 */
class ReviewsGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function review(?Franchise $franchise, ?int $rating = 5, bool $answered = false): Review
    {
        static $n = 0;
        $n++;

        return $this->inTenant(function () use ($franchise, $rating, $answered, $n) {
            $review = Review::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'franchise_id'    => $franchise?->id,
                'remote_name'     => "accounts/a/locations/l/reviews/r-{$n}",
                'location_name'   => 'accounts/a/locations/l',
                'reviewer_name'   => "Reviewer {$n}",
                'rating'          => $rating,
                'reviewed_at'     => now()->subDay(),
            ]);

            if ($answered) {
                ReviewReply::create([
                    'organization_id' => $this->alpha['org']->id,
                    'review_id'       => $review->id,
                    'body'            => 'Thank you.',
                    'status'          => ReviewReply::STATUS_PUBLISHED,
                    'published_at'    => now(),
                ]);
            }

            return $review;
        });
    }

    /** @return array{0: Franchise, 1: User} */
    private function branchReader(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'name'            => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => $this->alpha['admin']->role_id,
                'name'            => 'Branch', 'email' => 'branch@alpha.test',
                'password'        => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'       => $this->alpha['org']->id,
                'user_id'               => $user->id,
                'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /** @return array<string, mixed> */
    private function glance(User $user): array
    {
        return app(ReviewsGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray()['data'];
    }

    public function test_a_reader_who_sees_none_is_not_told_the_business_has_none(): void
    {
        [, $branch] = $this->branchReader();

        $this->review($this->alpha['franchise']);
        $this->review($this->alpha['franchise']);

        $data = $this->glance($branch);

        $this->assertTrue($data['empty']);
        $this->assertSame('No reviews in this view.', $data['sentence']);

        // The control: the organization really does hold reviews, so "yet" was
        // false here rather than merely clumsy.
        $this->assertSame(2, $this->inTenant(fn () => Review::query()->withoutGlobalScopes()->count()));
    }

    /**
     * The most expensive sentence on the box.
     *
     * A branch reader whose own review is answered used to be told that EVERY
     * review had been — while an unanswered one sat in another branch, in
     * public, at the top of the listing.
     */
    public function test_all_answered_speaks_for_the_view_and_not_the_business(): void
    {
        [$second, $branch] = $this->branchReader();

        $this->review($second, answered: true);
        $this->review($this->alpha['franchise'], answered: false);

        $data = $this->glance($branch);

        $this->assertFalse($data['empty']);
        $this->assertContains('Every review in this view has been answered.', $data['sentences']);

        // The control, on the other side of the boundary: the administrator
        // sees the one nobody has answered, so the reassurance above was about
        // reach and not about an organization with nothing outstanding.
        $this->assertContains('1 review unanswered.', $this->glance($this->alpha['admin'])['sentences']);
    }

    /**
     * Google occasionally returns a review with no stars. Those reviews are
     * HERE, holding no rating — not awaited, which is what "yet" claimed.
     */
    public function test_unrated_reviews_are_described_rather_than_awaited(): void
    {
        $this->review(null, rating: null);
        $this->review(null, rating: null);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertContains('No star ratings across 2.', $data['sentences']);

        foreach ($data['sentences'] as $sentence) {
            $this->assertStringNotContainsString(' yet', $sentence);
            $this->assertStringNotContainsString('in reach', $sentence);
        }
    }

    /** With stars, the same slot reads the same shape. */
    public function test_the_average_names_what_it_averaged(): void
    {
        $this->review(null, rating: 5);
        $this->review(null, rating: 4);

        $this->assertContains(
            '4.5 stars across 2.',
            $this->glance($this->alpha['admin'])['sentences'],
        );
    }

    /** With nothing anywhere, the same sentence is simply true. */
    public function test_an_organization_with_no_reviews_says_the_same_thing(): void
    {
        $data = $this->glance($this->alpha['admin']);

        $this->assertTrue($data['empty']);
        $this->assertSame('No reviews in this view.', $data['sentence']);
    }
}
