<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What a condition may be built on, read off the events themselves.
 *
 * The builder's Field box was free text against a fixed vocabulary nothing
 * published, so a customer wrote `from` and `Status` for an email rule whose
 * payload carries `from_address` and `subject`. Both clauses read null, the rule
 * skipped every run for a fortnight, and the trace said "the value at the time
 * was nothing" — which reads like missing data rather than a wrong field name.
 *
 * These cover the two halves that make the picker trustworthy: it offers what a
 * condition can actually read, and it never carries the data.
 */
class AutomationTriggerFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_it_offers_the_fields_an_email_event_actually_carried(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->recordEmailEvent($t);

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=email.received')
            ->assertOk()
            ->json('data');

        // The two the customer needed and could not guess.
        $this->assertContains('from_address', $data['fields']);
        $this->assertContains('subject', $data['fields']);

        // And the ones they invented are absent, which is the point: a picker
        // cannot offer `Status`, so it cannot be typed.
        $this->assertNotContains('Status', $data['fields']);
        $this->assertNotContains('from', $data['fields']);

        $this->assertSame(1, $data['sampled']);
    }

    /**
     * Keys are structural. Values are a customer's words.
     *
     * Reach here is `automations.view` plus the module's tab, which is not
     * permission to read one branch's mail. A sample value would be far more
     * useful and is exactly what must not cross this line.
     */
    public function test_it_returns_no_values_only_field_names(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->recordEmailEvent($t);

        $body = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=email.received')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Missed call from', $body);
        $this->assertStringNotContainsString('notify@ringcentral.com', $body);
        $this->assertStringNotContainsString('office@example.test', $body);
    }

    public function test_a_trigger_whose_tab_you_cannot_open_is_refused(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->recordEmailEvent($t);

        // Can build rules, cannot open Email. Asking by name must not report
        // what an inbox event carries.
        $outsider = $this->userWithRole($t, 'Rules only', ['automations.view']);

        $this->signedInAs($outsider)
            ->getJson('/api/v1/automations/trigger-fields?trigger=email.received')
            ->assertForbidden();
    }

    public function test_a_trigger_nothing_declares_is_refused_rather_than_answered_empty(): void
    {
        $t = $this->provisionTenant('Alpha');

        // An empty field list and "no such trigger" are different answers, and
        // returning the first for the second is how a typo looks like a trigger
        // that carries nothing.
        $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=email.recieved')
            ->assertStatus(422);
    }

    public function test_a_declared_trigger_that_has_never_fired_says_so(): void
    {
        $t = $this->provisionTenant('Alpha');

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=email.received')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $data['fields']);
        // `sampled` is what lets the builder say "this has not happened yet"
        // instead of "this event carries nothing".
        $this->assertSame(0, $data['sampled']);
    }

    private function recordEmailEvent(array $t): void
    {
        $this->inTenant($t['org'], fn () => AutomationEvent::create([
            'organization_id' => $t['org']->id,
            'event_key' => 'email.received',
            // Exactly the shape MailboxWatcher::payload() writes: flat, because
            // an arrival is not an update and has no `before`.
            'payload' => [
                'address' => 'office@example.test',
                'provider' => 'gmail',
                'subject' => 'Missed call from (954) 555-0111',
                'from_name' => 'RingCentral',
                'from_address' => 'notify@ringcentral.com',
                'has_attachments' => false,
            ],
            'occurred_at' => now(),
            'available_at' => now(),
        ]));
    }
}
