<?php

namespace App\Support\Jobs;

use App\Models\Job;
use App\Models\JobPart;
use App\Support\Settings\SettingsResolver;

/**
 * A "Project" job's costs (Jose with Shoval, 2026-11-23): a job tagged Project
 * cannot be completed until its Parts cost and Labor cost are in. Labor is a
 * contractor's set price, or a repair team's day rate (alone or with a helper)
 * times the days it took.
 */
final class ProjectCosts
{
    public const TAG = 'PROJECT';

    public static function isProject(Job $job): bool
    {
        return collect((array) ($job->tags ?? []))->contains(fn ($t) => mb_strtoupper(trim((string) $t)) === self::TAG);
    }

    /** Parts cost: what was entered, else the parts' prices × quantities, else null. */
    public static function partsMinor(Job $job): ?int
    {
        if ($job->parts_cost_minor !== null) {
            return (int) $job->parts_cost_minor;
        }
        $parts = JobPart::query()->where('job_id', $job->id)->whereNotNull('price_minor')->get(['price_minor', 'quantity']);

        return $parts->isEmpty() ? null : (int) $parts->sum(fn (JobPart $p) => $p->price_minor * $p->quantity);
    }

    /** Labor cost, or null while it cannot be worked out. */
    public static function laborMinor(Job $job): ?int
    {
        return match ($job->labor_kind) {
            'contractor' => $job->labor_price_minor === null ? null : (int) $job->labor_price_minor,
            'team' => (($rate = self::rates($job)[$job->labor_helper ? 'with_helper_per_day_minor' : 'alone_per_day_minor'] ?? null) === null
                || ! $job->labor_days) ? null : (int) $rate * (int) $job->labor_days,
            default => null,
        };
    }

    /** What a Project job still needs before it is complete; empty when nothing. @return list<string> */
    public static function missing(Job $job): array
    {
        if (! self::isProject($job)) {
            return [];
        }

        return array_values(array_filter([
            self::partsMinor($job) === null ? 'Parts cost' : null,
            self::laborMinor($job) === null ? ($job->labor_kind === 'team' ? 'Labor cost (days, and the team day rate in Settings)' : 'Labor cost') : null,
        ]));
    }

    /** @return array{alone_per_day_minor?: int|null, with_helper_per_day_minor?: int|null} */
    public static function rates(Job $job): array
    {
        $place = $job->franchise?->company ?? $job->organization;

        return (array) app(SettingsResolver::class)->get('project_board.team_rates', $place);
    }
}
