<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use ReflectionClass;
use App\Support\Automations\ActionRunner;
use App\Models\Lead;
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
    /**
     * Still true, and now worth reading carefully: the endpoint DOES return a
     * `values` map for fields with a closed set declared in code. What it must
     * never return is a value read off this tenant's records, which is what
     * every assertion below is about. Declared is not sampled.
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

    /**
     * A closed set is offered so nobody has to guess `website_chat`.
     *
     * THE ASSERTION THAT MATTERS IS website_form. No form enquiry has ever been
     * recorded - not in this test, and not in production, where the count is
     * zero against 1992 local_services and 4 website_chat. So if these values
     * were sampled off recent events rather than declared in code, the one value
     * a business most needs to build a rule for BEFORE their first form arrives
     * would be the one missing. That is the whole reason they are declared.
     */
    public function test_it_offers_the_declared_values_for_a_field_with_a_closed_set(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], fn () => AutomationEvent::create([
            'organization_id' => $t['org']->id,
            'event_key' => 'lead.received',
            'payload' => ['after' => [
                'source' => 'website_chat',
                'contact_name' => 'A caller',
                'received_at' => now()->toIso8601String(),
            ]],
            'occurred_at' => now(),
            'available_at' => now(),
        ]));

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=lead.received')
            ->assertOk()
            ->json('data');

        $this->assertContains('source', $data['fields']);

        $offered = collect($data['values']['source'] ?? [])->pluck('label', 'value')->all();

        $this->assertSame('Website live chat', $offered['website_chat'] ?? null);
        $this->assertSame('Website form', $offered['website_form'] ?? null,
            'a value nothing has ever raised must still be offered - otherwise the picker '
            .'is sampled, not declared, and a rule cannot be built before the first one arrives');

        // And a field with no closed set gets no map, rather than an empty one
        // that would draw an empty dropdown over a working text box.
        $this->assertArrayNotHasKey('contact_name', $data['values']);
    }

    /**
     * The one hand-written list is pinned to the model it describes.
     *
     * Every other set in FIELD_VALUES is an enum read at runtime, so a module
     * adding a case is offered immediately. Lead's two are string constants with
     * no enum to read, so they are typed out - and a typed-out list is exactly
     * the half-complete picker the docblock there warns against. Leads adds a
     * source roughly every few weeks (Thumbtack 2026-09-08, website chat and
     * form 2026-09-08), so this is not hypothetical: without this, the next one
     * is silently missing from the dropdown and a rule cannot be built for it.
     */
    public function test_the_hand_written_lead_values_still_match_the_model(): void
    {
        $declared = ActionRunner::valuesOn(Lead::class);

        $constants = (new ReflectionClass(Lead::class))->getConstants();

        $expected = fn (string $prefix) => collect($constants)
            ->filter(fn ($v, $k) => str_starts_with($k, $prefix) && is_string($v))
            ->values()->sort()->values()->all();

        $this->assertSame($expected('SOURCE_'), collect($declared['source'])->keys()->sort()->values()->all(),
            'Lead has a source the automation picker does not offer, or offers one it no longer has');

        $this->assertSame($expected('STATUS_'), collect($declared['status'])->keys()->sort()->values()->all(),
            'Lead has a status the automation picker does not offer, or offers one it no longer has');
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
