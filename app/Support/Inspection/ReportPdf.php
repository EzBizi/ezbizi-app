<?php

namespace App\Support\Inspection;

use App\Models\InspectionReport;
use App\Support\Files\HardenedPdf;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * A filed report as PDF bytes — the one place that decides how.
 *
 * ── Why this exists rather than a second copy ────────────────────
 *
 * Two callers now need the same bytes: the download endpoint, and the
 * automation action that emails a report to the customer. The three steps —
 * resolve the paper size from settings, render the frozen document to HTML,
 * hand it to the hardened renderer — are not obviously worth a class until you
 * notice that getting the FIRST one wrong is invisible.
 *
 * A copy in the action would read the registry default and produce Letter while
 * the tenant's download produced A4, and nothing would fail: both are valid
 * PDFs of the same report, differing only in a dimension nobody compares until
 * a customer prints one. `InspectionGlance` carried a copy of the reports
 * franchise filter with a docblock promising it matched the controller, and
 * that copy carried the controller's bug for as long as the promise stood.
 * Same shape, so the same answer.
 *
 * ── The paper setting is read per tenant, not once ───────────────
 *
 * `contract_pdf_paper` is a tenant setting, and the automation engine runs
 * inside `TenantContext::run`, so the resolver answers for the organization
 * whose rule is firing. With no tenant set there is no organization to ask and
 * the registry default is the honest answer rather than a guess.
 */
class ReportPdf
{
    public function __construct(
        private readonly ReportDocument $document,
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly SettingRegistry $registry,
    ) {}

    public function render(InspectionReport $report): string
    {
        return HardenedPdf::render($this->document->html($report), $this->paper());
    }

    /**
     * The tenant's paper size, or the registry default when there is no tenant.
     *
     * ponytail: reads `contract_pdf_paper`, which is named for the first module
     * that needed it. "Which paper does this organization use" is ONE fact — a
     * franchise in Ohio prints its contracts and its inspections on the same
     * letter-sized sheet — so a second setting would be a second place to
     * change it and a chance for the two to disagree. Rename the key to
     * `pdf_paper` in a settings migration when someone is next in that file;
     * until then the honest thing is to read the one that exists and say so.
     *
     * Carried here verbatim from InspectionReportsController, which is where it
     * was written and no longer where the reading happens.
     */
    public function paper(): string
    {
        $organization = $this->tenant->get();

        return (string) ($organization === null
            ? $this->registry->getOrFail('contract_pdf_paper')->default
            : $this->settings->get('contract_pdf_paper', $organization));
    }
}
