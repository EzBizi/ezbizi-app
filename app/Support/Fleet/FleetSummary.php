<?php

namespace App\Support\Fleet;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehiclePayment;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use App\Support\Time\ViewTimezone;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fleet's General tab (Mia, 2026-10-02: "same thing for Fleet Management" as
 * Inventory's dashboard). Same three blocks as InventorySummary - health,
 * warnings, and a series over the period - so the two tabs read alike.
 *
 * Every query starts from InventoryVisibility, so nothing is counted from a
 * company the caller cannot reach. Money (`spending`) is computed only for a
 * caller holding `fleet_management.financials`: an aggregate over payments is
 * still an answer about payments.
 *
 * "Soon" is 30 days and 500 miles, the same window the list's Due soon chip
 * and the Dashboard box use.
 */
final class FleetSummary
{
    public const SOON_DAYS = 30;

    public const SOON_MILES = 500;

    /** @return array<string, mixed> */
    public static function health(User $user, ViewScope $scope): array
    {
        $onRoad = self::onRoad($user, $scope);

        $byStatus = (clone $onRoad)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $total = (int) $byStatus->sum();

        if ($total === 0) {
            return ['empty' => true, 'sentence' => 'No vehicles to show.'];
        }

        $owned = (clone $onRoad)->whereHas('ownershipPeriods', fn (Builder $p) => $p
            ->whereNull('ended_on')->where('kind', 'owned'))->count();
        $rented = (clone $onRoad)->whereHas('ownershipPeriods', fn (Builder $p) => $p
            ->whereNull('ended_on')->where('kind', 'rented'))->count();

        return [
            'empty' => false,
            'vehicles' => $total,
            'active' => (int) ($byStatus[VehicleStatus::Active->value] ?? 0),
            'in_shop' => (int) ($byStatus[VehicleStatus::InShop->value] ?? 0),
            'out_of_service' => (int) ($byStatus[VehicleStatus::OutOfService->value] ?? 0),
            'owned' => $owned,
            'rented' => $rented,
            'unassigned' => self::unassigned(clone $onRoad)->count(),
            // The list filter each figure opens, in the keys the Fleet screen's
            // own chips use. A figure with no entry is drawn as text.
            'links' => [
                'vehicles' => '',
                'active' => 'status=active',
                'in_shop' => 'status=in_shop',
                'out_of_service' => 'status=out_of_service',
                'owned' => 'ownership=owned',
                'rented' => 'ownership=rented',
                'unassigned' => 'unassigned=1',
            ],
        ];
    }

