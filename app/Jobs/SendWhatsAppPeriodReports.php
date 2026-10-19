<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppReportBatch;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Social\WhatsAppPeriodReports;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Build and send a period's PDFs off the webhook request: approved ($approvedBy
 * set) to every group, or a fresh preview to the approver (REFRESH, null).
 * `tries = 1`: a half-sent batch retried would send some groups twice.
 */
final class SendWhatsAppPeriodReports implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** $privateFor: a user to send each PDF to privately instead (SEPARATELY). */
    public function __construct(public readonly string $batchId, public readonly ?string $approvedBy, public readonly ?string $privateFor = null) {}

    public function handle(TenantContext $tenant, WhatsAppPeriodReports $reports): void
    {
        $batch = WhatsAppReportBatch::query()->withoutGlobalScopes()->find($this->batchId);
        $organization = $batch === null ? null : Organization::query()->withoutGlobalScopes()->find($batch->organization_id);

        if ($organization === null) {
            return;
        }

        // One PeriodReport per run: it caches the Airtable reads (Reports).
        $report = app(PeriodReport::class);

        $tenant->run($organization, function () use ($reports, $batch, $report) {
            if ($this->privateFor !== null) {
                $to = User::query()->find($this->privateFor);

                return $to === null ? 0 : $reports->sendPrivately($batch, $report, $to);
            }

            return $this->approvedBy !== null ? $reports->send($batch, $report) : $reports->preview($batch, $report);
        });
    }
}
