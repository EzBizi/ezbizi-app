<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\WhatsAppGroup;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Social\WhatsAppPeriodReports;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ask the approver to approve last week's (or month's) PDFs on WhatsApp.
 * Only businesses with an `approval` group, and only a batch not yet approved.
 */
class WhatsAppPeriodReportsPreview extends Command
{
    protected $signature = 'ezbizi:whatsapp-period-reports {period : week or month}';

    protected $description = 'Send the weekly or monthly PDFs to the approver on WhatsApp';

    public function handle(TenantContext $tenant, WhatsAppPeriodReports $reports): int
    {
        $period = $this->argument('period') === 'month' ? 'month' : 'week';
        $report = app(PeriodReport::class);

        $organizations = Organization::query()->withoutGlobalScopes()
            ->whereIn('id', WhatsAppGroup::query()->withoutGlobalScopes()
                ->where('purpose', WhatsAppPeriodReports::APPROVAL)->select('organization_id'))
            ->get();
        // Said, not silent (audit #5, 2026-11-11): the weekly PDF goes 1:1 by the "Weekly company report" rule instead.
        if ($organizations->isEmpty()) {
            $this->warn('No organization has a report approval WhatsApp group, so no PDF was sent.');
        }

        foreach ($organizations as $organization) {
            try {
                $tenant->run($organization, function () use ($reports, $report, $period) {
                    $batch = $reports->batchFor($period);

                    // Once: a batch already asked about waits for its answer
                    // (or REFRESH) rather than being sent again every run.
                    if ($batch->wasRecentlyCreated) {
                        try {
                            $reports->preview($batch, $report);
                        } catch (Throwable $e) {
                            $batch->delete(); // so the next scheduled run asks again
                            throw $e;
                        }
                    }
                });
            } catch (Throwable $e) {
                report($e);
                $this->error($organization->name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
