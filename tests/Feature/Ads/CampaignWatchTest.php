<?php

namespace Tests\Feature\Ads;

use App\Models\AutomationEvent;
use App\Models\SocialConnection;
use App\Support\Audit\AuditLogger;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\AdsPerformanceGlance;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A Local Services campaign that stops showing raises `ads.campaign_stopped`
 * once, naming who paused it - EzBizi's own audit first, then Google's change
 * history. Shapes measured 2026-09-28 on A-1's 37 LSA campaigns:
 * ENABLED/ELIGIBLE (32) and PAUSED/PAUSED (5).
 */
class CampaignWatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private string $status = 'ENABLED';

    private string $primary = 'ELIGIBLE';

    private array $changes = [];

    private array $t;

    private SocialConnection $row;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        $this->row = $this->inTenant($this->t['org'], function () {
            $row = SocialConnection::create([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_COMPANY,
                'scope_id' => $this->t['company']->id,
                'provider' => SocialProvider::GoogleAds->value,
                'remote_id' => '4941850746',
                'display_name' => 'A1 Chimney - Cleveland',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });

        config([
            'services.google_ads.client_id' => 'client.apps.googleusercontent.com',
            'services.google_ads.client_secret' => 'secret',
            'services.google_ads.developer_token' => 'dev-token',
            'services.google_ads.version' => 'v24',
        ]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'expires_in' => 3600], 200),
            'googleads.googleapis.com/*' => fn (Request $r) => Http::response(['results' => str_contains((string) $r['query'], 'FROM change_event')
                ? $this->changes
                : [['campaign' => ['resourceName' => 'customers/4941850746/campaigns/77', 'id' => '77',
                    'name' => 'LSA Cleveland', 'status' => $this->status, 'primaryStatus' => $this->primary]]]], 200),
        ]);
        Http::preventStrayRequests();
    }

    private function raised(): array
    {
        return AutomationEvent::withoutGlobalScopes()->where('event_key', 'ads.campaign_stopped')->get()->all();
    }

    public function test_a_pause_made_in_google_is_raised_once_with_who_made_it(): void
    {
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();
        $this->assertSame([], $this->raised(), 'the first look has no before');

        $this->status = $this->primary = 'PAUSED';
        $this->changes = [
            ['changeEvent' => ['campaign' => 'customers/4941850746/campaigns/12', 'userEmail' => 'someone@else.com', 'clientType' => 'GOOGLE_ADS_WEB_CLIENT']],
            ['changeEvent' => ['campaign' => 'customers/4941850746/campaigns/77', 'userEmail' => 'rodrigo@a-1chimney.com', 'clientType' => 'GOOGLE_ADS_API']],
        ];
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();

        $events = $this->raised();
        $this->assertCount(1, $events);
        $this->assertSame('LSA Cleveland', $events[0]->payload['campaign']);
        $this->assertSame('paused', $events[0]->payload['reason']);
        $this->assertSame('rodrigo@a-1chimney.com (google ads api)', $events[0]->payload['paused_by']);

        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();
        $this->assertCount(1, $this->raised(), 'still paused is not news');
    }

    /** Account change alerts (A1 Leads takeover, 2026-10-19): back on is news too. */
    public function test_a_campaign_showing_again_is_raised_once(): void
    {
        $this->status = $this->primary = 'PAUSED';
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();
        $this->status = 'ENABLED';
        $this->primary = 'ELIGIBLE';
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();

        $back = AutomationEvent::withoutGlobalScopes()->where('event_key', 'ads.campaign_resumed')->get();
        $this->assertCount(1, $back, 'once, not every hour it stays on');
        $this->assertSame('LSA Cleveland', $back[0]->payload['campaign']);
        $this->assertSame([], $this->raised());
    }

    public function test_a_pause_pressed_in_ezbizi_names_the_person(): void
    {
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();

        $this->inTenant($this->t['org'], fn () => app(AuditLogger::class)->log(
            'ads_performance.campaign_status_changed',
            $this->row,
            ['status' => 'PAUSED'],
            ['campaign_id' => '77', 'customer_id' => '4941850746'],
            actor: $this->t['admin'],
        ));
        $this->status = $this->primary = 'PAUSED';
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();

        $this->assertSame('EzBizi: '.$this->t['admin']->name, $this->raised()[0]->payload['paused_by']);
    }

    public function test_the_dashboard_box_leads_with_what_is_wrong(): void
    {
        $glance = fn () => app(AdsPerformanceGlance::class)
            ->resolve($this->t['admin']->fresh(), DashboardPeriod::for($this->t['admin']->fresh()), [])
            ->toArray()['data'];

        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();
        $this->assertSame(['1 account connected.'], $glance()['sentences']);

        $this->status = $this->primary = 'PAUSED';
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();
        $this->assertSame(['1 Local Services campaign is paused.', '1 account connected.'], $glance()['sentences']);
    }

    public function test_a_campaign_google_stops_itself_has_nobody_to_name(): void
    {
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();

        $this->primary = 'NOT_ELIGIBLE';
        $this->artisan('ezbizi:ads-campaign-watch')->assertSuccessful();

        $event = $this->raised()[0];
        $this->assertSame('not eligible', $event->payload['reason']);
        $this->assertNull($event->payload['paused_by']);
    }
}
