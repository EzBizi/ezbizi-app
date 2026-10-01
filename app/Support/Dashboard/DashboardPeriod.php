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
 * Day / week / month / quarter / year in the current view's timezone. End is
 * always now — "this month so far", not a future Sunday. Day is today so far
 * (Jose, 2026-10-01: "i also want the default to be Day ( as in today )"). Snapshots ignore the period;
 * time series ask bucket() for the axis.
 */
final class DashboardPeriod
{
    public const VALUES = ['day', 'week', 'month', 'quarter', 'year'];

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
                "Dashboard period must be day, week, month, quarter, or year — not '{$key}'."
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
                'day' => $now->copy()->startOfDay(),
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
            'day', 'week' => $local->toDateString(),
            'month', 'quarter' => $local->copy()->startOfWeek($this->weekStartsAt)->toDateString(),
            'year' => $local->format('Y-m'),
        };
    }

    /**
     * The words a sentence uses for this window: "today", "this week".
     */
    public function phrase(): string
    {
        return $this->key === 'day' ? 'today' : 'this '.$this->key;
    }

    /**
     * Moved to `ViewTimezone` when scheduling needed the same answer: a post
     * scheduled for 9am must fire at the 9am this would have reported it in,
     * and two copies of that reasoning is how they come to differ.
     */
    private static function timezoneFor(User $user): string
    {
        return \App\Support\Time\ViewTimezone::forUser($user);
    }

    private static function weekStartsAtFor(User $user): int
    {
        $scope = ViewScope::forUser($user);
        $startsOn = app(SettingsResolver::class)->get('week_starts_on', $scope->settingsScope());

        return $startsOn === 'monday' ? Carbon::MONDAY : Carbon::SUNDAY;
    }
}
