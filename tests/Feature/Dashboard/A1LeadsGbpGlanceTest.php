<?php

namespace Tests\Feature\Dashboard;

use App\Models\A1LeadsReviewEvent;
use App\Models\Company;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\A1LeadsGbpGlance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The A1Leads GBP box counts A-1 Chimney's new reviews, and only for somebody
 * with A-1 Chimney in view - the tab's own rule, asked of the same person in
 * two views so the refusal cannot be a missing row.
 */
class A1LeadsGbpGlanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_counts_new_reviews_and_the_low_ones_only_with_a1_in_view(): void
    {
        Carbon::setTestNow('2026-09-25T15:00:00Z');
        $t = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.organization_id', $t['org']->id);
        config()->set('services.a1leads.company_id', $t['company']->id);
        $user = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $other = $this->inTenant($t['org'], function () use ($t) {
            foreach ([['r-5', 5], ['r-1', 1], ['r-2', 2]] as [$name, $rating]) {
                A1LeadsReviewEvent::create([
                    'company_id' => $t['company']->id, 'google_review_name' => "accounts/a/locations/l/reviews/{$name}",
                    'rating' => $rating, 'review_created_at' => '2026-09-24T10:00:00Z',
                ]);
            }
            // Last year: outside the period, so not counted.
            A1LeadsReviewEvent::create([
                'company_id' => $t['company']->id, 'google_review_name' => 'accounts/a/locations/l/reviews/old',
                'rating' => 1, 'review_created_at' => '2025-01-01T10:00:00Z',
            ]);

            return Company::create(['organization_id' => $t['org']->id, 'name' => 'Everdoors']);
        });

        $data = app(A1LeadsGbpGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray()['data'];
        $this->assertFalse($data['empty']);
        $this->assertSame(['3 new Google reviews this month.', '2 of them 1 to 3 stars.'], $data['sentences']);

        // The same person with Everdoors in view sees none of it.
        $user = $this->inTenant($t['org'], function () use ($user, $other) {
            $user->forceFill(['preferences' => ['navigation.scope' => 'company:'.$other->id]])->save();

            return $user->fresh();
        });
        $data = app(A1LeadsGbpGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray()['data'];
        $this->assertTrue($data['empty']);
        $this->assertStringContainsString('Switch to A-1 Chimney', $data['sentence']);
    }

    /**
     * Both figures compare today so far with yesterday to the same hour.
     *
     * Yesterday afternoon's review is after noon, so a whole-day comparison
     * would say 2 and 2 where the same span says 1 and 1.
     */
    public function test_the_figures_compare_with_the_same_span_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $t = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.organization_id', $t['org']->id);
        config()->set('services.a1leads.company_id', $t['company']->id);
        $user = $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);

        $this->inTenant($t['org'], function () use ($t) {
            foreach ([
                ['today-5', 5, '2026-08-24 09:00:00'],
                ['today-2', 2, '2026-08-24 09:00:00'],
                ['today-1', 1, '2026-08-24 09:00:00'],
                ['yesterday-morning', 1, '2026-08-23 10:00:00'],
                ['yesterday-afternoon', 2, '2026-08-23 13:00:00'],
            ] as [$name, $rating, $at]) {
                A1LeadsReviewEvent::create([
                    'company_id' => $t['company']->id, 'google_review_name' => "accounts/a/locations/l/reviews/{$name}",
                    'rating' => $rating, 'review_created_at' => Carbon::parse($at, 'America/New_York')->utc(),
                ]);
            }
        });

        $data = app(A1LeadsGbpGlance::class)->resolve($user, DashboardPeriod::for($user, 'day'), [])->toArray()['data'];

        $this->assertSame([
            ['key' => 'new', 'label' => 'new today', 'value' => 3, 'previous' => 1],
            ['key' => 'low', 'label' => '1 to 3 stars', 'value' => 2, 'previous' => 1],
        ], $data['figures']);
    }
}
