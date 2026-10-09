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
            'name' => 'Kassandra Stone', 'zip' => '19020',
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
            $this->assertNull($waiting->refresh()->classification, 'read once the transcript exists');
        });
        $this->assertSame(2, AutomationEvent::withoutGlobalScopes()->where('event_key', 'lead.classified')->count());
    }

    public function test_spam_is_tagged_not_a_lead(): void
    {
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => [
            'classification' => 'ARCHIVE', 'summary' => 'Selling a domain name.', 'name' => null, 'zip' => null,
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

    public function test_nothing_is_sent_while_switched_off(): void
    {
        config()->set('ezbizi.leads_ai_grok', false);
        Process::fake();
        $this->callLead(null, 'Hi this is Kassandra');

        $this->artisan('ezbizi:leads-read')->assertSuccessful();

        Process::assertNothingRan();
    }
}
