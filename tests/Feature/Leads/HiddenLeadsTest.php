<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\EventEmitter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-29: Bar's WhatsApp carries private financial talk that dispatchers must not see. */
class HiddenLeadsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_hidden_lead_is_gone_for_a_dispatcher_and_there_for_the_hidden_grant(): void
    {
        $t = $this->provisionTenant();
        $dispatcher = $this->userWithRole($t, 'Dispatcher', ['leads.view', 'leads.manage', 'leads.whatsapp.view']);
        $keeper = $this->userWithRole($t, 'Owner', ['leads.view', 'leads.manage', 'leads.whatsapp.view', Lead::HIDDEN_PERMISSION]);
        $lead = $this->lead($t, '+15553334444');

        $this->signedInAs($dispatcher)->postJson("/api/v1/leads/{$lead->id}/hide")->assertForbidden();
        $this->signedInAs($keeper)->postJson("/api/v1/leads/{$lead->id}/hide")->assertOk();

        $this->signedInAs($dispatcher)->getJson('/api/v1/leads')->assertOk()->assertJsonCount(0, 'data');
        $this->signedInAs($dispatcher)->getJson("/api/v1/leads/{$lead->id}")->assertNotFound();
        $this->signedInAs($dispatcher)->getJson('/api/v1/leads/hidden')->assertForbidden();

        $this->signedInAs($keeper)->getJson('/api/v1/leads')->assertOk()->assertJsonCount(1, 'data');
        $this->signedInAs($keeper)->getJson('/api/v1/leads/hidden?q=3334444')->assertOk()->assertJsonPath('data.leads.0.id', $lead->id);

        $this->signedInAs($keeper)->postJson("/api/v1/leads/{$lead->id}/unhide")->assertOk();
        $this->signedInAs($dispatcher)->getJson("/api/v1/leads/{$lead->id}")->assertOk();
    }

    public function test_an_always_hidden_number_hides_its_next_lead_and_no_rule_acts_on_it(): void
    {
        $t = $this->provisionTenant();
        $keeper = $this->userWithRole($t, 'Owner', ['leads.view', 'leads.manage', 'leads.whatsapp.view', Lead::HIDDEN_PERMISSION]);
        $first = $this->lead($t, '+15553334444');

        $this->signedInAs($keeper)->postJson("/api/v1/leads/{$first->id}/hide", ['always' => true])->assertOk();

        [$next, $event] = $this->inTenant($t['org'], function () use ($t) {
            $next = $this->lead($t, '(555) 333-4444');

            return [$next, app(EventEmitter::class)->emit('lead.received', $next)];
        });
        $this->assertNotNull($next->fresh()->hidden_at, 'filed hidden');

        $this->inTenant($t['org'], fn () => app(AutomationEngine::class)->deliver($event));
        $this->assertSame('Not run: this lead is hidden.', AutomationEvent::withoutGlobalScopes()->find($event->id)->last_error);

        $this->signedInAs($keeper)->getJson('/api/v1/leads/hidden')->assertOk()
            ->assertJsonPath('data.numbers.0.digits', '5553334444')->assertJsonPath('data.numbers.0.leads', 2);
    }

    private function lead(array $t, string $phone): Lead
    {
        return $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id, 'source' => Lead::SOURCE_WHATSAPP, 'remote_id' => uniqid(),
            'phone' => $phone, 'phone_digits' => Lead::digits($phone), 'status' => 'new', 'received_at' => now(),
        ]));
    }
}
