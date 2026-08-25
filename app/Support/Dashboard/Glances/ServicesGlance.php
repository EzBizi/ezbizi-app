<?php

namespace App\Support\Dashboard\Glances;

use App\Models\Service;
use App\Models\User;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Scoping\ViewScope;
use App\Support\Services\ServiceVisibility;
use App\Support\Tenancy\TenantContext;

/**
 * Services box: groups as a pie (snapshot), count as a sentence.
 */
final class ServicesGlance implements Glance
{
    public function key(): string
    {
        return 'services';
    }

    public function label(): string
    {
        return 'Services';
    }

    public function permission(): string
    {
        return 'services.view';
    }

    public function allowedPresentations(): array
    {
        return ['pie', 'bar', 'text'];
    }

    public function defaultPresentation(): string
    {
        return 'pie';
    }

    public function snapshot(): bool
    {
        return true;
    }

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);
            $rows = ServiceVisibility::constrain(Service::query(), $user, $scope)
                ->with('group:id,name')
                ->get(['id', 'group_id']);

            if ($rows->isEmpty()) {
                return new GlancePayload(
                    key: $this->key(),
                    label: $this->label(),
                    presentation: $this->defaultPresentation(),
                    allowedPresentations: $this->allowedPresentations(),
                    snapshot: $this->snapshot(),
                    comingSoon: false,
                    options: [],
                    data: [
                        'empty' => true,
                        'sentence' => 'No services in reach.',
                    ],
                );
            }

            $counts = [];
            foreach ($rows as $row) {
                $name = $row->group?->name ?? 'Ungrouped';
                $counts[$name] = ($counts[$name] ?? 0) + 1;
            }

            $total = $rows->count();
            $slices = [];
            foreach ($counts as $label => $totalSlice) {
                $slices[] = ['label' => $label, 'total' => $totalSlice];
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: [
                    'empty' => false,
                    'slices' => $slices,
                    'sentences' => [
                        $total === 1 ? '1 service in reach.' : "{$total} services in reach.",
                    ],
                ],
            );
        });
    }
}
