<?php

namespace App\Support\CustomerExperience;

use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Review;
use App\Models\ReviewLinkMatch;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * "Reviewed", the funnel's fifth step (Jose, 2026-11-09). Google never says
 * which review a link produced, so each new Google review is read against the
 * links sent in the 14 days before it at the same branch's listing, by the
 * reviewer's name: exact (whole name), likely (first name and last initial, or
 * the reverse), possible (first name only, and only one such customer). The
 * latest such send wins; no Google click means "reviewed without click".
 */
final class ReviewLinkMatcher
{
    public const WINDOW_DAYS = 14;

    private const RANK = ['exact' => 3, 'likely' => 2, 'possible' => 1];

    /** Reads the tenant's Google reviews since [$since]; returns how many were matched now. */
    public function run(CarbonInterface $since): int
    {
        $listings = BusinessLocation::query()->whereNotNull('franchise_id')->get(['franchise_id', 'remote_name'])
            ->map(fn ($l) => ['franchise_id' => $l->franchise_id, 'tail' => preg_match('~(locations/[^/]+)$~', (string) $l->remote_name, $m) ? $m[1] : null])
            ->filter(fn ($l) => $l['tail'] !== null);
        if ($listings->isEmpty()) {
            return 0;
        }
        $franchiseOf = fn (Review $r) => $listings->first(fn ($l) => str_ends_with((string) $r->location_name, $l['tail']))['franchise_id'] ?? null;

        $sends = $this->sends($since->copy()->subDays(self::WINDOW_DAYS));
        $clicked = DB::table('review_link_events')->where('kind', 'google')->pluck('job_id')->flip();
        $made = 0;

        Review::query()->where('source', Review::SOURCE_GOOGLE)->where('reviewed_at', '>=', $since)
            ->whereNotNull('reviewer_name')->whereNotIn('id', ReviewLinkMatch::query()->select('review_id'))
            ->orderBy('reviewed_at')->each(function (Review $review) use ($sends, $franchiseOf, $clicked, &$made) {
                $franchise = $franchiseOf($review);
                if ($franchise === null) {
                    return;
                }
                $at = $review->reviewed_at;
                $candidates = $sends->filter(fn ($s) => $s->franchise_id === $franchise
                    && $s->sent_at->lte($at) && $s->sent_at->gte($at->copy()->subDays(self::WINDOW_DAYS)))
                    ->map(fn ($s) => [$s, self::score((string) $review->reviewer_name, (string) $s->customer_name)])
                    ->filter(fn ($p) => $p[1] !== null);
                if ($candidates->isEmpty()) {
                    return;
                }
                $best = $candidates->max(fn ($p) => self::RANK[$p[1]]);
                $top = $candidates->filter(fn ($p) => self::RANK[$p[1]] === $best);
                // A first name alone is only evidence when it points at one customer.
                if ($best === 1 && $top->pluck('0.customer_id')->unique()->count() > 1) {
                    return;
                }
                [$send, $confidence] = $top->sortByDesc(fn ($p) => $p[0]->sent_at)->first();
                ReviewLinkMatch::create(['review_id' => $review->id, 'job_id' => $send->job_id, 'customer_id' => $send->customer_id,
                    'confidence' => $confidence, 'clicked' => isset($clicked[$send->job_id]), 'sent_at' => $send->sent_at]);
                if ($review->customer_id === null) {
                    $review->forceFill(['customer_id' => $send->customer_id, 'job_id' => $send->job_id, 'customer_match' => 'link'])->save();
                }
                $made++;
            });

        return $made;
    }

    /** Every review link sent since [$from]: job, customer, branch, when. */
    private function sends(CarbonInterface $from)
    {
        return DB::table('automation_runs as r')
            ->join('automation_events as e', 'e.id', '=', 'r.event_id')
            ->join('jobs as j', 'j.id', '=', 'e.subject_id')
            ->join('customers as c', 'c.id', '=', 'j.customer_id')
            ->where('e.subject_type', \App\Models\Job::class)
            ->where('r.status', 'succeeded')->where('r.is_test', false)
            ->where('r.actions_snapshot', 'like', '%review_link%')
            ->where('r.started_at', '>=', $from)
            ->whereNull('c.deleted_at')
            ->get(['j.id as job_id', 'j.franchise_id', 'c.id as customer_id', 'c.name as customer_name', 'r.started_at'])
            ->map(function ($s) {
                $s->sent_at = \Carbon\CarbonImmutable::parse($s->started_at, 'UTC');

                return $s;
            });
    }

    /** exact | likely | possible | null, from a Google reviewer name and a customer name. */
    public static function score(string $reviewer, string $customer): ?string
    {
        $words = fn (string $n) => array_values(array_filter(preg_split('/\s+/', trim(preg_replace('/[^a-z\s]/', ' ',
            strtolower(\Illuminate\Support\Str::ascii($n)))))));
        [$r, $c] = [$words($reviewer), $words($customer)];
        if (count($r) === 0 || count($c) === 0 || in_array(implode(' ', $r), ['a google user', 'google user'], true)) {
            return null;
        }
        [$rf, $rl, $cf, $cl] = [$r[0], end($r), $c[0], end($c)];
        if (count($r) > 1 && count($c) > 1 && $rf === $cf && $rl === $cl) {
            return 'exact';
        }
        $initial = fn (string $a, string $b) => strlen($a) === 1 ? $a === $b[0] : (strlen($b) === 1 && $b === $a[0]);
        if (count($r) > 1 && count($c) > 1 && (($rf === $cf && $initial($rl, $cl)) || ($rl === $cl && $initial($rf, $cf)))) {
            return 'likely';
        }
        if ($rf === $cf && strlen($rf) > 2) {
            return 'possible';
        }

        return null;
    }
}
