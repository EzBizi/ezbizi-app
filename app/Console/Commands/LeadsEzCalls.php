<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\PhoneNumber;
use App\Support\Leads\EzLeadCalls;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Calls and texts to EzBizi's own lead lines into Leads as EZ Calls and EZ
 * Texts (Jose, 2026-10-16). See EzLeadCalls. Once per organization that has a
 * lead line, inside it; each call and text is filed once, so the window can
 * overlap freely.
 */
class LeadsEzCalls extends Command
{
    protected $signature = 'ezbizi:leads-ez {--hours=2 : hours back to read}';

    protected $description = 'File calls and texts on EzBizi lead lines as leads, once each';

    public function handle(EzLeadCalls $calls, TenantContext $tenant): int
    {
        $since = CarbonImmutable::now()->subHours(max(1, (int) $this->option('hours')));
        $orgs = PhoneNumber::query()->withoutGlobalScopes()->where('lead_line', true)->distinct()->pluck('organization_id');

        foreach (Organization::query()->withoutGlobalScopes()->whereIn('id', $orgs)->get() as $organization) {
            $filed = $tenant->run($organization, fn () => $calls->file($since));
            $this->info("{$organization->name}: filed {$filed} EZ call(s) and text(s).");
        }

        return self::SUCCESS;
    }
}
