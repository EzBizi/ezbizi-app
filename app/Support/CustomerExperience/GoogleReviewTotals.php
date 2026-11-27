<?php

namespace App\Support\CustomerExperience;

use App\Models\BusinessLocation;

/**
 * A company's Google reviews as Google counts them: the sum of each listing's totalReviewCount and the
 * average weighted by it, read on the review sync (desk, 2026-11-20; the website badge reads this). Listings
 * no longer shown, and those not yet read, are left out.
 */
final class GoogleReviewTotals
{
    /** @return array{count: int, average: ?float, listings: int, as_of: ?string} */
    public static function forCompany(string $companyId): array
    {
        $rows = BusinessLocation::query()->where('company_id', $companyId)->whereNull('not_listed_since')
            ->whereNotNull('google_totals_at')->get(['google_review_count', 'google_rating', 'google_totals_at']);
        $count = (int) $rows->sum('google_review_count');
        $weighted = $rows->sum(fn ($l) => (float) $l->google_rating * (int) $l->google_review_count);

        return ['count' => $count, 'average' => $count > 0 ? round($weighted / $count, 2) : null,
            'listings' => $rows->count(), 'as_of' => $rows->min('google_totals_at')?->toIso8601String()];
    }
}
