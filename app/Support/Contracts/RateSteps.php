<?php

namespace App\Support\Contracts;

/**
 * Hourly rate stages: "$26, then after 90 days $28" (Merav, via Jose,
 * 2026-11-17: "After X days hourly rate ..."). The answer to a `rate_steps`
 * field, stored as JSON: [{"after_days": 90, "rate": "28.00"}, ...], sorted by
 * days. The starting rate stays the `hourly_rate` field beside it.
 *
 * Days count from the start date. When onboarding completes, each stage is
 * written as a pay rate effective on that day (OnboardingToRecord), so the rate
 * history is the pay_rates table and a stage applies itself on its date.
 */
final class RateSteps
{
    /**
     * Normalised steps, or a reason they cannot be read.
     *
     * @return array{0: list<array{after_days: int, rate: string}>|null, 1: ?string}
     */
    public static function parse(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (! is_array($raw)) {
            return [null, 'Send a list of stages, each with after_days and rate.'];
        }

        $steps = [];
        foreach (array_values($raw) as $row) {
            $days = is_array($row) ? ($row['after_days'] ?? null) : null;
            $rate = is_array($row) ? ($row['rate'] ?? null) : null;
            // A row added on the form and not filled in yet is no stage.
            if (in_array($days, [null, ''], true) && in_array($rate, [null, ''], true)) {
                continue;
            }
            if (! is_numeric($days) || (int) $days != $days || (int) $days < 1 || (int) $days > 3650) {
                return [null, 'Each stage starts after a whole number of days, 1 to 3650.'];
            }
            if (! is_numeric($rate) || (float) $rate <= 0 || (float) $rate > 10000) {
                return [null, 'Each stage needs an hourly rate above zero.'];
            }
            if (isset($steps[(int) $days])) {
                return [null, 'Two stages start after the same number of days.'];
            }
            $steps[(int) $days] = ['after_days' => (int) $days, 'rate' => number_format((float) $rate, 2, '.', '')];
        }
        ksort($steps);

        return [array_values($steps), null];
    }

    /** "After 90 days: $28.00/hr; after 180 days: $30.00/hr", or null for none. */
    public static function summary(?string $value): ?string
    {
        [$steps] = self::parse($value);
        if ($steps === null || $steps === []) {
            return null;
        }

        return ucfirst(implode('; ', array_map(
            fn (array $s) => sprintf('after %d %s: $%s/hr', $s['after_days'], $s['after_days'] === 1 ? 'day' : 'days', $s['rate']),
            $steps,
        )));
    }
}
