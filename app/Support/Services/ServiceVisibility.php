<?php

namespace App\Support\Services;

use App\Models\User;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\ViewScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which services, groups, and labels a list may show.
 *
 * Same place query as the inventory catalogue: organization rows throughout
 * the tenant, company General to anyone who can see that company, franchise
 * rows only when that franchise is in the view. One engine, different nouns.
 */
final class ServiceVisibility
{
    public static function constrain(Builder $query, User $user, ViewScope $scope): Builder
    {
        return InventoryVisibility::constrain($query, $user, $scope);
    }
}
