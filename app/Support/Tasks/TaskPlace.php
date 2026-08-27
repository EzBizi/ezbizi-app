<?php

namespace App\Support\Tasks;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\TaskBoard;
use App\Models\User;
use App\Support\Access\ScopeReach;
use App\Support\Scoping\ViewScope;

final class TaskPlace
{
    /** @return array{organization_id:string,company_id:?string,franchise_id:?string} */
    public static function fromView(ViewScope $scope): array
    {
        $place = $scope->settingsScope();

        return match ($scope->type) {
            ViewScope::COMPANY => [
                'organization_id' => $place->organization_id,
                'company_id' => $place->getKey(),
                'franchise_id' => null,
            ],
            ViewScope::FRANCHISE => [
                'organization_id' => $place->organization_id,
                'company_id' => $place->company_id,
                'franchise_id' => $place->getKey(),
            ],
            default => [
                'organization_id' => $place->getKey(),
                'company_id' => null,
                'franchise_id' => null,
            ],
        };
    }

    public static function coveredBy(
        User $user,
        TaskBoard $board,
        ViewScope $scope,
        ScopeReach $reach,
    ): bool {
        if (! self::reachedBy($user, $board, $reach)) {
            return false;
        }

        if ($board->company_id === null) {
            return $scope->isOrganization();
        }

        if ($board->franchise_id === null) {
            return $scope->coversCompany($board->company_id)
                && $scope->coversFranchise(null);
        }

        return $scope->coversCompany($board->company_id)
            && $scope->coversFranchise($board->franchise_id);
    }

    public static function reachedBy(User $user, TaskBoard $board, ScopeReach $reach): bool
    {
        if ($board->organization_id !== $user->organization_id) {
            return false;
        }

        if ($board->company_id === null) {
            return $reach->covers(
                $user,
                Assignment::SCOPE_ORGANIZATION,
                $board->organization_id,
            );
        }

        if ($board->franchise_id === null) {
            return $reach->covers($user, Assignment::SCOPE_COMPANY, $board->company_id);
        }

        return $reach->covers($user, Assignment::SCOPE_FRANCHISE, $board->franchise_id);
    }
}
