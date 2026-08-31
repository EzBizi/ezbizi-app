<?php

namespace App\Support\Dashboard\Glances;

use App\Models\User;
use App\Models\Vehicle;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Fleet\VehicleStatus;
use App\Support\Fleet\VehicleVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fleet Management box: pie of VehicleStatus under VehicleVisibility (snapshot).
 *
 * Empty-at-zero once any vehicle exists so the board does not reorder as vans
 * move. Service / insurance due stay sentences. Money stays off this box —
 * financials live on the vehicle pane.
 */
final class FleetGlance extends DescribedGlance
{
    protected const KEY = 'fleet_management';
    protected const LABEL = 'Fleet Management';
    protected const PERMISSION = 'fleet_management.view';
    protected const PRESENTATIONS = ['pie', 'bar', 'text'];
    protected const DEFAULT_PRESENTATION = 'pie';
    protected const SNAPSHOT = true;

    public function resolve(User $user, DashboardPeriod $period, array $options): GlancePayload
    {
        return app(TenantContext::class)->run($user->organization, function () use ($user) {
            $scope = ViewScope::forUser($user);
            $base = VehicleVisibility::constrain(Vehicle::query(), $user, $scope);

            $counts = (clone $base)
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

            $data = [
                'empty' => false,
                'slices' => $slices,
            ];

            $sentences = [];
            $serviceDue = $this->serviceDueCount(clone $base);
            if ($serviceDue > 0) {
                $sentences[] = $serviceDue === 1
                    ? '1 vehicle needs service soon.'
                    : "{$serviceDue} vehicles need service soon.";
            }

            $insuranceDue = $this->insuranceDueCount(clone $base);
            if ($insuranceDue > 0) {
                $sentences[] = $insuranceDue === 1
                    ? '1 vehicle has insurance due soon.'
                    : "{$insuranceDue} vehicles have insurance due soon.";
            }

            if ($sentences !== []) {
                $data['sentences'] = $sentences;
            }

            return new GlancePayload(
                key: $this->key(),
                label: $this->label(),
                presentation: $this->defaultPresentation(),
                allowedPresentations: $this->allowedPresentations(),
                snapshot: $this->snapshot(),
                comingSoon: false,
                options: [],
                data: $data,
            );
        });
    }

    private function serviceDueCount(Builder $query): int
    {
        $within = now()->addDays(30)->toDateString();

        return (int) $query
            ->whereNotIn('status', [VehicleStatus::Sold, VehicleStatus::Returned])
            ->where(function (Builder $inner) use ($within): void {
                $inner->where(function (Builder $date) use ($within): void {
                    $date->whereNotNull('next_service_on')
                        ->whereDate('next_service_on', '<=', $within);
                })->orWhere(function (Builder $miles): void {
                    $miles->whereNotNull('next_service_miles')
                        ->whereNotNull('odometer_miles')
                        ->whereRaw('odometer_miles >= next_service_miles - 500');
                });
            })
            ->count();
    }

    private function insuranceDueCount(Builder $query): int
    {
        $within = now()->addDays(30)->toDateString();

        return (int) $query
            ->whereNotIn('status', [VehicleStatus::Sold, VehicleStatus::Returned])
            ->whereHas('insurancePolicies', function (Builder $policy) use ($within): void {
                $policy->whereNull('ended_on')
                    ->whereNotNull('expires_on')
                    ->whereDate('expires_on', '<=', $within);
            })
            ->count();
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
