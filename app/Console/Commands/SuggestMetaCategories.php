<?php

namespace App\Console\Commands;

use App\Models\MetaAdCampaign;
use App\Models\Organization;
use App\Models\ServiceGroup;
use App\Support\Ai\GrokCli;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Grok suggests the service category for each Facebook campaign from its name
 * (Jose, 2026-10-12: "Auto-map FB campaigns"). Writes only
 * suggested_service_group_id; a person confirming copies it into
 * service_group_id, which this never touches.
 */
class SuggestMetaCategories extends Command
{
    protected $signature = 'ezbizi:suggest-meta-categories {--limit=20}';

    protected $description = 'Suggest a service category for each Facebook campaign from its name';

    public function handle(TenantContext $tenant): int
    {
        if (! GrokCli::configured()) {
            return self::SUCCESS;
        }

        $campaigns = MetaAdCampaign::query()->withoutGlobalScopes()
            ->whereNull('suggested_at')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($campaigns->groupBy('organization_id') as $orgId => $group) {
            $tenant->run(Organization::withoutGlobalScopes()->findOrFail($orgId), function () use ($group) {
                $categories = ServiceGroup::query()->pluck('name', 'id');
                if ($categories->isEmpty()) {
                    return;
                }

                $list = $categories->map(fn ($name, $id) => "$id: $name")->implode("\n");
                $answers = GrokCli::askMany($group->map(fn ($c) => "A home-services business (chimneys, fireplaces, "
                    ."garage doors) runs this Facebook ad campaign. Pick the one service category it advertises, "
                    ."by id, or \"none\" if the name does not say.\n\nCategories:\n$list\n\nCampaign name: ".$c->name)->values()->all(),
                    [
                        'type' => 'object',
                        'properties' => ['category' => ['type' => 'string', 'enum' => [...$categories->keys()->all(), 'none']]],
                        'required' => ['category'],
                        'additionalProperties' => false,
                    ]);

                foreach ($group->values() as $n => $campaign) {
                    if ($answers[$n] instanceof RuntimeException) {
                        $this->warn('Grok: '.$answers[$n]->getMessage());

                        continue;   // asked again next run
                    }
                    $pick = $answers[$n]['category'];
                    $campaign->forceFill([
                        'suggested_service_group_id' => $categories->has($pick) ? $pick : null,
                        'suggested_at' => now(),
                    ])->save();
                }
            });
        }

        return self::SUCCESS;
    }
}
