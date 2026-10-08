<?php

namespace Tests\Feature\Api;

use App\Models\AutomationEvent;
use ReflectionClass;
use App\Support\Automations\ActionRunner;
use App\Models\Customer;
use App\Models\Job;
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
     * Keys are structural. Values are a customer's words — and the line moved.
     *
     * THIS TEST USED TO SAY NO VALUE MAY EVER BE RETURNED, and asserted it for
     * an administrator. Jose asked for the opposite on 2026-09-17, by name:
     * *"I should have a preview and catalog of variables, so for example if i
     * put {{title}} i should be able to see what that will turn out as"* — and
     * a preview built from invented values answers nothing, because the failure
     * it exists to show is a field that resolves to a blank.
     *
     * So the rule is no longer "never a value". It is "a value only to somebody
     * who already reaches the record", and the guard had to move with it rather
     * than be deleted: the admin case is covered below, and what is asserted
     * HERE is the half that still protects somebody — a person who may build
     * rules and may open Email, but stands at one branch.
     *
     * Asserted on the whole response body rather than on the `samples` key,
     * because a leak that arrives under a new key is still a leak, and a key
     * name is the thing most likely to change next.
     */
    public function test_somebody_who_does_not_reach_the_record_gets_names_and_no_words(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->recordEmailEvent($t);

        $atOneBranch = $this->userWithRole(
            $t, 'Branch', ['automations.view', 'automations.build.view', 'email.view'],
            email: 'branch@example.test', onlyAt: $t['franchise'],
        );

        $response = $this->signedInAs($atOneBranch)
            ->getJson('/api/v1/automations/trigger-fields?trigger=email.received')
            ->assertOk();

        // THE CONTROL: the names are offered, so this is about the words and
        // not about the endpoint refusing this person outright.
        $this->assertContains('from_address', $response->json('data.fields'));

        $body = $response->getContent();

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
        $outsider = $this->userWithRole($t, 'Rules only', ['automations.view', 'automations.build.view']);

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

    /**
     * A PREVIEW NEEDS A REAL VALUE, AND ONLY FROM A RECORD THIS PERSON REACHES.
     *
     * Jose asked for it by name: *"if i put {{title}} i should be able to see
     * what that will turn out as"*. An invented example cannot answer that — it
     * shows the braces work, not whether the field resolves, and a blank is
     * exactly the failure that goes unnoticed until a customer receives it.
     *
     * Sampling reverses the rule written above `values`, which says values are
     * declared and never read off events. That rule is right for a dropdown of
     * every allowed value and wrong for a preview, so the safety had to move
     * somewhere: the module gate decides who may know a field EXISTS, and it is
     * per-module, which on its own would hand somebody a value off a record
     * they cannot open.
     *
     * TWO VIEWERS, ONE EVENT. The administrator gets the example; the person
     * who stands at one branch does not, because an event with no subject
     * belongs to the organization and reach is asked about it rather than
     * waved through. Both see the same FIELD — what differs is the value.
     */
    public function test_an_example_value_is_only_shown_to_somebody_who_reaches_the_record(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], fn () => AutomationEvent::create([
            'organization_id' => $t['org']->id,
            'event_key'       => 'job.created',
            'payload'         => ['title' => 'Reachable sweep'],
            'occurred_at'     => now()->subMinute(),
            'available_at'    => now(),
        ]));

        $atTheTop = $this->userWithRole($t, 'Owner', ['automations.view', 'automations.build.view', 'jobs.view']);

        $atOneBranch = $this->userWithRole(
            $t, 'Branch', ['automations.view', 'automations.build.view', 'jobs.view'],
            email: 'branch@example.test', onlyAt: $t['franchise'],
        );

        $seen = $this->signedInAs($atTheTop)
            ->getJson('/api/v1/automations/trigger-fields?trigger=job.created')
            ->assertOk()->json('data');

        $narrower = $this->signedInAs($atOneBranch)
            ->getJson('/api/v1/automations/trigger-fields?trigger=job.created')
            ->assertOk()->json('data');

        $this->assertSame('Reachable sweep', $seen['samples']['title'] ?? null);

        // THE CONTROL: the field itself is offered to both, so this is about the
        // VALUE and not about the endpoint refusing the narrower person outright.
        $this->assertContains('title', $narrower['fields']);
        $this->assertArrayNotHasKey('title', $narrower['samples']);
    }

    /**
     * AN EVENT THAT ACTUALLY HAS A SUBJECT, which is the case that 500'd.
     *
     * The sample reader asked `$event->subject()` — a relation `AutomationEvent`
     * does not have. Every test here built events with no subject at all, so the
     * branch that resolves one was never entered and the endpoint answered 500
     * in production the first time a real job event reached it.
     *
     * A whole feature can be covered by tests that never construct its subject.
     * The fixtures were convenient rather than representative, and convenient
     * fixtures decide which branch runs.
     */
    public function test_an_event_carrying_a_subject_is_read_without_error(): void
    {
        $t = $this->provisionTenant('Alpha');

        // A RULE IS ITS OWN SUBJECT on the clock trigger, which makes it the
        // cheapest real subject there is — no customer, no job number, nothing
        // this test would have to invent. What matters is only that
        // `subject_type` is set and the class resolves, which is the branch
        // that was never entered.
        $rule = $this->inTenant($t['org'], fn () => \App\Models\Automation::create([
            'organization_id' => $t['org']->id,
            'name' => 'Nightly',
            'trigger_key' => 'automation.schedule_reached',
            'is_active' => true,
        ]));

        $this->inTenant($t['org'], fn () => AutomationEvent::create([
            'organization_id' => $t['org']->id,
            'event_key' => 'automation.schedule_reached',
            'subject_type' => $rule::class,
            'subject_id' => $rule->getKey(),
            'payload' => ['schedule_kind' => 'daily'],
            'occurred_at' => now(),
            'available_at' => now(),
        ]));

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=automation.schedule_reached')
            ->assertOk()
            ->json('data');

        $this->assertContains('schedule_kind', $data['fields']);
        $this->assertSame('daily', $data['samples']['schedule_kind'] ?? null);
    }

    /**
     * `{{company_name}}` worked in a lead's message and no chip offered it,
     * because chips came only from the payload and the name is read off the
     * lead. So an auto reply kept the brand typed in by hand (Mia, 2026-10-02).
     * It is offered for messages and kept out of `fields`, which a condition
     * reads and where it would always compare against nothing.
     */
    public function test_a_lead_message_is_offered_the_company_name_with_an_example(): void
    {
        $t = $this->provisionTenant('Alpha');

        $lead = $this->inTenant($t['org'], fn () => Lead::create([
            'organization_id' => $t['org']->id,
            'company_id' => $t['company']->id,
            'source' => Lead::SOURCE_WEBSITE_CHAT,
            'remote_id' => 'chat-1',
            'status' => Lead::STATUS_NEW,
            'received_at' => now(),
        ]));

        $this->inTenant($t['org'], fn () => AutomationEvent::create([
            'organization_id' => $t['org']->id,
            'event_key' => 'lead.received',
            'subject_type' => Lead::class,
            'subject_id' => $lead->getKey(),
            'payload' => ['after' => ['source' => 'website_chat', 'kind' => 'website_chat']],
            'occurred_at' => now(),
            'available_at' => now(),
        ]));

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=lead.received')
            ->assertOk()
            ->json('data');

        $this->assertContains('company_name', $data['message_fields']);
        $this->assertNotContains('company_name', $data['fields']);
        $this->assertSame($t['company']->name, $data['samples']['company_name'] ?? null);
    }

    /**
     * The job thank-you text names the company (desk for Jose, 2026-10-08).
     * A job carries only its franchise; the company is that franchise's.
     */
    public function test_a_job_message_is_offered_the_franchises_company_name(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->inTenant($t['org'], function () use ($t) {
            $customer = Customer::create(['owning_franchise_id' => $t['franchise']->id, 'number' => 'C-1', 'name' => 'Marta Quill']);
            $job = Job::create(['franchise_id' => $t['franchise']->id, 'customer_id' => $customer->id, 'number' => 'J-1', 'title' => 'Sweep',
                'status' => 'Done', 'currency' => 'USD']);

            AutomationEvent::create([
                'organization_id' => $t['org']->id,
                'event_key' => 'job.status_changed',
                'subject_type' => Job::class,
                'subject_id' => $job->getKey(),
                'payload' => ['after' => ['status' => 'Done'], 'before' => ['status' => 'Scheduled']],
                'occurred_at' => now(),
                'available_at' => now(),
            ]);
        });

        $data = $this->signedInAs($t['admin'])
            ->getJson('/api/v1/automations/trigger-fields?trigger=job.status_changed')
            ->assertOk()
            ->json('data');

        $this->assertContains('company_name', $data['message_fields']);
        $this->assertSame($this->inTenant($t['org'], fn () => $t['franchise']->company->name), $data['samples']['company_name'] ?? null);
    }
}
