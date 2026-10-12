<?php

namespace Tests\Feature\Leads;

use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-09: Grok sorts leads and fills "the gaps, like in the LS Calls". */
class LeadsReadTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('A1-Group');
        config()->set('ezbizi.leads_ai_grok', true);
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
    }

    private function callLead(?string $name, ?string $transcript): Lead
    {
        return $this->inTenant($this->t['org'], function () use ($name, $transcript) {
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => uniqid(), 'lead_type' => 'PHONE_CALL', 'contact_name' => $name,
                'status' => Lead::STATUS_NEW, 'received_at' => now(),
            ]);
            LeadConversation::create([
                'organization_id' => $lead->organization_id, 'lead_id' => $lead->id, 'remote_id' => uniqid(),
                'channel' => LeadConversation::CHANNEL_PHONE_CALL, 'participant' => LeadConversation::FROM_CONSUMER,
                'occurred_at' => now(), 'duration_ms' => 60000, 'transcript' => $transcript,
            ]);

            return $lead;
        });
    }

    public function test_a_call_gets_its_name_and_zip_and_a_pile_once_transcribed(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'classification' => 'BOOK', 'summary' => 'Wants a chimney inspection.',
            'name' => 'Kassandra Stone', 'zip' => '19020', 'not_a_lead' => false, 'lost' => false, 'job_applicant' => false,
        ]]))]);

        $named = $this->callLead('Already Known', 'Hi this is Kassandra, zip 19020, I need an inspection');
        $blank = $this->callLead(null, 'Hi this is Kassandra Stone, zip 19020, I need an inspection');
        $waiting = $this->callLead(null, null);   // not transcribed yet

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        $this->inTenant($this->t['org'], function () use ($named, $blank, $waiting) {
            $blank->refresh();
            $this->assertSame('Kassandra Stone', $blank->contact_name);
            $this->assertSame('19020', $blank->postal_code);
            $this->assertSame('BOOK', $blank->classification);
            $this->assertSame('Already Known', $named->refresh()->contact_name, 'never overwrites what we hold');
            $this->assertSame('Kassandra Stone', $named->ai_set['name_conflict'], 'a different name is flagged for a person');
            $this->assertNull($waiting->refresh()->classification, 'read once the transcript exists');

            // The AI mark: set by AI and still AI's, until a person edits it.
            $this->assertSame(['contact_name', 'postal_code'], \App\Http\Controllers\Api\LeadsController::aiHeld($blank));
            $this->assertSame(['postal_code'], \App\Http\Controllers\Api\LeadsController::aiHeld($named), 'the ZIP only: the name was ours');
            $blank->forceFill(['contact_name' => 'Cassandra Stone'])->save();
            $this->assertSame(['postal_code'], \App\Http\Controllers\Api\LeadsController::aiHeld($blank));
            $this->assertStringContainsString('3 values set on 2 leads, 1 changed by a person (67% kept)',
                \App\Console\Commands\AiWeeklySummary::accuracy($this->t['org']->id,
                    \Carbon\CarbonImmutable::now()->subDay(), \Carbon\CarbonImmutable::now()));
        });
        $this->assertSame(2, AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.classified')->count());
    }

    public function test_spam_is_tagged_not_a_lead(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'classification' => 'ARCHIVE', 'summary' => 'Selling a domain name.', 'name' => null, 'zip' => null, 'not_a_lead' => true, 'lost' => false, 'job_applicant' => false,
        ]]))]);
        $form = $this->inTenant($this->t['org'], function () {
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_WEBSITE_FORM,
                'remote_id' => uniqid(), 'status' => Lead::STATUS_NEW, 'received_at' => now(),
            ]);
            LeadConversation::create([
                'organization_id' => $lead->organization_id, 'lead_id' => $lead->id, 'remote_id' => uniqid(),
                'channel' => 'FORM', 'participant' => LeadConversation::FROM_CONSUMER, 'occurred_at' => now(),
                'text' => 'Why should you own IllinoisRoofingCompany.com? Price: $199',
            ]);

            return $lead;
        });

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        $this->assertSame(Lead::STATUS_NOT_A_LEAD, $this->inTenant($this->t['org'], fn () => $form->refresh()->status));
    }

    public function test_a_customer_who_declined_is_lost_not_hidden_as_spam(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'classification' => 'ARCHIVE', 'summary' => 'Wanted a crown quote, then declined.', 'name' => null,
            'zip' => null, 'not_a_lead' => false, 'lost' => true, 'job_applicant' => false,
        ]]))]);
        $call = $this->callLead(null, 'How much for a new crown? ... oh that is too much, no thanks');

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        $this->assertSame(Lead::STATUS_LOST, $this->inTenant($this->t['org'], fn () => $call->refresh()->status));
    }

    /** Jose, 2026-10-09: "Job applicants should have their own tab in Leads and be marked as such". */
    public function test_a_job_applicant_gets_their_own_tab(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'classification' => 'ARCHIVE', 'summary' => 'Looking for a chimney tech job.', 'name' => 'Andrew Jackson',
            'zip' => null, 'not_a_lead' => true, 'lost' => false, 'job_applicant' => false, 'job_applicant' => true,
        ]]))]);
        // A website form, so the list shows it (a Local Services lead here has no connection).
        $call = $this->inTenant($this->t['org'], function () {
            $lead = Lead::create([
                'organization_id' => $this->t['org']->id, 'source' => Lead::SOURCE_WEBSITE_FORM,
                'remote_id' => uniqid(), 'status' => Lead::STATUS_NEW, 'received_at' => now(),
            ]);
            LeadConversation::create([
                'organization_id' => $lead->organization_id, 'lead_id' => $lead->id, 'remote_id' => uniqid(),
                'channel' => 'FORM', 'participant' => LeadConversation::FROM_CONSUMER, 'occurred_at' => now(),
                'text' => 'Hi I am applying for the technician position',
            ]);

            return $lead;
        });
        $this->assertCount(1, $this->signedInAs($this->t['admin'])->getJson('/api/v1/leads')->assertOk()->json('data'),
            'the control: listed while New');

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        $this->assertSame(Lead::STATUS_JOB_APPLICANT, $this->inTenant($this->t['org'], fn () => $call->refresh()->status));
        $this->assertSame([], $this->getJson('/api/v1/leads')->assertOk()->json('data'), 'out of every other list');
        $this->assertSame(['Andrew Jackson'], array_column(
            $this->getJson('/api/v1/leads?source=job_applicant')->assertOk()->json('data'), 'contact_name'));
    }

    public function test_nothing_is_sent_while_switched_off(): void
    {
        config()->set('ezbizi.leads_ai_grok', false);
        Process::fake();
        $this->callLead(null, 'Hi this is Kassandra');

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        Process::assertNothingRan();
    }

    /** Rodrigo's §3: a first name becomes the full name the customer gave. */
    public function test_a_first_name_is_enriched_to_the_full_name_the_customer_gave(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'classification' => 'BOOK', 'summary' => 'Wants a sweep.', 'name' => 'Nora Vale', 'name_basis' => 'self_introduction',
            'zip' => null, 'not_a_lead' => false, 'lost' => false, 'job_applicant' => false,
        ]]))]);
        $lead = $this->callLead('Nora', 'Hi, this is Nora Vale, I need a sweep');

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        $this->assertSame('Nora Vale', $this->inTenant($this->t['org'], fn () => $lead->refresh()->contact_name));
    }

    /** AI item 4: read side by side; a failed lead stays unread for the next run. */
    public function test_leads_are_read_together_and_a_failure_stays_with_its_lead(): void
    {
        Process::fake(['*' => Process::sequence()
            ->push(Process::result('', 'not logged in', 1))
            ->push(Process::result(json_encode(['structuredOutput' => [
                'classification' => 'BOOK', 'summary' => 'Wants a sweep.', 'name' => null, 'name_basis' => 'none',
                'zip' => null, 'not_a_lead' => false, 'lost' => false, 'job_applicant' => false,
            ]])))]);
        $a = $this->callLead('A', 'I need a sweep');
        $b = $this->callLead('B', 'I need a sweep');

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        $read = $this->inTenant($this->t['org'], fn () => array_filter([$a->refresh()->classification, $b->refresh()->classification]));
        $this->assertCount(1, $read, 'one read, one left for the next run');
    }
}
