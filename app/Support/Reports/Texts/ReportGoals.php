<?php

namespace App\Support\Reports\Texts;

use App\Models\ReportGoal;

/**
 * The goals the weekly and monthly report colours against, set per
 * organization and per company in Settings (Jose, 2026-10-16: the takeover
 * "has to fit the EzBizi system"). Nothing is preset: an unset goal colours
 * nothing. Red means worse than the goal.
 */
final class ReportGoals
{
    public const RED = '#DC2626';

    /** key => [label, direction]: 'max' is red above, 'min' red below. Shares are 0-1. */
    public const KEYS = [
        'avg_sale' => ['Average sale', 'min'],
        'cancel_rate' => ['Cancel rate', 'max'],
        'lsa_conversion' => ['LSA conversion', 'min'],
        'repair_cost_from_gross' => ['Repair cost from gross', 'max'],
        'profit_margin' => ['Profit margin', 'min'],
        'marketing' => ['Marketing share of income', 'max'],
        'office' => ['Office share of income', 'max'],
        'field' => ['Field share of income', 'max'],
        'operation' => ['Operation share of income', 'max'],
        'royalty' => ['Royalty share of income', 'max'],
    ];

    /** @param array<string, float> $goals */
    private function __construct(private readonly array $goals) {}

    /**
     * One company's goals: its own, over the organization-wide ones (company
     * null). Read outside a tenant, so the organization is named.
     */
    public static function for(string $organizationId, ?string $company): self
    {
        $rows = ReportGoal::withoutGlobalScopes()->where('organization_id', $organizationId)
            ->where(fn ($q) => $q->whereNull('company')->when($company !== null, fn ($q) => $q->orWhere('company', $company)))
            ->get(['company', 'key', 'value'])
            ->sortBy(fn ($r) => $r->company !== null); // company-specific last, so it wins

        return new self($rows->mapWithKeys(fn ($r) => [$r->key => (float) $r->value])->all());
    }

    /** Is $value worse than the goal for $key? False when no goal is set. */
    public function bad(string $key, ?float $value): bool
    {
        if ($value === null || ! isset($this->goals[$key], self::KEYS[$key])) {
            return false;
        }

        return self::KEYS[$key][1] === 'max' ? $value > $this->goals[$key] : $value < $this->goals[$key];
    }

    /** A value as red text when $bad. */
    public static function mark(string $text, bool $bad): string
    {
        return $bad ? '<span style="color:'.self::RED.';font-weight:700">'.$text.'</span>' : $text;
    }
}
