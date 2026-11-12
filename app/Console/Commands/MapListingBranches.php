<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Support\CustomerExperience\ListingBranches;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** Files every unfiled Business Profile listing under a branch by its state (Jose, 2026-11-12). */
class MapListingBranches extends Command
{
    protected $signature = 'ezbizi:gbp-map-branches';

    protected $description = 'File Business Profile listings under a branch by state, and set each connection\'s leads branch';

    public function handle(TenantContext $tenant): int
    {
        Organization::query()->each(function (Organization $org) use ($tenant) {
            [$filed, $left] = $tenant->run($org, fn () => ListingBranches::fill());
            ($filed + $left) > 0 && $this->line("{$org->name}: {$filed} listings filed, {$left} left for a person");
        });

        return self::SUCCESS;
    }
}
