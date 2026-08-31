<?php

namespace App\Support\Inventory;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which items and locations a list may show (spec §4).
 *
 * General (`franchise_id` null) is visible to anyone who can see that company
 * — including a franchise-only user looking at one of its places. Organization
 * rows (`company_id` null) are the group register: anyone with reach in the
 * tenant sees them. Tagged rows only appear when the franchise is in the
 * view’s franchise ids (already intersected with reach). Empty reach matches
 * nothing.
 */
final class InventoryVisibility
{
    public static function constrain(Builder $query, User $user, ViewScope $scope): Builder
    {
        $companyIds = self::companyIdsFor($user, $scope);
        $franchiseIds = $scope->franchiseIds();

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where(function (Builder $outer) use ($companyIds, $franchiseIds): void {
            $outer->whereNull('company_id')
                ->orWhere(function (Builder $atCompany) use ($companyIds, $franchiseIds): void {
                    $atCompany
                        ->whereIn('company_id', $companyIds)
                        ->where(function (Builder $inner) use ($franchiseIds): void {
                            $inner->whereNull('franchise_id');

                            if ($franchiseIds !== []) {
                                $inner->orWhereIn('franchise_id', $franchiseIds);
                            }
                        });
                });
        });
    }

    /**
     * Companies this view may show General rows for, already ∩ soft reach.
     *
     * Soft reach = `reachableCompanies()` plus companies that own a reachable
     * franchise — franchise assignments do not confer `canActOnCompany`, but
     * they still see that company’s General catalogue.
     *
     * @return list<string>
     */
    private static function companyIdsFor(User $user, ViewScope $scope): array
    {
        $softReach = self::softReachCompanyIds($user);

        if ($softReach === []) {
            return [];
        }

        // Not symmetrical on purpose: the organization arm answers with reach, not
        // with $scope->companyIds(). ViewScope::forUser falls back to an organization
        // view for anyone who has never touched the switcher, so a user assigned to
        // one company arrives claiming to look at all of them; trusting the scope in
        // that arm shows them every company. Phone shipped exactly that to production.
        $covered = match ($scope->type) {
            ViewScope::COMPANY => $scope->companyIds(),
            ViewScope::FRANCHISE => array_values(array_filter([
                Franchise::find($scope->id)?->company_id,
            ])),
            default => $softReach,
        };

        return array_values(array_intersect($covered, $softReach));
    }

    /** @return list<string> */
    private static function softReachCompanyIds(User $user): array
    {
        $fromCompanies = $user->reachableCompanies()->modelKeys();
        $fromFranchises = $user->reachableFranchises()
            ->pluck('company_id')
            ->all();

        return array_values(array_unique([...$fromCompanies, ...$fromFranchises]));
    }
}
