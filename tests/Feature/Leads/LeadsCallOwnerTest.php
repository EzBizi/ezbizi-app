<?php

namespace Tests\Feature\Leads;

use App\Models\Lead;
use App\Models\LeadConversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-10-16: John Catalano's message lead held another woman's call. */
class LeadsCallOwnerTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_different_callers_call_moves_to_its_own_lead_once_and_stays(): void
    {
        $t = $this->provisionTenant('A1-Group');
        config()->set('ezbizi.leads_ai_grok', true);
        config()->set('services.grok_cli.home', '/var/lib/ezbizi-grok');
        Process::fake(['*' => Process::result(json_encode(['structuredOutput' => ['same' => 'different', 'reason' => 'appliance repair at a Berwyn rental, not a chimney in Aurora']]))]);

        [$lead, $call] = $this->inTenant($t['org'], function () use ($t) {
            $lead = Lead::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES,
                'remote_id' => '344772988', 'lead_type' => 'MESSAGE', 'contact_name' => 'John Catalano', 'status' => Lead::STATUS_NEW, 'received_at' => now()->subDays(8)]);
            LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => '3402471103',
                'channel' => 'MESSAGE', 'participant' => 'CONSUMER', 'text' => 'Chimney sweep at 2473 Lakeside Dr, Aurora', 'occurred_at' => now()->subDays(8)]);
            $call = LeadConversation::create(['organization_id' => $t['org']->id, 'lead_id' => $lead->id, 'remote_id' => '3405794171',
                'channel' => 'PHONE_CALL', 'participant' => 'CONSUMER', 'duration_ms' => 348000, 'occurred_at' => now()->subHour(),
                'transcript' => 'Your technician missed the appliance repair at my tenant\'s place in Berwyn.']);

            return [$lead, $call];
        });

        $this->artisan('ezbizi:leads-call-owner')->assertSuccessful();
        $this->artisan('ezbizi:leads-call-owner')->assertSuccessful();   // asked once

        $this->inTenant($t['org'], function () use ($lead, $call) {
            $moved = $call->refresh()->lead;
            $this->assertNotSame($lead->id, $moved->id);
            $this->assertSame(['PHONE_CALL', 'call_3405794171'], [$moved->lead_type, $moved->remote_id]);
            $this->assertStringContainsString('Berwyn', (string) $moved->note);
            $this->assertSame(1, $lead->conversations()->count(), 'the messages stay with John');
            $this->assertSame(2, Lead::query()->count());
        });
        Process::assertRanTimes(fn () => true, 1);
    }
}
