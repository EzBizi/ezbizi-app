<?php

namespace Tests\Feature\Reviews;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Services\BusinessProfile\BusinessProfileApi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A1 Leads takeover (2026-10-19): the Business Profile reads Customer Experience builds on. */
class BusinessProfileApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_performance_terms_media_services_and_standing_read_and_the_write_waits(): void
    {
        $t = $this->provisionTenant('A1-Group');
        config(['services.business_profile.client_id' => 'cid', 'services.business_profile.client_secret' => 'cs']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'businessprofileperformance.googleapis.com/v1/locations/55:fetchMultiDailyMetricsTimeSeries*' => Http::response(['multiDailyMetricTimeSeries' => [[
                'dailyMetricTimeSeries' => [['dailyMetric' => 'CALL_CLICKS', 'timeSeries' => ['datedValues' => [
                    ['date' => ['year' => 2026, 'month' => 10, 'day' => 1], 'value' => '7'],
                    ['date' => ['year' => 2026, 'month' => 10, 'day' => 2]],
                ]]]],
            ]]]),
            'businessprofileperformance.googleapis.com/v1/locations/55/searchkeywords/*' => Http::response(['searchKeywordsCounts' => [
                ['searchKeyword' => 'chimney sweep', 'insightsValue' => ['value' => '120']],
                ['searchKeyword' => 'a1 chimney', 'insightsValue' => ['threshold' => '15']],
            ]]),
            'mybusiness.googleapis.com/v4/accounts/9/locations/55/media*' => Http::response(['mediaItems' => [
                ['name' => 'accounts/9/locations/55/media/m1', 'mediaFormat' => 'PHOTO', 'googleUrl' => 'https://lh3/x', 'locationAssociation' => ['category' => 'EXTERIOR']],
            ]]),
            'mybusinessbusinessinformation.googleapis.com/v1/locations/55*' => Http::response([
                'categories' => ['primaryCategory' => ['name' => 'categories/gcid:chimney_sweep', 'displayName' => 'Chimney sweep']],
                'serviceItems' => [['structuredServiceItem' => ['serviceTypeId' => 'job_type_id:chimney_inspection']]],
            ]),
            'mybusinessverifications.googleapis.com/v1/locations/55:getVoiceOfMerchantState' => Http::response(['hasVoiceOfMerchant' => true, 'hasBusinessAuthority' => true]),
            'mybusinessverifications.googleapis.com/v1/locations/55/verifications' => Http::response(['verifications' => [['method' => 'PHONE_CALL', 'state' => 'COMPLETED']]]),
        ]);

        $this->inTenant($t['org'], function () use ($t) {
            $connection = SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $t['company']->id, 'provider' => 'google_business_profile', 'remote_id' => 'accounts/9', 'display_name' => 'A1']);
            $connection->connect(['refresh_token' => 'rt']);
            $connection->save();
            $location = BusinessLocation::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'connection_id' => $connection->id, 'remote_name' => 'locations/55', 'account_name' => 'accounts/9', 'title' => 'A1 Chimney']);
            $api = app(BusinessProfileApi::class);

            $perf = $api->performance($location, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-02'));
            $this->assertSame(['2026-10-01' => 7, '2026-10-02' => 0], $perf['CALL_CLICKS']);
            $this->assertSame(['chimney sweep' => 120, 'a1 chimney' => 15], $api->searchTerms($location, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-01')));
            $this->assertSame('EXTERIOR', $api->media($location)[0]['category']);
            $cs = $api->categoriesAndServices($location);
            $this->assertSame('Chimney sweep', $cs['primary']['displayName']);
            $this->assertCount(1, $cs['services']);
            $standing = $api->standing($location);
            $this->assertTrue($standing['has_voice_of_merchant']);
            $this->assertSame('COMPLETED', $standing['verifications'][0]['state']);

            try {
                $api->replaceServices($location, []);
                $this->fail('a write went out while writes are off');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not switched on', $e->getMessage());
            }
        });
        Http::assertNotSent(fn ($r) => $r->method() === 'PATCH');
    }
}
