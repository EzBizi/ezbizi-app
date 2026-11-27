<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Models\Organization;
use App\Support\Jobs\SourceFromDialed;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Every 15 minutes, each active "source from the line dialed" rule looks at the
 * last 2 hours of jobs (desk for Jose, 2026-11-17: Reports are read during the
 * day). The rule's own daily run still sweeps 48 hours as the safety net.
 */
class SourceFromDialedNow extends Command
{
    protected $signature = 'ezbizi:jobs-source-from-dialed {--hours=2}';

    protected $description = 'Set job sources from the line dialed for recent jobs, for every active rule that does it.';

    public function handle(TenantContext $tenant, SourceFromDialed $sources): int
    {
        foreach (Automation::query()->withoutGlobalScopes()->where('is_active', true)->get() as $rule) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($rule->organization_id), function () use ($rule, $sources) {
                if (! collect((array) $rule->activeVersion?->actions)->contains('type', 'job.source_from_dialed')) {
                    return;
                }
                [$changed, $refused] = $sources->apply($rule, (int) $this->option('hours'));
                $this->line("{$rule->name}: {$changed} changed".($refused === [] ? '' : '; '.implode('; ', $refused)));
            });
        }

        return self::SUCCESS;
    }
}
