<?php

namespace Tests\Feature\Monitoring;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * 2026-10-29: whole offices read offline. Every agent route used
 * `throttle:N,1` with no name, which Laravel keys by user id alone, so one
 * bucket held them all: the 1.5-second polls spent the heartbeat's allowance
 * and the beats came back 429. Each route now counts on its own.
 */
class AgentThrottleBucketsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_a_spent_poll_allowance_does_not_refuse_the_heartbeat(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $agent = $this->signedInAs($alpha['admin']);

        for ($i = 0; $i < 120; $i++) {
            $agent->getJson('/api/v1/monitoring/input-pending?device_key=dk-1');
        }
        $agent->getJson('/api/v1/monitoring/input-pending?device_key=dk-1')
            ->assertStatus(429); // the control: that bucket really is spent

        $agent->postJson('/api/v1/monitoring/heartbeat', [
            'device_key' => 'dk-1', 'platform' => 'windows', 'capabilities' => ['activity'],
        ])->assertNoContent();
    }
}
