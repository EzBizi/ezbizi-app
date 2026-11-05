<?php

namespace App\Support\Dashboard\Glances;

use App\Models\User;
use App\Models\Vehicle;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\DescribedGlance;
use App\Support\Dashboard\GlancePayload;
use App\Support\Fleet\VehicleStatus;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fleet Management box: pie of VehicleStatus under InventoryVisibility (snapshot).
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
            $base = InventoryVisibility::constrain(Vehicle::query(), $user, $scope);

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
                        'sentence' => 'No vehicles to show.',
                    ],
                );
            }

            $slices = array_map(fn (string $status) => [
                'label' => $this->statusLabel($status),
                'total' => (int) ($counts[$status] ?? 0),
            ], array_column(VehicleStatus::cases(), 'value'));

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

            // Snapshots, so no previous. Counts of vehicles only: what the
            // insurance costs stays on the vehicle pane.
            $data['figures'] = [
                GlancePayload::figure('vehicles', 'vehicles', $total),
                GlancePayload::figure('service_due', 'need service soon', $serviceDue),
                GlancePayload::figure('insurance_due', 'insurance due soon', $insuranceDue),
            ];

            // What needs somebody (Dashboard, 2026-10-05): overdue is bad,
            // inside thirty days is caution, overdue first. Counts of vehicles
            // only — never what anything costs. The "soon" figures above
            // include the overdue ones, so each caution row is the difference,
            // and a vehicle is never counted as both.
            $serviceOverdue = $this->serviceOverdueCount(clone $base);
            $insuranceExpired = $this->insuranceExpiredCount(clone $base);
            $serviceSoon = $serviceDue - $serviceOverdue;
            $insuranceSoon = $insuranceDue - $insuranceExpired;

            $attention = array_values(array_filter([
                $serviceOverdue === 0 ? null : GlancePayload::attention('bad', $serviceOverdue === 1
                    ? '1 vehicle is overdue for service.'
                    : "{$serviceOverdue} vehicles are overdue for service."),
                $insuranceExpired === 0 ? null : GlancePayload::attention('bad', $insuranceExpired === 1
                    ? '1 vehicle has expired insurance.'
                    : "{$insuranceExpired} vehicles have expired insurance."),
                $serviceSoon === 0 ? null : GlancePayload::attention('caution', $serviceSoon === 1
                    ? '1 vehicle needs service within 30 days.'
                    : "{$serviceSoon} vehicles need service within 30 days."),
                $insuranceSoon === 0 ? null : GlancePayload::attention('caution', $insuranceSoon === 1
                    ? '1 vehicle has insurance expiring within 30 days.'
                    : "{$insuranceSoon} vehicles have insurance expiring within 30 days."),
            ]));

            if ($attention !== []) {
                $data['attention'] = array_slice($attention, 0, 3);
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

    /**
     * Past due: the day has gone, or the odometer has reached the mileage.
     * Every vehicle here is also in serviceDueCount, whose window starts
     * before today and whose mileage margin starts 500 earlier.
     */
    private function serviceOverdueCount(Builder $query): int
    {
        $today = now()->toDateString();

        return (int) $query
            ->whereNotIn('status', [VehicleStatus::Sold, VehicleStatus::Returned])
            ->where(function (Builder $inner) use ($today): void {
                $inner->where(function (Builder $date) use ($today): void {
                    $date->whereNotNull('next_service_on')
                        ->whereDate('next_service_on', '<', $today);
                })->orWhere(function (Builder $miles): void {
                    $miles->whereNotNull('next_service_miles')
                        ->whereNotNull('odometer_miles')
                        ->whereRaw('odometer_miles >= next_service_miles');
                });
            })
            ->count();
    }

    /**
     * The open policy's expiry has passed and nothing has replaced it, so the
     * vehicle is uninsured on paper. A subset of insuranceDueCount.
     */
    private function insuranceExpiredCount(Builder $query): int
    {
        $today = now()->toDateString();

        return (int) $query
            ->whereNotIn('status', [VehicleStatus::Sold, VehicleStatus::Returned])
            ->whereHas('insurancePolicies', function (Builder $policy) use ($today): void {
                $policy->whereNull('ended_on')
                    ->whereNotNull('expires_on')
                    ->whereDate('expires_on', '<', $today);
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
