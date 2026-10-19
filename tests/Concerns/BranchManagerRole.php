<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;

/**
 * Every permission there is, on a role that is NOT the Administrator.
 *
 * Reach tests used to give a branch-limited person the Administrator role to
 * hand them every permission at once. Since 2026-10-16 an Administrator
 * reaches the whole organization whatever their assignment (Jose: not
 * "limited to that company"), so such a person is no longer limited, and the
 * tests asked a question that no longer exists. This role keeps what they
 * test: reach, with nothing missing from the permissions.
 *
 * Call it inside the tenant, as User::create already is.
 */
final class BranchManagerRole
{
    /** @var array<string, string> organization id => role id */
    private static array $made = [];

    public static function idFor(User $administrator): string
    {
        $org = (string) $administrator->organization_id;

        if (isset(self::$made[$org]) && app(TenantContext::class)->run(
            $administrator->organization,
            fn () => Role::query()->whereKey(self::$made[$org])->exists(),
        )) {
            return self::$made[$org];
        }

        // In the administrator's own tenant, whether or not the caller is.
        $role = app(TenantContext::class)->run($administrator->organization, function () use ($org) {
            $role = Role::create([
                'organization_id' => $org,
                'name' => 'Branch manager (all permissions)',
                'is_system' => false,
            ]);
            $role->syncPermissionKeys(app(ModuleRegistry::class)->allPermissionKeys());

            return $role;
        });

        return self::$made[$org] = (string) $role->getKey();
    }
}
