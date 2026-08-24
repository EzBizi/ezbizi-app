<?php

namespace App\Support\Dashboard\Glances;

use App\Models\User;
use App\Models\Vehicle;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Fleet\VehicleStatus;
use App\Support\Fleet\VehicleVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;

/**
 * Fleet Management box: pie of VehicleStatus under VehicleVisibility (snapshot).
 *
 * Empty-at-zero once any vehicle exists so the board does not reorder as vans
 * move. Money stays off this box — financials live on the vehicle pane.
 */
final class FleetGlance implements Glance
{
    public function key(): string
    {
        return 'fleet_management';
    }

    public function label(): string
    {
        return 'Fleet Management';
    }

    public function permission(): string
    {
        return 'fleet_management.view';
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
            $counts = VehicleVisibility::constrain(Vehicle::query(), $user, $scope)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $total = (int) $counts->sum();

            if ($total === 0) {
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
                        'sentence' => 'No vehicles in reach.',
                    ],
                );
            }

            $slices = array_map(fn (string $status) => [
                'label' => $this->statusLabel($status),
                'total' => (int) ($counts[$status] ?? 0),
            ], VehicleStatus::values());

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
                ],
            );
        });
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'in_shop' => 'In shop',
            'out_of_service' => 'Out of service',
            'sold' => 'Sold',
            'returned' => 'Returned',
            default => $status,
        };
    }
}
