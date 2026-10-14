<?php

namespace App\Support\Reports\Scoring;

use App\Console\Commands\ScoreReportCalls;
use App\Models\ReportScoringWeight;

/**
 * How much each metric counts in a person's Scoring score (Jose, 2026-10-14:
 * "conversion rate being the most important, while the other call related
 * stuff ... is also important but to a lesser degree ... all summing up to
 * 100%"). Every metric is a percentage; the score is their weighted average,
 * out of 10.
 */
class ScoringWeights
{
    /** Conversion first, then AI's seven checks (ScoreReportCalls::CHECKS). */
    public const DEFAULTS = [
        'conversion' => 40,
        'greeted' => 5,
        'got_name' => 10,
        'got_address' => 10,
        'found_need' => 10,
        'offered_time' => 10,
        'gave_price' => 5,
        'asked_for_booking' => 10,
    ];

    /** @return array<string, int> */
    public static function current(): array
    {
        $saved = ReportScoringWeight::query()->value('weights');

        // A metric added later reads 0 in a saved set, so the sum stays 100.
        return is_array($saved)
            ? array_map(fn ($key) => (int) ($saved[$key] ?? 0), array_combine(array_keys(self::DEFAULTS), array_keys(self::DEFAULTS)))
            : self::DEFAULTS;
    }

    /** @return list<string> */
    public static function metrics(): array
    {
        return ['conversion', ...ScoreReportCalls::CHECKS];
    }

    /**
     * Out of 10. A metric with no value (no new-client calls, so no
     * conversion rate) drops out and the others share its weight.
     *
     * @param  array<string, int|float|null>  $values  each 0-100
     * @param  array<string, int>  $weights
     */
    public static function score(array $values, array $weights): ?float
    {
        $sum = $total = 0;
        foreach ($weights as $metric => $weight) {
            if ($weight > 0 && isset($values[$metric])) {
                $sum += $weight * min(100, $values[$metric]);
                $total += $weight;
            }
        }

        return $total === 0 ? null : round($sum / $total / 10, 1);
    }
}
