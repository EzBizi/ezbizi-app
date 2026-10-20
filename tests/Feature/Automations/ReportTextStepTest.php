<?php

namespace Tests\Feature\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\WhatsAppPendingReport;
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

    public function test_a_rule_holds_the_text_behind_the_ready_template_and_refuses_a_creator_without_reports(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
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
            $this->assertSame(['13055550123'], WhatsAppPendingReport::query()->pluck('counterparty')->all());
            app(ActionRunner::class)->run($step, $event, null, automation: $mine);
            $this->assertSame(1, WhatsAppPendingReport::query()->count(), 'the same day is not sent twice');

            $theirs = Automation::create(['name' => 'V', 'trigger_key' => 'automation.schedule_reached', 'is_active' => false, 'created_by' => $viewer->id]);
            $refused = app(ActionRunner::class)->run($step, $event, null, automation: $theirs);
            $this->assertFalse($refused['ok']);
            $this->assertSame(1, WhatsAppPendingReport::query()->count(), 'a creator without reports.view sends nothing');
        });
    }
}
