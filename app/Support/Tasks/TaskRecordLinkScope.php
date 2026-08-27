<?php

namespace App\Support\Tasks;

use App\Models\Assignment;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\InspectionReport;
use App\Models\InspectionRun;
use App\Models\InventoryItem;
use App\Models\Job;
use App\Models\Service;
use App\Models\TaskBoard;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Model;

final class TaskRecordLinkScope
{
    public function contains(Model $record, TaskBoard $board, ViewScope $scope): bool
    {
        foreach ($this->places($record) as $place) {
            if ($this->inView($place, $scope) && $this->inBoard($place, $board)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{company_id:?string,franchise_id:?string}> */
    private function places(Model $record): array
    {
        if ($record instanceof User) {
            return $record->assignments->map(fn (Assignment $assignment) => [
                'company_id' => $assignment->assigned_company_id
                    ?? ($assignment->assigned_franchise_id === null ? null : Franchise::query()->find($assignment->assigned_franchise_id)?->company_id),
                'franchise_id' => $assignment->assigned_franchise_id,
            ])->values()->all();
        }
        if ($record instanceof Customer) {
            return [$this->franchise($record->owning_franchise_id)];
        }
        if ($record instanceof Job) {
            return [$this->franchise($record->franchise_id)];
        }
        if ($record instanceof Vehicle || $record instanceof InventoryItem || $record instanceof Service) {
            return [['company_id' => $record->company_id, 'franchise_id' => $record->franchise_id]];
        }
        if ($record instanceof InspectionReport || $record instanceof InspectionRun) {
            if ($record->job?->franchise_id !== null) {
                return [$this->franchise($record->job->franchise_id)];
            }
            if ($record->customer?->owning_franchise_id !== null) {
                return [$this->franchise($record->customer->owning_franchise_id)];
            }
        }

        return [['company_id' => null, 'franchise_id' => null]];
    }

    /** @return array{company_id:?string,franchise_id:?string} */
    private function franchise(?string $id): array
    {
        return ['company_id' => $id === null ? null : Franchise::query()->find($id)?->company_id, 'franchise_id' => $id];
    }

    /** @param array{company_id:?string,franchise_id:?string} $place */
    private function inView(array $place, ViewScope $scope): bool
    {
        if ($place['company_id'] === null) {
            return $scope->isOrganization();
        }
        if ($place['franchise_id'] === null) {
            return $scope->coversCompany($place['company_id']) && $scope->coversFranchise(null);
        }

        return $scope->coversCompany($place['company_id']) && $scope->coversFranchise($place['franchise_id']);
    }

    /** @param array{company_id:?string,franchise_id:?string} $place */
    private function inBoard(array $place, TaskBoard $board): bool
    {
        if ($board->company_id === null) {
            return true;
        }
        if ($board->franchise_id !== null) {
            return $place['franchise_id'] === $board->franchise_id;
        }
        if ($place['company_id'] === null) {
            return false;
        }

        return in_array($place['company_id'], $board->company()->firstOrFail()->subtreeCompanyIds(), true);
    }
}
