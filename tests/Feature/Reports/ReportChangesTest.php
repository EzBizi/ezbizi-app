<?php

namespace Tests\Feature\Reports;

use App\Jobs\SendWhatsAppPeriodReports;
use App\Models\WhatsAppReportBatch;
use App\Models\WorkizJob;
use App\Support\Reports\Drafts\ReportChanges;
use App\Support\Reports\Texts\PeriodReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** A report draft takes a fixed set of corrections, each kept with who made it (2026-10-20). */
class ReportChangesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_excluded_jobs_leave_every_figure_and_a_goal_can_be_this_reports_own(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];
        $corrector = $this->userWithRole($t, 'Bookkeeper', ['reports.view', 'reports.correct']);

        $batch = $this->inTenant($t['org'], function () use ($f) {
            foreach ([['JOB-1', 'A1', 400], ['JOB-2', 'A2', 600]] as [$id, $serial, $price]) {
                WorkizJob::query()->create(['franchise_id' => $f->id, 'remote_id' => $id, 'job_source' => 'GMB', 'status' => 'Done',
                    'booked_at' => '2026-09-22 09:00:00', 'last_status_update' => '2026-09-23 09:00:00',
                    'payload' => ['SerialId' => $serial, 'JobTotalPrice' => $price], 'synced_at' => now()]);
            }

            return WhatsAppReportBatch::query()->create(['period' => 'week', 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-27']);
        });

        $html = fn () => app(PeriodReport::class)->withOptions((array) $batch->fresh()->settings)->html($f, '2026-09-21', '2026-09-27');
        $this->assertStringContainsString('$1,000.00', $html());

        $this->inTenant($t['org'], fn () => app(ReportChanges::class)->apply($batch, 'report.exclude_jobs', ['jobs' => ['A2']], $corrector));
        $this->assertStringNotContainsString('$1,000.00', $html());
        $this->assertStringContainsString('<td>$400.00</td><td>$400.00</td>', preg_replace('#</?span[^>]*>#', '', $html()));

        $this->inTenant($t['org'], fn () => app(ReportChanges::class)->apply($batch, 'report.goal', ['key' => 'avg_sale', 'value' => 500, 'branch' => $f->id], $corrector));
        $this->assertStringContainsString('#DC2626;font-weight:700">$400.00', $html(), "this report's own goal colours it");

        $changes = $batch->fresh()->settings['changes'];
        $this->assertSame(['report.exclude_jobs', 'report.goal'], array_column($changes, 'key'));
        $this->assertSame($corrector->id, $changes[0]['by']);
        Queue::assertPushed(SendWhatsAppPeriodReports::class, 2);
    }

    public function test_an_unknown_change_a_non_corrector_and_an_approved_report_are_refused(): void
    {
        Queue::fake();
        $t = $this->provisionTenant('Alpha');
        $corrector = $this->userWithRole($t, 'Bookkeeper', ['reports.view', 'reports.correct']);
        $viewer = $this->userWithRole($t, 'Viewer', ['reports.view']);
        $batch = $this->inTenant($t['org'], fn () => WhatsAppReportBatch::query()->create(['period' => 'week', 'starts_on' => '2026-09-21', 'ends_on' => '2026-09-27']));

        $refused = function (callable $fn) use ($t) {
            try {
                $this->inTenant($t['org'], $fn);
                $this->fail('expected a refusal');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        };

        $refused(fn () => app(ReportChanges::class)->apply($batch, 'report.set_profit', ['value' => 1], $corrector));
        $refused(fn () => app(ReportChanges::class)->apply($batch, 'report.exclude_jobs', ['jobs' => ['A1']], $viewer));
        $this->inTenant($t['org'], fn () => $batch->forceFill(['approved_at' => now()])->save());
        $refused(fn () => app(ReportChanges::class)->apply($batch, 'report.exclude_jobs', ['jobs' => ['A1']], $corrector));
        Queue::assertNothingPushed();
    }
}
