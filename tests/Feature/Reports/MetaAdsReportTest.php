<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\MetaAdCampaign;
use App\Models\MetaAdInsight;
use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-12: "Facebook Financial report + Retro filter by state and category". */
class MetaAdsReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_spend_per_campaign_filtered_by_state_and_where_viewers_were(): void
    {
        $t = $this->provisionTenant('Alpha');
        $user = $this->userWithRole($t, 'marketer', ['reports.meta.view', 'reports.build']);

        [$nj] = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => (string) $t['org']->id, 'provider' => 'facebook_ads',
                'remote_id' => 'act_1', 'display_name' => 'A1', 'handle' => 'act_1',
            ]);
            $c->connect(['access_token' => 'x']);
            $c->save();

            $group = (string) Str::uuid();
            DB::table('service_groups')->insert(['id' => $group, 'organization_id' => $t['org']->id, 'name' => 'Chimney', 'created_at' => now(), 'updated_at' => now()]);

            $nj = MetaAdCampaign::query()->create(['connection_id' => $c->id, 'campaign_id' => '1', 'name' => 'NJ Chimney', 'state' => 'NJ', 'service_group_id' => $group]);
            MetaAdCampaign::query()->create(['connection_id' => $c->id, 'campaign_id' => '2', 'name' => 'TX Garage', 'state' => 'TX']);

            foreach ([
                ['1', '', 100.50, 4], ['1', 'New Jersey', 80.00, 3], ['1', 'Pennsylvania', 20.50, 1],
                ['2', '', 50.00, 0],
            ] as [$campaign, $region, $spend, $leads]) {
                MetaAdInsight::query()->create(['connection_id' => $c->id, 'date' => '2026-10-05', 'campaign_id' => $campaign,
                    'adset_id' => 'as'.$campaign, 'region' => $region, 'spend' => $spend, 'leads' => 999, 'forms' => $leads, 'clicks' => 10, 'impressions' => 100]);
            }

            return [$nj];
        });

        $get = fn (string $q) => $this->signedInAs($user)->getJson('/api/v1/reports/meta?from=2026-10-01&to=2026-10-07'.$q)->assertOk();

        // Totals only: 100.50, not 100.50 + the region rows.
        $all = $get('');
        $all->assertJsonPath('data.rows.0.name', 'NJ Chimney')
            ->assertJsonPath('data.rows.0.spend_minor', 10050)
            ->assertJsonPath('data.rows.0.cost_per_lead_minor', 2512)
            ->assertJsonPath('data.rows.0.category', 'Chimney')
            ->assertJsonPath('data.rows.1.cost_per_lead_minor', null)
            ->assertJsonPath('data.regions', ['New Jersey', 'Pennsylvania']);

        $get('&state=TX')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.name', 'TX Garage');
        $get('&category=none')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.name', 'TX Garage');
        $get('&region=New%20Jersey')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.spend_minor', 8000);

        // Seeing is not editing.
        $this->signedInAs($this->userWithRole($t, 'viewer', ['reports.meta.view']))
            ->putJson('/api/v1/reports/meta/campaigns/'.$nj->id, ['state' => 'PA'])->assertForbidden();

        // Staff map a campaign to a branch.
        $this->signedInAs($user)->putJson('/api/v1/reports/meta/campaigns/'.$nj->id, ['franchise_id' => $t['franchise']->id])->assertOk();
        $get('')->assertJsonPath('data.rows.0.branch', $t['franchise']->name);

        // Desk, 2026-10-27: a branch-limited builder sees and moves only its branch's campaigns.
        $other = $this->inTenant($t['org'], fn () => Franchise::query()->create(['organization_id' => $t['org']->id, 'company_id' => $t['franchise']->company_id, 'name' => 'Other', 'is_active' => true]));
        $local = $this->userWithRole($t, 'local', ['reports.meta.view', 'reports.build'], onlyAt: $other);
        $this->signedInAs($local)->getJson('/api/v1/reports/meta/campaigns')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($local)->getJson('/api/v1/reports/meta?from=2026-10-01&to=2026-10-07')->assertOk()
            ->assertJsonCount(0, 'data.rows')->assertJsonPath('data.states', [])->assertJsonPath('data.regions', []);
        $this->signedInAs($local)->putJson('/api/v1/reports/meta/campaigns/'.$nj->id, ['franchise_id' => $other->id])->assertForbidden();
        $this->signedInAs($user)->putJson('/api/v1/reports/meta/campaigns/'.$nj->id, ['franchise_id' => $other->id])->assertOk();
        $this->signedInAs($local)->putJson('/api/v1/reports/meta/campaigns/'.$nj->id, ['franchise_id' => $t['franchise']->id])->assertForbidden();
        $this->signedInAs($local)->putJson('/api/v1/reports/meta/campaigns/'.$nj->id, ['state' => 'PA'])->assertOk();
    }

    public function test_the_tab_permission_is_needed(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($this->userWithRole($t, 'tech', []))
            ->getJson('/api/v1/reports/meta?from=2026-10-01&to=2026-10-07')->assertForbidden();
    }
}
