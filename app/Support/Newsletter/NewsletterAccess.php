<?php

namespace App\Support\Newsletter;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Scoping\OwnedScope;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Shared newsletter/audience visibility; inherited reads never confer upward writes. */
final class NewsletterAccess
{
    public static function visible(Builder $query, User $user): Builder
    {
        $view = ViewScope::forUser($user);
        $companies = $user->reachableCompanies()->pluck('id')
            ->merge($user->reachableFranchises()->pluck('company_id'))->unique()->values()->all();
        $franchises = $view->franchiseIds();
        if ($view->type === ViewScope::COMPANY) {
            $companies = array_values(array_intersect($companies, $view->companyIds()));
        } elseif ($view->type === ViewScope::FRANCHISE) {
            $companies = array_values(array_intersect($companies, [Franchise::find($view->id)?->company_id]));
        }

        return $query->where(function (Builder $q) use ($user, $companies, $franchises) {
            $q->where(fn (Builder $q) => $q->where('scope_type', 'organization')->where('scope_id', $user->organization_id))
                ->orWhere(fn (Builder $q) => $q->where('scope_type', 'company')->whereIn('scope_id', $companies))
                ->orWhere(fn (Builder $q) => $q->where('scope_type', 'franchise')->whereIn('scope_id', $franchises));
        });
    }

    public static function mayWrite(User $user, Model $row): bool
    {
        return OwnedScope::mayWrite($user, (string) $row->organization_id, $row->scope_type, $row->scope_id);
    }
}
