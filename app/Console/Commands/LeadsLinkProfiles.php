<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\SocialConnection;
use App\Support\Leads\AccountProfileLink;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Local Services accounts to their Business Profile listing, per organization. See AccountProfileLink. */
class LeadsLinkProfiles extends Command
{
    protected $signature = 'ezbizi:leads-link-profiles';

    protected $description = 'Link Local Services accounts to their Business Profile listing, and rename one whose listing moved';

    public function handle(AccountProfileLink $link, TenantContext $tenant): int
    {
        $orgs = SocialConnection::query()->withoutGlobalScopes()->where('provider', SocialProvider::GoogleAds->value)
            ->distinct()->pluck('organization_id');
        foreach (Organization::withoutGlobalScopes()->whereIn('id', $orgs)->get() as $org) {
            [$linked, $renamed] = $tenant->run($org, fn () => $link->run());
            $this->info("{$org->name}: linked {$linked}, renamed {$renamed}.");
        }

        return self::SUCCESS;
    }
}
