<?php

namespace Tests\Feature\Reports;

use App\Jobs\SendWhatsAppPeriodReports;
use App\Models\Automation;
use App\Models\Franchise;
use App\Models\WhatsAppGroup;
use App\Models\WhatsAppPendingReport;
use App\Models\WhatsAppReportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The Reports workspace's own endpoints (takeover row 10, UI queue 157). */
class ReportWorkspaceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_text_comes_with_the_rules_that_send_it_and_stays_in_view(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $a = Automation::create(['name' => 'Morning reviews', 'trigger_key' => 'automation.schedule_reached', 'is_active' => true, 'created_by' => $t['admin']->id]);
            $a->addVersion(null, [['type' => 'report.text', 'kind' => 'weekly_reviews', 'user_ids' => [$t['admin']->id]]], $t['admin']);
            $other = Automation::create(['name' => 'PPC', 'trigger_key' => 'automation.schedule_reached', 'is_active' => true, 'created_by' => $t['admin']->id]);
            $other->addVersion(null, [['type' => 'report.text', 'kind' => 'ppc', 'user_ids' => []]], $t['admin']);
        });

        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/texts/weekly_reviews?date=2026-10-20')->assertOk()
            ->assertJsonPath('data.label', 'Weekly reviews by branch')
            ->assertJsonPath('data.rules.0.name', 'Morning reviews')
            ->assertJsonPath('data.rules.0.to', [$t['admin']->name])
            ->assertJsonCount(1, 'data.rules');
        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/texts/nonsense')->assertNotFound();

        // A branch outside the reader's view is refused.
        $other = $this->inTenant($t['org'], fn () => Franchise::query()->create(['organization_id' => $t['org']->id, 'company_id' => $t['franchise']->company_id, 'name' => 'Other', 'is_active' => true]));
        $local = $this->userWithRole($t, 'local', ['reports.view'], onlyAt: $t['franchise']);
        $this->signedInAs($local)->getJson('/api/v1/reports/texts/weekly_reviews?franchise_id='.$other->id)->assertForbidden();
        $this->signedInAs($local)->getJson('/api/v1/reports/texts/weekly_reviews?franchise_id='.$t['franchise']->id)->assertOk();
        $this->signedInAs($this->userWithRole($t, 'none', ['leads.view']))->getJson('/api/v1/reports/texts/weekly_reviews')->assertForbidden();
    }

    public function test_send_now_goes_to_each_person_who_may_see_it(): void
    {
        config(['services.meta.whatsapp_token' => 'a-token-for-the-fake']);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
        $t = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($t, 'Viewer', ['leads.view']);
        DB::table('whatsapp_numbers')->insert(['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id,
            'phone_number_id' => '1309270845610102', 'waba_id' => '1090541950244647', 'display_number' => '+19545162455',
            'created_at' => now(), 'updated_at' => now()]);
        $this->inTenant($t['org'], function () use ($t, $viewer) {
            $t['admin']->forceFill(['phone' => '+13055550123'])->save();
            $viewer->forceFill(['phone' => '+13055550199'])->save();
        });

        $this->signedInAs($t['admin'])->postJson('/api/v1/reports/texts/weekly_reviews/send', ['user_ids' => [$t['admin']->id, $viewer->id]])
            ->assertOk()->assertJsonPath('data.sent', 1);
        $this->assertSame(['13055550123'], $this->inTenant($t['org'], fn () => WhatsAppPendingReport::query()->pluck('counterparty')->all()));
    }

    public function test_the_batch_steps_and_approve_for_the_approval_group_only(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $merav = $this->userWithRole($t, 'Bookkeeper', ['reports.view']);
        $batch = $this->inTenant($t['org'], function () use ($t, $merav) {
            $group = WhatsAppGroup::create(['phone_number_id' => '1', 'purpose' => 'approval', 'subject' => 'Approval']);
            $group->members()->attach($merav->id, ['id' => (string) Str::uuid(), 'organization_id' => $t['org']->id]);

            return WhatsAppReportBatch::query()->create(['period' => 'week', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-25', 'revision' => 1]);
        });

        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/batches/week')->assertOk()
            ->assertJsonPath('data.step', 'draft')->assertJsonPath('data.can_approve', false);
        $this->signedInAs($t['admin'])->postJson("/api/v1/reports/batches/{$batch->id}/approve")->assertForbidden();

        $this->signedInAs($merav)->getJson('/api/v1/reports/batches/week')->assertJsonPath('data.can_approve', true);
        $this->signedInAs($merav)->postJson("/api/v1/reports/batches/{$batch->id}/approve")->assertOk();
        Queue::assertPushed(SendWhatsAppPeriodReports::class);
        $this->signedInAs($merav)->getJson('/api/v1/reports/batches/week')->assertJsonPath('data.step', 'sent');
        $this->signedInAs($merav)->postJson("/api/v1/reports/batches/{$batch->id}/approve")->assertStatus(422);
    }

    public function test_period_and_profitability_preview_as_json(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/period?from=2026-10-01&to=2026-10-07&format=json')->assertOk()
            ->assertJsonPath('data.sheets.Created.0', ['Branch', 'Source', 'Jobs', 'Done', 'Open', 'Canceled', 'Gross']);
        $this->signedInAs($t['admin'])->getJson('/api/v1/reports/period?from=2026-10-01&to=2026-10-07&format=json&report=job_profitability')->assertOk()
            ->assertJsonPath('data.sheets.Jobs.0.0', 'Branch');
    }
}
