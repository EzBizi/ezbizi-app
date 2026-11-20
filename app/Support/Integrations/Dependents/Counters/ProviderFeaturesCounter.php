<?php

namespace App\Support\Integrations\Dependents\Counters;

use App\Models\User;
use App\Support\Integrations\Dependents\DependentCounter;
use App\Support\Integrations\Dependents\Feature;
use App\Support\Integrations\Dependents\IntegrationRef;

/**
 * Features of providers that have no rows of their own to count: every
 * integration is an add-on, and this is what the add-on was doing. It never
 * counts anything.
 */
final class ProviderFeaturesCounter implements DependentCounter
{
    public function count(IntegrationRef $ref, User $viewer): ?array
    {
        return null;
    }

    public function features(IntegrationRef $ref): array
    {
        return match ($ref->provider) {
            'workiz', 'workiz_web' => [
                Feature::lastBreaks($ref, 'Jobs › Workiz: jobs stop mirroring to Workiz'),
                Feature::degrades('Reports: Workiz job reports stop updating'),
            ],
            'google_ads' => [Feature::breaks('Marketing › Ads: Google Ads spend and leads stop updating')],
            'facebook_ads' => [Feature::breaks('Marketing › Ads: Facebook Ads spend and leads stop updating')],
            'thumbtack' => [Feature::breaks('Leads › Thumbtack: new Thumbtack leads stop arriving and replies cannot be sent')],
            'quickbooks' => [Feature::breaks('Financials › Markets: QuickBooks expenses stop updating; what was read stays')],
            'ringcentral' => [Feature::degrades('Reports › Calls: RingCentral call history stops updating')],
            default => [],
        };
    }
}
