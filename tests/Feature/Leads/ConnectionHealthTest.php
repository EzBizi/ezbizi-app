<?php

namespace Tests\Feature\Leads;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Connections page's "Not fetching": a Google Ads account still marked
 * connected that nothing has read for hours (2026-09-30).
 */
class ConnectionHealthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_connected_account_nothing_reads_is_flagged(): void
    {
        $t = $this->provisionTenant('A1-Group');

        foreach (['fresh' => 1, 'stale' => 7] as $id => $hours) {
            $this->inTenant($t['org'], function () use ($t, $id, $hours) {
                $row = SocialConnection::create([
                    'organization_id' => $t['org']->id,
                    'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                    'scope_id' => $t['org']->id,
                    'provider' => SocialProvider::GoogleAds->value,
                    'remote_id' => $id,
                    'display_name' => $id,
                ]);
                $row->connect(['refresh_token' => 'rt']);
                $row->forceFill(['last_reached_at' => now()->subHours($hours)])->save();
            });
        }

        $rows = collect($this->signedInAs($t['admin'])->getJson('/api/v1/leads')->assertOk()->json('meta.connections'))
            ->keyBy('remote_id');

        $this->assertFalse($rows['fresh']['sync_failing']);
        $this->assertTrue($rows['stale']['sync_failing']);
        $this->assertArrayHasKey('status_reason', $rows['stale']);
        $this->assertTrue($rows['stale']['may_pause'], 'an administrator may pause');
        $this->assertNull($rows['stale']['campaigns'], 'not read by the watch yet');
    }
}
