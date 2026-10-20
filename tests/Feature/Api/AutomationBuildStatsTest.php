<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Build's step screens (UI/UX, 2026-10-20): how often a trigger happened, and how many a condition would have run on. */
class AutomationBuildStatsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_counts_the_last_30_days_and_says_which_would_have_run(): void
    {
        $t = $this->provisionTenant('Alpha');
        $mail = fn (string $from, $at) => $this->inTenant($t['org'], fn () => AutomationEvent::create([
            'organization_id' => $t['org']->id, 'event_key' => 'email.received',
            'payload' => ['from_address' => $from, 'subject' => 'Hi'], 'occurred_at' => $at, 'available_at' => $at,
        ]));
        $mail('notify@ringcentral.com', now());
        $mail('someone@example.test', now()->subDay());
        $mail('notify@ringcentral.com', now()->subDays(40)); // too old

        $this->signedInAs($t['admin'])->getJson('/api/v1/automations/trigger-stats?triggers=email.received,nope')
            ->assertOk()->assertExactJson(['data' => ['email.received' => ['last_30_days' => 2]]]);

        $run = $this->signedInAs($t['admin'])->postJson('/api/v1/automations/would-have-run', [
            'trigger' => 'email.received',
            'conditions' => ['all' => [['field' => 'from_address', 'op' => 'contains', 'value' => 'ringcentral']]],
        ])->assertOk()->json('data');
        $this->assertSame([2, 1], [$run['total'], $run['matched']]);
        $this->assertSame([true, false], array_column($run['examples'], 'matched'));

        $viewer = $this->userWithRole($t, 'Nobody', []);
        $this->signedInAs($viewer)->getJson('/api/v1/automations/trigger-stats?triggers=email.received')->assertForbidden();
    }
}
