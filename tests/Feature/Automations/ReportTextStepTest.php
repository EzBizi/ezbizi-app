<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\WhatsAppMessage;
use App\Support\Automations\ActionRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Reports' daily texts as a rule (A1 Leads takeover, 2026-10-20). */
class ReportTextStepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_rule_sends_the_text_as_the_document_template_and_refuses_a_creator_without_reports(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
        Http::fake(['*/media' => Http::response(['id' => 'MEDIA-1']), '*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $t = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['tasks.view']);
        DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647', 'display_number' => '+19545162455',
            'created_at' => now(), 'updated_at' => now()]);

        $this->inTenant($t['org'], function () use ($t, $viewer) {
            $t['admin']->forceFill(['phone' => '+13055550123'])->save();
            $step = ['type' => 'report.text', 'kind' => 'weekly_reviews', 'user_ids' => [$t['admin']->id]];
            $event = new AutomationEvent(['event_key' => 'automation.schedule_reached', 'payload' => []]);
            $mine = Automation::create(['name' => 'Weekly reviews', 'trigger_key' => 'automation.schedule_reached', 'is_active' => false, 'created_by' => $t['admin']->id]);

            $sent = app(ActionRunner::class)->run($step, $event, null, automation: $mine);
            $this->assertTrue($sent['ok'], $sent['detail']);
            $this->assertSame([['13055550123', 'report_statement']], WhatsAppMessage::query()->get()->map(fn ($m) => [$m->counterparty, $m->template_name])->all());
            app(ActionRunner::class)->run($step, $event, null, automation: $mine);
            $this->assertSame(1, WhatsAppMessage::query()->count(), 'the same day is not sent twice');

            $theirs = Automation::create(['name' => 'V', 'trigger_key' => 'automation.schedule_reached', 'is_active' => false, 'created_by' => $viewer->id]);
            $refused = app(ActionRunner::class)->run($step, $event, null, automation: $theirs);
            $this->assertFalse($refused['ok']);
            $this->assertSame(1, WhatsAppMessage::query()->count(), 'a creator without reports.view sends nothing');
        });
    }

    public function test_a_recipient_who_cannot_see_reports_is_skipped_at_send_and_refused_on_save(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
        Http::fake(['*/media' => Http::response(['id' => 'MEDIA-1']), '*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $t = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['tasks.view']);
        DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647', 'display_number' => '+19545162455',
            'created_at' => now(), 'updated_at' => now()]);

        $this->inTenant($t['org'], function () use ($t, $viewer) {
            $viewer->forceFill(['phone' => '+13055550199'])->save();
            $step = ['type' => 'report.text', 'kind' => 'weekly_reviews', 'user_ids' => [$viewer->id]];
            $event = new AutomationEvent(['event_key' => 'automation.schedule_reached', 'payload' => []]);
            $rule = Automation::create(['name' => 'W', 'trigger_key' => 'automation.schedule_reached', 'is_active' => false, 'created_by' => $t['admin']->id]);

            $result = app(ActionRunner::class)->run($step, $event, null, automation: $rule);
            $this->assertTrue($result['skipped'] ?? false, $result['detail']);
            $this->assertSame(0, WhatsAppMessage::query()->count(), 'a recipient without reports.view gets nothing');
            $this->assertFalse(ActionRunner::mayReceiveReportText($viewer, null, null));
            $this->assertTrue(ActionRunner::mayReceiveReportText($t['admin'], null, null));
        });
    }
}
