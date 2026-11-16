<?php

namespace Tests\Feature\Leads;

use App\Models\Automation;
use App\Models\AutomationVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Leads › Manage › Recovery (Jose, 2026-11-16). */
class RecoveryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const NOT_BOOKED = ['all' => [['op' => 'not_in', 'field' => 'status', 'value' => ['completed', 'lost']]]];

    private function rule(array $t, string $name, string $trigger, ?array $conditions, array $actions): Automation
    {
        return $this->inTenant($t['org'], function () use ($t, $name, $trigger, $conditions, $actions) {
            $rule = Automation::create(['organization_id' => $t['org']->id, 'name' => $name, 'trigger_key' => $trigger, 'is_active' => true]);
            $rule->addVersion($conditions, $actions, $t['admin']);

            return $rule->fresh();
        });
    }

    public function test_it_lists_the_customer_replies_on_recovery_triggers_and_nothing_else(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $reply = [['type' => 'reply', 'message' => '{{thank_you}} for calling {{company_name}}!']];
        $this->rule($t, 'Not booked - after a call', 'lead.call_not_booked', self::NOT_BOOKED, $reply);
        $this->rule($t, 'Closed Office - Initial Auto Reply', 'lead.received',
            ['all' => [['op' => 'eq', 'field' => 'office_open', 'value' => false]]], $reply);
        // Not recovery: a first reply in office hours, and a staff alert.
        $this->rule($t, 'LS Text - Initial Auto Reply', 'lead.received',
            ['all' => [['op' => 'eq', 'field' => 'office_open', 'value' => true]]], $reply);
        $this->rule($t, 'Missed call alert', 'lead.missed_call', null, [['type' => 'notify', 'role_ids' => []]]);

        $data = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/recovery')->assertOk()->json('data');

        $this->assertSame(['Closed Office - Initial Auto Reply', 'Not booked - after a call'], array_column($data['rules'], 'name'));
        $this->assertSame('{{thank_you}} for calling {{company_name}}!', $data['rules'][1]['message']);
        $this->assertEquals(self::NOT_BOOKED, $data['rules'][1]['conditions']);
        $this->assertSame(['scheduling.overbooked_offer_message', 'scheduling.offer_message'], array_column($data['texts'], 'key'));
    }

    /** 11-12: an actions-only PATCH wiped a rule's conditions. The tab sends the whole version. */
    public function test_editing_the_text_keeps_the_conditions_and_toggling_keeps_the_version(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $rule = $this->rule($t, 'Not booked - after a call', 'lead.call_not_booked', self::NOT_BOOKED,
            [['type' => 'reply', 'message' => 'Old words']]);
        $card = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/recovery')->json('data.rules.0');

        $card['actions'][0]['message'] = 'New words {{booking_link}}';
        $this->signedInAs($t['admin'])->patchJson("/api/v1/automations/{$rule->id}", [
            'conditions' => $card['conditions'], 'actions' => $card['actions'],
        ])->assertOk()->assertJsonPath('data.version', 2);

        $after = $this->signedInAs($t['admin'])->getJson('/api/v1/leads/recovery')->json('data.rules.0');
        $this->assertSame('New words {{booking_link}}', $after['message']);
        $this->assertEquals(self::NOT_BOOKED, $after['conditions']);

        // Scope from the Company · Branch picker: no new version either.
        $this->signedInAs($t['admin'])->patchJson("/api/v1/automations/{$rule->id}", [
            'scope_type' => 'company', 'scope_id' => $t['company']->id,
        ])->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame("company:{$t['company']->id}", $this->signedInAs($t['admin'])->getJson('/api/v1/leads/recovery')->json('data.rules.0.place'));

        $this->signedInAs($t['admin'])->patchJson("/api/v1/automations/{$rule->id}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false)->assertJsonPath('data.version', 2);
        $this->assertSame(2, $this->inTenant($t['org'], fn () => AutomationVersion::query()->where('automation_id', $rule->id)->count()));
    }

    public function test_the_preview_fills_the_variables_for_a_brand_and_writes_nothing(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $data = $this->signedInAs($t['admin'])->postJson('/api/v1/leads/recovery/preview', [
            'text' => "{{thank_you}} for calling {{company_name}}!\nBook here: {{booking_link}}",
            'company_id' => $t['company']->id,
        ])->assertOk()->json('data');

        $this->assertStringContainsString('Jane', $data['text']);
        $this->assertStringContainsString("for calling {$t['company']->name}!", $data['text']);
        // No booking page set up, so the link's line is dropped as a real send drops it.
        $this->assertStringNotContainsString('Book here', $data['text']);
        $this->assertStringNotContainsString('{{', $data['text']);
        $this->assertDatabaseCount('leads', 0);
        $this->assertDatabaseCount('booking_codes', 0);
    }

    public function test_it_needs_the_automations_permission(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $user = $this->userWithRole($t, 'Viewer', ['leads.view']);

        $this->signedInAs($user)->getJson('/api/v1/leads/recovery')->assertForbidden();
    }
}
