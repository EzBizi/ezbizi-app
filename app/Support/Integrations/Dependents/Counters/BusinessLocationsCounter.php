<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\BusinessLocation;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;
use Illuminate\Database\Eloquent\Builder;

final class BusinessLocationsCounter extends RowCounter
{
    protected function key(): string
    {
        return 'business_locations';
    }

    protected function applies(IntegrationRef $ref): bool
    {
        return $ref->kind === 'social';
    }

    protected function query(IntegrationRef $ref): Builder
    {
        return BusinessLocation::query()->where('connection_id', $ref->id);
    }

    protected function label(int $count): string
    {
        return self::plural($count, 'Business Profile location stops syncing', 'Business Profile locations stop syncing');
    }

    public function features(IntegrationRef $ref): array
    {
        return $ref->provider === 'google_business_profile'
            ? [Feature::breaks('Marketing › Reviews: Google reviews stop arriving and replies cannot be posted')]
            : [];
    }
}
