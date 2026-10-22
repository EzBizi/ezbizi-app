<?php

namespace Tests\Feature\Financials;

use App\Models\AirtableRecord;
use App\Models\AuditEvent;
use App\Models\FinancialAdjustment;
use App\Models\FinancialSource;
use App\Support\Ai\AppAct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class FinancialAdjustmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_refund_recorded_from_chat_after_yes_lands_in_the_refunds_figures_audited_twice(): void
    {
        $t = $this->provisionTenant('Alpha');
        $staff = $this->userWithRole($t, 'Office', ['financials.view', 'financials.bases.view', 'financials.edit', 'ai_assistance.command']);

        $plan = ['method' => 'POST', 'path' => 'financials/adjustments', 'summary' => 'Refund $45.50 for Ann',
            'body' => ['place' => 'Alpha F1', 'kind' => 'refund', 'date' => '2026-10-06', 'technician' => 'Ann', 'amount' => 45.5, 'job_number' => 'J-9', 'reason' => 'Came back twice']];
        $this->assertTrue(AppAct::writable($plan['method'], $plan['path'], $plan['body']));
        $this->assertFalse(AppAct::writable('POST', 'financials/adjustments', ['cents' => 1]), 'cents key');
        $this->assertStringContainsString('POST financials/adjustments {place, kind,', AppAct::catalogue());

        $result = $this->inTenant($t['org'], fn () => AppAct::run($staff->fresh(), $plan));
        $this->assertTrue($result['ok'], $result['message']);

        $this->inTenant($t['org'], function () {
            $this->assertSame(4550, FinancialAdjustment::query()->sole()->cents);
            $this->assertSame(1, AuditEvent::query()->where('action', 'financials.adjustment_created')->count());
            $this->assertSame(1, AuditEvent::query()->where('action', 'staff_bot.command')->count());
        });

        $refunds = $this->signedInAs($staff)->getJson('/api/v1/financials/refunds?from=2026-10-05&to=2026-10-11')->assertOk()->json('data');
        $this->assertSame(['Alpha F1', 'refund', 4550], [$refunds[0]['place'], $refunds[0]['kind'], $refunds[0]['total_cents']]);
        $this->assertSame('ezbizi', $refunds[0]['items'][0]['source']);

        // Without the edit tick, the same command is refused by the route.
        $viewer = $this->userWithRole($t, 'Viewer', ['financials.view', 'financials.bases.view', 'ai_assistance.command']);
        $this->assertFalse($this->inTenant($t['org'], fn () => AppAct::run($viewer->fresh(), $plan))['ok'], 'viewer run');
        $this->inTenant($t['org'], fn () => $this->assertSame(1, FinancialAdjustment::query()->count()));

        $this->signedInAs($staff)->postJson('/api/v1/financials/adjustments', ['place' => 'Nowhere'] + $plan['body'])->assertStatus(422);
        $this->signedInAs($staff)->postJson('/api/v1/financials/adjustments', ['amount' => -5] + $plan['body'])->assertStatus(422);
    }

    public function test_a_repair_move_shifts_cents_between_technicians_and_never_below_zero(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], function () use ($t) {
            $row = fn (string $id, array $f) => AirtableRecord::query()->create(['organization_id' => $t['org']->id, 'base_id' => 'appA', 'table_id' => 'tblRep',
                'table_name' => 'NJ', 'record_id' => $id, 'fields' => $f, 'fields_hash' => md5(json_encode($f)), 'fetched_at' => now()]);
            $row('r1', ['Date' => '2026-10-06', 'Team' => 'A', 'Record type' => 'Job', 'Technician' => 'Ann', 'Job Number' => 1]);
            $row('r2', ['Date' => '2026-10-06', 'Team' => 'A', 'Record type' => 'Job', 'Technician' => 'Bob', 'Job Number' => 2]);
            $row('r3', ['Date' => '2026-10-11', 'Team' => 'A', 'Record type' => 'Weekly Summary', 'Employee Cost' => 200]);
            FinancialSource::create(['base_id' => 'appA', 'table_id' => 'tblRep', 'role' => 'repair', 'franchise_id' => $t['franchise']->id, 'company_id' => $t['company']->id]);
        });
        $as = $this->signedInAs($t['admin']);
        $move = ['franchise_id' => $t['franchise']->id, 'kind' => 'repair_move', 'date' => '2026-10-08', 'team' => 'A', 'from_technician' => 'Bob', 'technician' => 'Ann', 'amount' => 25, 'reason' => 'Ann drove'];
        $id = $as->postJson('/api/v1/financials/adjustments', $move)->assertCreated()->json('data.id');

        $team = $as->getJson('/api/v1/financials/repair-split?from=2026-10-05&to=2026-10-11')->json('data.0.groups.0');
        $this->assertSame([['person' => 'Ann', 'jobs' => 1, 'cents' => 12500], ['person' => 'Bob', 'jobs' => 1, 'cents' => 7500]], $team['people']);
        $this->assertSame(20000, array_sum(array_column($team['people'], 'cents')));

        $as->patchJson("/api/v1/financials/adjustments/{$id}", ['amount' => 150])->assertOk();
        $team = $as->getJson('/api/v1/financials/repair-split?from=2026-10-05&to=2026-10-11')->json('data.0.groups.0');
        $this->assertSame('unavailable', $team['status']);
        $this->assertSame('A recorded move takes more from Bob than their share.', $team['reason']);
    }

    /** The search bar: `q` narrows by technician, job number or reason; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $as = $this->signedInAs($t['admin']);
        foreach ([['Ann', 'J-9', 'Came back twice'], ['Marisol', 'J-77', 'Wrong cap size']] as [$tech, $job, $reason]) {
            $as->postJson('/api/v1/financials/adjustments', ['place' => 'Alpha F1', 'kind' => 'refund', 'date' => '2026-10-06',
                'technician' => $tech, 'amount' => 10, 'job_number' => $job, 'reason' => $reason])->assertCreated();
        }

        $url = '/api/v1/financials/adjustments?from=2026-10-01&to=2026-10-31';
        $as->getJson($url.'&q=marisol')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.job_number', 'J-77');
        $as->getJson($url.'&q=came%20back')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.technician', 'Ann');
        $as->getJson($url.'&q=')->assertOk()->assertJsonCount(2, 'data');
        $as->getJson($url)->assertOk()->assertJsonCount(2, 'data');
    }
}
