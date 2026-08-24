<?php

namespace App\Support\Dashboard;

use App\Models\Franchise;
use App\Models\User;
use App\Support\Scoping\ViewScope;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * One calendar window for the whole dashboard (spec §6).
 *
 * Week / month / quarter / year in the current view's timezone. End is always
 * now — "this month so far", not a future Sunday. Snapshots ignore the period;
 * time series ask bucket() for the axis.
 */
final class DashboardPeriod
{
    public const VALUES = ['week', 'month', 'quarter', 'year'];

    private function __construct(
        public readonly string $key,
        public readonly Carbon $startsOn,
        public readonly Carbon $endsOn,
        public readonly string $timezone,
        private readonly int $weekStartsAt,
    ) {}

    public static function for(User $user, string $key = 'month'): self
    {
        if (! in_array($key, self::VALUES, true)) {
            throw new InvalidArgumentException(
                "Dashboard period must be week, month, quarter, or year — not '{$key}'."
            );
        }

        // Settings (and Franchise when the view is a place) are tenant-scoped.
        // Callers — including tests — may ask for a period outside a request, so
        // enter the user's organization here rather than requiring every caller
        // to remember TenantContext.
        return app(TenantContext::class)->run($user->organization, function () use ($user, $key) {
            $timezone = self::timezoneFor($user);
            $now = Carbon::now($timezone);
            $weekStartsAt = self::weekStartsAtFor($user);
            $startsOn = match ($key) {
                'week' => $now->copy()->startOfWeek($weekStartsAt),
                'month' => $now->copy()->startOfMonth()->startOfDay(),
                'quarter' => $now->copy()->firstOfQuarter()->startOfDay(),
                'year' => $now->copy()->startOfYear()->startOfDay(),
            };

            return new self($key, $startsOn, $now->copy(), $timezone, $weekStartsAt);
        });
    }

    /**
     * Series axis key for a point inside this period.
     *
     * Day for a week; start-of-week day for a month or quarter; month for a year.
     */
    public function bucket(Carbon $at): string
    {
        $local = $at->copy()->timezone($this->timezone);

        return match ($this->key) {
            'week' => $local->toDateString(),
            'month', 'quarter' => $local->copy()->startOfWeek($this->weekStartsAt)->toDateString(),
            'year' => $local->format('Y-m'),
        };
    }

    private static function timezoneFor(User $user): string
    {
        $scope = ViewScope::forUser($user);
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

    private static function weekStartsAtFor(User $user): int
    {
        $scope = ViewScope::forUser($user);
        $startsOn = app(SettingsResolver::class)->get('week_starts_on', $scope->settingsScope());

        return $startsOn === 'monday' ? Carbon::MONDAY : Carbon::SUNDAY;
    }
}
