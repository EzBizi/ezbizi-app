<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Ownership;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Tenancy\TenantContext;

/** Company on the dashboard: how much of the ownership chart is filled in (design 175). */
final class CompanyGlance extends DescribedGlance
{
    protected const KEY = 'company';

    protected const LABEL = 'Company';

    protected const PERMISSION = 'company.view';

    protected const PRESENTATIONS = ['text'];

    protected const DEFAULT_PRESENTATION = 'text';

    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () {
            $people = User::query()->where('is_active', true)->count();
            $noManager = User::query()->where('is_active', true)->whereNull('reports_to_user_id')->count();
            $steps = Ownership::query()->where('kind', 'process')->distinct()->count('key');
            $unowned = count(Ownership::STEPS) - $steps;

            return $this->payload([
                'empty' => false,
                'sentences' => array_values(array_filter([
                    $unowned > 0 ? "{$unowned} of ".count(Ownership::STEPS).' steps have no owner.' : 'Every step has an owner.',
                    $noManager > 0 ? "{$noManager} of {$people} people have nobody they report to." : null,
                ])),
                'figures' => [
                    GlancePayload::figure('people', 'people', $people),
                    GlancePayload::figure('unowned', 'steps without an owner', $unowned),
                ],
                'attention' => $unowned > 0
                    ? [GlancePayload::attention('caution', $unowned === 1 ? '1 step has no owner' : "{$unowned} steps have no owner")]
                    : [],
            ]);
        });
    }
}
