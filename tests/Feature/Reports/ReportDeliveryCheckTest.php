<?php

namespace Tests\Feature\Reports;

use App\Models\Automation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** 2026-11-09: 93 of 107 report texts sat undelivered for five days and nothing said so. */
class ReportDeliveryCheckTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_report_text_undelivered_after_30_minutes_is_mailed_once_to_its_rules_maker(): void
    {
        // Mail::raw is not a mailable, so Mail::fake cannot count it: use the array transport.
        config(['mail.default' => 'array']);
        $t = $this->provisionTenant();
        $rule = $this->inTenant($t['org'], fn () => Automation::create(['organization_id' => $t['org']->id, 'name' => 'WhatsApp: Revenue collected',
            'trigger_key' => 'automation.schedule_reached', 'is_active' => true, 'created_by' => $t['admin']->id]));
        $key = fn (string $who) => "report.text:{$rule->id}:revenue_collected:2026-11-09:{$who}";
        $this->message($t, $key($t['admin']->id), 'failed', 40, 'Re-engagement message');
        $this->message($t, $key('someone-else'), 'delivered', 40);
        $this->message($t, $key('a-third'), 'sent', 10);
        $this->message($t, null, 'failed', 40);

        $this->artisan('ezbizi:report-delivery-check')->assertSuccessful();
        $this->artisan('ezbizi:report-delivery-check')->assertSuccessful();

        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $this->assertSame($t['admin']->email, $sent[0]->getOriginalMessage()->getTo()[0]->getAddress());
        $body = $sent[0]->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('"WhatsApp: Revenue collected"', $body);
        $this->assertStringContainsString('- '.$t['admin']->name.': failed (Re-engagement message)', $body);
        $this->assertSame(1, substr_count($body, "\n- "));
    }

    private function message(array $t, ?string $key, string $status, int $minutesAgo, ?string $reason = null): void
    {
        DB::table('whatsapp_messages')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id, 'phone_number_id' => '1309270845610102',
            'direction' => 'out', 'counterparty' => '13055550101', 'status' => $status, 'status_reason' => $reason, 'kind' => 'template',
            'template_name' => 'report_statement', 'idempotency_key' => $key, 'created_at' => now()->subMinutes($minutesAgo), 'updated_at' => now()]);
    }
}
