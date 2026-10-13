<?php

namespace Tests\Feature\Ai;

use App\Models\MetaAdCampaign;
use App\Models\ServiceGroup;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12: AI suggests each Facebook campaign's category; a person confirms. */
class SuggestMetaCategoriesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_suggestion_is_written_beside_the_category_and_never_into_it(): void
    {
        config(['services.grok_cli.home' => '/var/lib/ezbizi-grok']);
        $t = $this->provisionTenant('A1-Group');
        [$campaign, $chimney, $doors] = $this->inTenant($t['org'], function () use ($t) {
            $chimney = ServiceGroup::create(['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id, 'name' => 'Chimney']);
            $doors = ServiceGroup::create(['company_id' => $t['company']->id, 'franchise_id' => $t['franchise']->id, 'name' => 'Garage doors']);
            $c = SocialConnection::create([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $t['org']->id, 'provider' => SocialProvider::FacebookAds->value,
                'remote_id' => 'act_1', 'display_name' => 'A1 ads',
            ]);
            $campaign = MetaAdCampaign::create([
                'organization_id' => $t['org']->id, 'connection_id' => $c->id, 'campaign_id' => '1',
                'name' => 'NJ Chimney Sweep Fall', 'service_group_id' => $doors->id,
            ]);

            return [$campaign, $chimney, $doors];
        });
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['category' => $chimney->id]]))]);

        $this->artisan('ezbizi:suggest-meta-categories')->assertSuccessful();
        $this->artisan('ezbizi:suggest-meta-categories')->assertSuccessful();

        Process::assertRanTimes(fn () => true, 1);
        $campaign = $this->inTenant($t['org'], fn () => $campaign->fresh());
        $this->assertSame($chimney->id, $campaign->suggested_service_group_id);
        $this->assertSame($doors->id, $campaign->service_group_id, 'the real category is a person\'s');
    }
}
