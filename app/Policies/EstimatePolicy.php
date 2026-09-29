<?php

namespace App\Policies;

use App\Models\Estimate;
use App\Models\Franchise;
use App\Models\User;
use App\Support\Access\Refusal;
use Illuminate\Auth\Access\Response;

/**
 * Estimates are money, so every action needs the Jobs Financials toggle as
 * well as its own (Jose, 2026-09-29: money is "a financials toggle"). Then
 * reach, as for a job: an estimate belongs to one franchise.
 */
class EstimatePolicy
{
    private const MONEY_HIDDEN = 'Money on jobs is hidden for your role.';

    public function viewAny(User $user): Response
    {
        return $this->money($user)
            ?? ($user->hasPermission('estimates.view') ? Response::allow() : Refusal::permission('see estimates'));
    }

    public function view(User $user, Estimate $estimate): Response
    {
        return $this->check($user, 'estimates.view', 'see estimates', $estimate->franchise, 'see this estimate');
    }

    public function create(User $user, ?Franchise $franchise = null): Response
    {
        if ($franchise === null) {
            return $this->money($user)
                ?? ($user->hasPermission('estimates.create') ? Response::allow() : Refusal::permission('add an estimate'));
        }

        return $this->check($user, 'estimates.create', 'add an estimate', $franchise, 'add an estimate');
    }

    public function update(User $user, Estimate $estimate): Response
    {
        return $this->check($user, 'estimates.edit', 'change an estimate', $estimate->franchise, 'change this estimate');
    }

    public function delete(User $user, Estimate $estimate): Response
    {
        return $this->check($user, 'estimates.delete', 'delete an estimate', $estimate->franchise, 'delete this estimate');
    }

    private function money(User $user): ?Response
    {
        return $user->hasPermission('jobs.financials') ? null : Response::deny(self::MONEY_HIDDEN);
    }

    private function check(User $user, string $key, string $noun, Franchise $franchise, string $act): Response
    {
        if ($refused = $this->money($user)) {
            return $refused;
        }

        if (! $user->hasPermission($key)) {
            return Refusal::permission($noun);
        }

        return $user->canActOnFranchise($franchise)
            ? Response::allow()
            : Refusal::noReach($user, Refusal::AT_FRANCHISE, $act, $franchise->name);
    }
}
