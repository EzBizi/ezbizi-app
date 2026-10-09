<?php

namespace App\Support\Files;

use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;

/**
 * The paper a PDF prints on, for whoever is in context: one reader for the
 * contract copy and the inspection report. HardenedPdf::options refuses a
 * value it does not know.
 *
 * It is `contract_pdf_paper` in SettingRegistry and cascades like every other
 * setting, because two organizations on one installation disagree the moment
 * one of them is in North America. With no tenant in context (a queued job, a
 * unit test converting a document) the registry's default is the answer, so
 * "what paper, if nobody said" stays one entry.
 *
 * ponytail: the key is named for the first module that needed it. "Which paper
 * does this organization use" is ONE fact, so a second setting would be a
 * second place to change it. Rename it to `pdf_paper` in a settings migration
 * when someone is next in that file.
 */
final class PdfPaper
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly SettingsResolver $settings,
        private readonly SettingRegistry $registry,
    ) {}

    public function get(): string
    {
        $organization = $this->tenant->get();

        return (string) ($organization === null
            ? $this->registry->getOrFail('contract_pdf_paper')->default
            : $this->settings->get('contract_pdf_paper', $organization));
    }
}
