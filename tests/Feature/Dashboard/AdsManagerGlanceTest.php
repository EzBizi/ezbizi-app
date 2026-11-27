<?php

namespace Tests\Feature\Dashboard;

use App\Models\AdSchedule;
use App\Models\SocialConnection;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\AdsManagerGlance;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Marketing › Ads box: accounts and schedules in view, counts only. */
class AdsManagerGlanceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_counts_accounts_and_failed_schedules_without_money(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            foreach ([SocialProvider::GoogleAds, SocialProvider::GoogleAds, SocialProvider::FacebookAds] as $i => $provider) {
                $c = SocialConnection::create(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_COMPANY,
                    'scope_id' => $t['company']->id, 'provider' => $provider->value, 'remote_id' => (string) (100 + $i), 'display_name' => "A{$i}"]);
                $c->connect(['refresh_token' => 'rt']);
                $c->save();
            }
            AdSchedule::create(['organization_id' => $t['org']->id, 'connection_id' => $c->id, 'object_id' => '1', 'object_name' => 'C',
                'days' => [1, 2, 3, 4, 5], 'on_at' => '08:00', 'off_at' => '18:00', 'timezone' => 'America/New_York', 'last_error' => 'Meta refused']);
        });
        $user = $this->userWithRole($t, 'Marketing', ['ads_manager.view']);

        $data = app(AdsManagerGlance::class)->resolve($user, DashboardPeriod::for($user), [])->toArray();
        $json = json_encode($data);

        $this->assertStringContainsString('2 Google and 1 Meta accounts connected.', $json);
        $this->assertStringContainsString('1 schedule could not switch its campaign.', $json);
        $this->assertStringNotContainsString('$', $json);
    }
}
