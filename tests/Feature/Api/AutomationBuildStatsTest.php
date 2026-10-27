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
            ->assertOk()->assertExactJson(['data' => ['email.received' => ['last_30_days' => 2, 'details' => 2, 'rules' => 0, 'top_actions' => []]]]);

        $run = $this->signedInAs($t['admin'])->postJson('/api/v1/automations/would-have-run', [
            'trigger' => 'email.received',
            'conditions' => ['all' => [['field' => 'from_address', 'op' => 'contains', 'value' => 'ringcentral']]],
        ])->assertOk()->json('data');
        $this->assertSame([2, 1], [$run['total'], $run['matched']]);
        $this->assertSame([true, false], array_column($run['examples'], 'matched'));

        // Plain words and kinds, and a draft tried on the newest event.
        $fields = $this->signedInAs($t['admin'])->getJson('/api/v1/automations/trigger-fields?trigger=email.received')->assertOk()->json('data');
        $this->assertSame('From address', $fields['labels']['from_address']);
        $this->assertSame('text', $fields['types']['from_address']);
        $this->assertCount(3, $fields['events'], 'the last three times, whole, whatever their age');
        $this->assertSame('notify@ringcentral.com', $fields['events'][0]['values']['from_address']);
        $this->assertNotEmpty($fields['events'][0]['event_id']);
        $dry = $this->signedInAs($t['admin'])->postJson('/api/v1/automations/dry-run', [
            'trigger_key' => 'email.received',
            'conditions' => ['all' => [['field' => 'from_address', 'op' => 'contains', 'value' => 'ringcentral']]],
            'actions' => [['type' => 'notify', 'message' => 'Mail from {{from_address}}']],
        ])->assertOk()->json('data');
        $this->assertTrue($dry['matched']);
        $this->assertStringContainsString('Would use: Mail from notify@ringcentral.com', $dry['would_do'][0]['detail']);
        $this->assertSame(0, \App\Models\Notification::withoutGlobalScopes()->count(), 'a dry run sends nothing');

        $viewer = $this->userWithRole($t, 'Nobody', []);
        $this->signedInAs($viewer)->getJson('/api/v1/automations/trigger-stats?triggers=email.received')->assertForbidden();
    }
    public function test_readiness_says_why_a_step_cannot_send_here(): void
    {
        $t = $this->provisionTenant('Alpha');
        $url = '/api/v1/automations/readiness?scope_type=franchise&scope_id='.$t['franchise']->id;
        $before = $this->signedInAs($t['admin'])->getJson($url)->assertOk()->json('data');
        $this->assertSame(['ready' => false, 'why_not' => 'No phone number is connected here.'], $before['sms.send']);
        $this->assertTrue($before['notify']['ready']);

        $this->inTenant($t['org'], function () use ($t) {
            $c = \App\Models\PhoneConnection::factory()->create(['scope_type' => 'organization', 'scope_id' => $t['org']->id]);
            \App\Models\PhoneNumber::factory()->create(['connection_id' => $c->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'e164' => '+12035551004', 'sms_outbound_allowed' => true]);
        });
        $this->assertTrue($this->signedInAs($t['admin'])->getJson($url)->assertOk()->json('data')['sms.send']['ready']);
    }
}