    /**
     * What needs attention, in a fixed order: what has already lapsed first.
     * A warning with nothing in it is not sent.
     *
     * @return list<array{key:string,count:int,sentence:string,link?:string}>
     */
    public static function warnings(User $user, ViewScope $scope): array
    {
        $today = Carbon::now(ViewTimezone::forUser($user))->toDateString();
        $soon = Carbon::now(ViewTimezone::forUser($user))->addDays(self::SOON_DAYS)->toDateString();
        $onRoad = fn () => self::onRoad($user, $scope);

        $serviceOverdue = $onRoad()->where(fn (Builder $q) => $q
            ->where(fn (Builder $d) => $d->whereNotNull('next_service_on')->whereDate('next_service_on', '<', $today))
            ->orWhere(fn (Builder $m) => $m->whereNotNull('next_service_miles')->whereNotNull('odometer_miles')
                ->whereColumn('odometer_miles', '>=', 'next_service_miles')))
            ->count();

        // Due soon, the list's own definition, minus what is already overdue.
        $serviceDue = $onRoad()->where(fn (Builder $q) => $q
            ->where(fn (Builder $d) => $d->whereNotNull('next_service_on')->whereDate('next_service_on', '<=', $soon))
            ->orWhere(fn (Builder $m) => $m->whereNotNull('next_service_miles')->whereNotNull('odometer_miles')
                ->whereRaw('odometer_miles >= next_service_miles - ?', [self::SOON_MILES])))
            ->count() - $serviceOverdue;

        $openPolicy = fn (Builder $p) => $p->whereNull('ended_on');

        $insuranceExpired = $onRoad()->whereHas('insurancePolicies', fn (Builder $p) => $openPolicy($p)
            ->whereNotNull('expires_on')->whereDate('expires_on', '<', $today))->count();

        $insuranceExpiring = $onRoad()->whereHas('insurancePolicies', fn (Builder $p) => $openPolicy($p)
            ->whereNotNull('expires_on')->whereDate('expires_on', '>=', $today)->whereDate('expires_on', '<=', $soon))->count();

        $uninsured = $onRoad()->whereDoesntHave('insurancePolicies', $openPolicy)->count();

        $outOfService = $onRoad()->where('status', VehicleStatus::OutOfService->value)->count();

        $unassignedActive = self::unassigned($onRoad()->where('status', VehicleStatus::Active->value))->count();

        $rows = [
            ['service_overdue', $serviceOverdue, 'is past its service date or mileage', 'are past their service date or mileage', 'due_soon=1'],
            ['insurance_expired', $insuranceExpired, 'has insurance that has expired', 'have insurance that has expired', null],
            ['service_due_soon', $serviceDue, 'needs service within 30 days', 'need service within 30 days', 'due_soon=1'],
            ['insurance_expiring', $insuranceExpiring, 'has insurance expiring within 30 days', 'have insurance expiring within 30 days', null],
            ['no_insurance', $uninsured, 'has no insurance on file', 'have no insurance on file', null],
            ['out_of_service', $outOfService, 'is out of service', 'are out of service', 'status=out_of_service'],
            ['active_unassigned', $unassignedActive, 'is in use with nobody holding the keys', 'are in use with nobody holding the keys', 'unassigned=1'],
        ];

        $out = [];

        foreach ($rows as [$key, $count, $one, $many, $link]) {
            if ($count <= 0) {
                continue;
            }

            $row = [
                'key' => $key,
                'count' => $count,
                'sentence' => $count === 1 ? "1 vehicle {$one}." : "{$count} vehicles {$many}.",
            ];

            if ($link !== null) {
                $row['link'] = $link;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * Payments over the period: a total, by category, and a series bucketed by
     * DashboardPeriod in the view's timezone. Only for `financials`.
     *
     * @return array<string, mixed>
     */
    public static function spending(User $user, ViewScope $scope, DashboardPeriod $period): array
    {
        $visible = InventoryVisibility::constrain(Vehicle::query(), $user, $scope)->select('vehicles.id');

        $payments = VehiclePayment::query()
            ->whereIn('vehicle_id', $visible)
            ->whereBetween('occurred_on', [$period->startsOn->toDateString(), $period->endsOn->toDateString()])
            ->get(['category', 'amount_cents', 'occurred_on']);

        $buckets = array_keys($period->emptyBuckets());
        $slot = array_flip($buckets);
        $points = array_fill(0, count($buckets), 0);
        $byCategory = [];

        foreach ($payments as $payment) {
            $category = $payment->category instanceof VehiclePaymentCategory
                ? $payment->category->value
                : (string) $payment->category;
            $byCategory[$category] = ($byCategory[$category] ?? 0) + (int) $payment->amount_cents;

            $bucket = $period->bucket(Carbon::parse($payment->occurred_on->toDateString(), $period->timezone));
            if (isset($slot[$bucket])) {
                $points[$slot[$bucket]] += (int) $payment->amount_cents;
            }
        }

        arsort($byCategory);

        return [
            'period' => $period->key,
            'currency' => 'USD',
            'total_minor' => array_sum($byCategory),
            'categories' => array_map(fn (string $key, int $amount) => [
                'key' => $key,
                'label' => ucfirst($key),
                'amount_minor' => $amount,
            ], array_keys($byCategory), array_values($byCategory)),
            'buckets' => $buckets,
            'points' => $points,
        ];
    }

    /** In the register: not sold, not returned. */
    private static function onRoad(User $user, ViewScope $scope): Builder
    {
        return InventoryVisibility::constrain(Vehicle::query(), $user, $scope)
            ->whereIn('status', [
                VehicleStatus::Active->value,
                VehicleStatus::InShop->value,
                VehicleStatus::OutOfService->value,
            ]);
    }

    private static function unassigned(Builder $query): Builder
    {
        return $query->whereDoesntHave('assignments', fn (Builder $a) => $a->whereNull('released_at'));
    }
}
