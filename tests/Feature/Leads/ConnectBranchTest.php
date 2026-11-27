<?php

namespace Tests\Feature\Leads;

use App\Models\SocialConnection;
use App\Support\Leads\ConnectBranch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk, 2026-11-12: every connection whose leads carry a branch gets one at connect when it is plain. */
class ConnectBranchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_lead_channels_get_the_only_branch_and_a_listing_does_not(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $branchOf = fn (string $provider) => $this->inTenant($t['org'], function () use ($t, $provider) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => $provider, 'remote_id' => $provider.'-1', 'display_name' => $provider,
            ]);
            $c->connect(['access_token' => 'a']);
            $c->save();
            ConnectBranch::apply($c);

            return $c->fresh()->leads_franchise_id;
        });

        foreach (['facebook_page', 'instagram_professional', 'whatsapp'] as $provider) {
            $this->assertSame($t['franchise']->id, $branchOf($provider), $provider);
        }
        $this->assertNull($branchOf('google_business_profile'), 'a listing is filed by its own sync');

        $this->extraFranchise($t, 'Cleveland');
        $this->assertNull($branchOf('thumbtack'), 'two branches: a person chooses');
    }
}
