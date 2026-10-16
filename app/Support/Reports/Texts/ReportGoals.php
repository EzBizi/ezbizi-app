<?php

namespace App\Support\Reports\Texts;

/**
 * The goals the weekly and monthly report colours against, per company
 * (copied from the A1 Leads report's report-goals.js, 2026-10-16; Jose sets
 * them from here on). Red means worse than the goal.
 */
final class ReportGoals
{
    public const RED = '#DC2626';

    public const GREEN = '#16A34A';

    /** Red above. */
    public const CANCEL_RATE = 0.20;

    /** Red below. */
    public const LSA_CONVERSION = 0.70;

    /** Repair teams: company share as a share of gross, red above. */
    public const REPAIR_COST_FROM_GROSS = 0.05;

    /** Average sale, red below; by the P&L table's company code. */
    public const AVG_SALE = [
        'NJ' => 1500, 'CT' => 2500, 'TX' => 1500, 'OH' => 2500, 'IL' => 2000,
        'GA' => 1500, 'DMV' => 1500, 'WA' => 2000, 'NW' => 1400,
    ];

    /**
     * Expense buckets as a share of income, red above; profit margin is the
     * goal itself.
     */
    public const BUCKETS = [
        'NJ' => ['margin' => .25, 'marketing' => .10, 'office' => .10, 'field' => .25, 'operation' => .25, 'royalty' => .05],
        'CT' => ['margin' => .25, 'marketing' => .075, 'office' => .075, 'field' => .30, 'operation' => .25, 'royalty' => .05],
        'TX' => ['margin' => .25, 'marketing' => .20, 'office' => .10, 'field' => .25, 'operation' => .15, 'royalty' => .05],
        'OH' => ['margin' => .25, 'marketing' => .20, 'office' => .10, 'field' => .25, 'operation' => .15, 'royalty' => .05],
        'IL' => ['margin' => .35, 'marketing' => .05, 'office' => .10, 'field' => .25, 'operation' => .20, 'royalty' => .05],
        'GA' => ['margin' => .25, 'marketing' => .10, 'office' => .10, 'field' => .30, 'operation' => .20, 'royalty' => .05],
        'DMV' => ['margin' => .30, 'marketing' => .10, 'office' => .10, 'field' => .25, 'operation' => .20, 'royalty' => .05],
        'WA' => ['margin' => .15, 'marketing' => .20, 'office' => .10, 'field' => .30, 'operation' => .20, 'royalty' => .05],
        'NW' => ['margin' => .30, 'marketing' => .35, 'office' => .25, 'field' => 0, 'operation' => .10, 'royalty' => 0],
    ];

    /** Which bucket a P&L category counts toward. */
    public const CATEGORY_BUCKET = [
        'Marketing' => 'marketing', 'External Companies' => 'marketing',
        'HQ Office Expenses' => 'office',
        'Tech Expenses' => 'field', 'Production Expenses' => 'field',
        'Bank Expenses' => 'operation',
        'Owner Distributions & Royalties' => 'royalty', 'Owner Distributions & Royalitys' => 'royalty',
    ];

    /** A value as red text when $bad. */
    public static function mark(string $text, bool $bad): string
    {
        return $bad ? '<span style="color:'.self::RED.';font-weight:700">'.$text.'</span>' : $text;
    }
}
