<?php

namespace App\Support\Time;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;

/**
 * The clock the person is looking at.
 *
 * ── Why the VIEW and not the user ────────────────────────────────
 *
 * §3: the current view is the place you file. Somebody standing in the
 * Connecticut branch schedules a post for "Tuesday 9am" and means Connecticut's
 * Tuesday 9am, whatever timezone their laptop is in and whatever the
 * organization's default says. Reading the user's own clock would put a branch
 * manager's holiday in Spain into a customer's posting schedule.
 *
 * ── Why the franchise is asked directly ──────────────────────────
 *
 * A franchise carries its own `timezone` column, which is NOT a settings key —
 * it overrides the cascade rather than participating in it (§6 asks
 * SettingsResolver for settings; this one is a property of the place). So a
 * franchise view asks the row first and only falls back to the organization's
 * setting when the branch has not set one. Company and organization views have
 * no such column and go straight to the cascade.
 *
 * ── Why this is a class and not a private method ─────────────────
 *
 * It was one: `DashboardPeriod::timezoneFor()`. Scheduling needs the identical
 * answer — a post scheduled for 9am must fire at the same 9am the dashboard
 * would report it in — and a second copy is how two parts of the app come to
 * disagree about what time it is for the same person. DashboardPeriod now calls
 * this.
 */
final class ViewTimezone
{
    public static function forUser(User $user): string
    {
        $scope    = ViewScope::forUser($user);
        $settings = app(SettingsResolver::class);

        if ($scope->type === ViewScope::FRANCHISE) {
            $franchise = Franchise::find($scope->id);

            if (is_string($franchise?->timezone) && $franchise->timezone !== '') {
                return $franchise->timezone;
            }

            return (string) $settings->get('timezone', $user->organization);
        }

        return (string) $settings->get('timezone', $scope->settingsScope());
    }
}
