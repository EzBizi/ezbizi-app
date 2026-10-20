<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\Reports\Texts\DailyTexts;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Prints one of the daily or weekly report texts (DailyTexts); sends nothing. */
class ReportTextPreview extends Command
{
    protected $signature = 'ezbizi:report-text {kind : ppc, marketing_ppc, dispatchers, technician_sales, weekly_losses or weekly_reviews}
        {--organization= : the organization id}
        {--date= : Y-m-d, default today in the app timezone}';

    protected $description = 'Print a daily or weekly report text, to check it before a rule sends it';

    public function handle(DailyTexts $texts, TenantContext $tenant): int
    {
        if (! isset(DailyTexts::KINDS[$this->argument('kind')])) {
            $this->error('Kinds: '.implode(', ', array_keys(DailyTexts::KINDS)));

            return self::FAILURE;
        }

        $organization = Organization::query()->find($this->option('organization'));
        if ($organization === null) {
            $this->error('Which organization? Pass --organization.');

            return self::FAILURE;
        }

        $day = CarbonImmutable::parse($this->option('date') ?? 'today', (string) config('app.timezone'));
        $this->line($tenant->run($organization, fn () => $texts->render($this->argument('kind'), $organization, $day)));

        return self::SUCCESS;
    }
}
