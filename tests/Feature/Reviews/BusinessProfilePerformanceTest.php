<?php

namespace Tests\Feature\Reviews;

use App\Models\BusinessLocation;
use App\Models\Franchise;
use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Business Profile performance (A1 Leads takeover, 2026-10-20): stored daily counts, reported by reach. */
class BusinessProfilePerformanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    private BusinessLocation $mine;

    private BusinessLocation $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-20 12:00:00');
        $this->t = $this->provisionTenant('A1-Group');
        config(['services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        $series = fn (int $calls) => Http::response(['multiDailyMetricTimeSeries' => [['dailyMetricTimeSeries' => [
            ['dailyMetric' => 'CALL_CLICKS', 'timeSeries' => ['datedValues' => [
                ['date' => ['year' => 2026, 'month' => 10, 'day' => 18], 'value' => (string) $calls],
                ['date' => ['year' => 2026, 'month' => 10, 'day' => 19], 'value' => '1'],
            ]]],
            ['dailyMetric' => 'WEBSITE_CLICKS', 'timeSeries' => ['datedValues' => [
                ['date' => ['year' => 2026, 'month' => 10, 'day' => 19], 'value' => '4'],
            ]]],
        ]]]]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'businessprofileperformance.googleapis.com/v1/locations/1:fetch*' => $series(5),
            'businessprofileperformance.googleapis.com/v1/locations/2:fetch*' => $series(9),
            'businessprofileperformance.googleapis.com/v1/locations/1/searchkeywords/*' => Http::response(['searchKeywordsCounts' => [
                ['searchKeyword' => 'chimney repair', 'insightsValue' => ['value' => '30']],
                ['searchKeyword' => 'chimney sweep', 'insightsValue' => ['value' => '120']],
            ]]),
        ]);

        [$this->mine, $this->theirs] = $this->inTenant($this->t['org'], function () {
            $other = Franchise::create(['organization_id' => $this->t['org']->id, 'company_id' => $this->t['franchise']->company_id,
                'name' => 'Other', 'is_active' => true]);
            $c = SocialConnection::create(['organization_id' => $this->t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $this->t['company']->id, 'provider' => 'google_business_profile', 'remote_id' => 'accounts/9', 'display_name' => 'Co']);
            $c->connect(['refresh_token' => 'rt']);
            $c->save();
            $make = fn (string $n, string $title, string $franchise) => BusinessLocation::create(['organization_id' => $this->t['org']->id,
                'franchise_id' => $franchise, 'connection_id' => $c->id, 'remote_name' => "locations/{$n}", 'account_name' => 'accounts/9', 'title' => $title]);

            return [$make('1', 'North', $this->t['franchise']->id), $make('2', 'South', $other->id)];
        });
    }

    public function test_the_sync_stores_the_counts_and_the_report_sums_them_by_reach(): void
    {
        $this->artisan('ezbizi:gbp-performance-sync')->assertSuccessful();
        $this->artisan('ezbizi:gbp-performance-sync')->assertSuccessful(); // re-reading overwrites, never doubles

        $data = $this->signedInAs($this->t['admin'])->getJson('/api/v1/business-profile/performance')->assertOk()->json('data');
        $this->assertSame(5 + 1 + 9 + 1, $data['totals']['CALL_CLICKS']);
        $this->assertSame(8, $data['totals']['WEBSITE_CLICKS']);
        $this->assertSame(0, $data['totals']['BUSINESS_DIRECTION_REQUESTS']);
        $this->assertSame(['2026-10-18', '2026-10-19'], array_column($data['daily'], 'date'));
        $this->assertSame('2026-10-19', $data['last_date']);

        // A branch sees its own listing only.
        $branch = $this->userWithRole($this->t, 'Branch', ['business_profile.view'], null, $this->t['franchise']);
        $mine = $this->signedInAs($branch)->getJson('/api/v1/business-profile/performance')->assertOk()->json('data');
        $this->assertSame(6, $mine['totals']['CALL_CLICKS']);
        $this->assertSame(['North'], array_column($mine['by_location'], 'name'));
    }

    public function test_search_terms_are_read_live_and_ranked(): void
    {
        $terms = $this->signedInAs($this->t['admin'])->getJson("/api/v1/business-profile/locations/{$this->mine->id}/search-terms")
            ->assertOk()->json('data');

        $this->assertSame([['term' => 'chimney sweep', 'impressions' => 120], ['term' => 'chimney repair', 'impressions' => 30]], $terms);
    }

    public function test_it_needs_the_business_profile_grant(): void
    {
        $this->signedInAs($this->userWithRole($this->t, 'Viewer', ['customers.view']))
            ->getJson('/api/v1/business-profile/performance')->assertForbidden();
    }
}
