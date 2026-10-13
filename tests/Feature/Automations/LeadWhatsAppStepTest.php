<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Booking confirmations by WhatsApp land on the enquiry, so the answer does too (Jose, 2026-10-13). */
class LeadWhatsAppStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_whatsapp_to_the_customer_of_an_enquiry_is_on_its_thread(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake', 'services.meta.whatsapp_bot_number_id' => '1309270845610102']);
        Http::fake(fn ($request) => str_contains($request->url(), 'message_templates')
            ? Http::response(['data' => [['name' => 'booking_confirmation', 'status' => 'APPROVED', 'category' => 'UTILITY', 'language' => 'en_US',
                'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}, see you {{2}}.']]]]])
            : Http::response(['messages' => [['id' => 'wamid.OUT1']]]));
        $t = $this->provisionTenant('A1-Group');
        DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647', 'display_number' => '+19545162455',
            'created_at' => now(), 'updated_at' => now()]);

        $this->inTenant($t['org'], function () use ($t) {
            $lead = Lead::create(['organization_id' => $t['org']->id, 'source' => Lead::SOURCE_LOCAL_SERVICES, 'lead_type' => 'PHONE_CALL',
                'remote_id' => 'b1', 'phone' => '+13055550123', 'status' => 'new', 'received_at' => now(), 'contact_name' => 'John']);
            $rule = Automation::create(['name' => 'Booking confirmed', 'trigger_key' => 'lead.booking_approved', 'is_active' => false, 'created_by' => $t['admin']->id]);
            $event = new AutomationEvent(['event_key' => 'lead.booking_approved', 'payload' => ['after' => ['booking_day' => 'Tuesday']]]);

            $sent = app(ActionRunner::class)->run(['type' => 'whatsapp.send', 'from' => '+19545162455', 'template' => 'booking_confirmation',
                'to_field' => 'customer:', 'parameters' => ['{{contact_name}}', '{{booking_day}}']], $event, $lead, automation: $rule);

            $this->assertTrue($sent['ok'], $sent['detail']);
            $this->assertSame(['Hi John, see you Tuesday.'], LeadConversation::query()->where('lead_id', $lead->id)->pluck('text')->all());
        });
    }
}
