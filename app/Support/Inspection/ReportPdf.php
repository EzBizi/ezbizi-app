<?php

namespace App\Support\Inspection;

use App\Models\InspectionReport;
use App\Support\Files\HardenedPdf;
use App\Support\Files\PdfPaper;

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
        private readonly PdfPaper $paper,
    ) {}

    public function render(InspectionReport $report): string
    {
        return HardenedPdf::render($this->document->html($report), $this->paper->get());
    }
}
