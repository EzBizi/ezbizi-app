<?php

namespace App\Support\Settings;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * The business's own timezone, from the `timezone` setting — never a literal
 * (Jose, 2026-11-04: every business value is editable, not a code constant).
 *
 * With a place, that place's value (a branch can override its company). With
 * none, the organization in context. With no organization at all (a platform
 * command looping over tenants before it enters one), the setting's own
 * default — which is what every literal this replaced said.
 */
final class OrgTimezone
{
    public static function of(?Model $scope = null): string
    {
        $scope ??= app(TenantContext::class)->get();

        $zone = $scope === null
            ? app(SettingRegistry::class)->getOrFail('timezone')->default
            : app(SettingsResolver::class)->get('timezone', $scope);

        return is_string($zone) && $zone !== '' ? $zone : (string) app(SettingRegistry::class)->getOrFail('timezone')->default;
    }
}
