<?php

namespace App\Console\Commands;

use App\Models\A1LeadsReviewEvent;
use App\Models\Company;
use App\Models\Organization;
use App\Services\A1Leads\A1LeadsClient;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Raise `a1leads_gbp.review_received` once for each NEW Google review of
 * A-1 Chimney's, so Bar's automations can reply to it.
 *
 * Jose, 2026-09-25: "this will be set using EzBizi Automations that Bar himself
 * will set up for the reply."
 *
 * ── Why a watcher, and what "new" means ─────────────────────────
 *
 * EzBizi does not import A1 Leads' reviews: it reads a daily snapshot that
 * A1 Leads installs on this box. So "a review arrived" can only be noticed as
 * "a new snapshot is installed, and it holds reviews Google stamped AFTER the
 * previous snapshot was captured". That is the whole definition, and it is
 * structural on purpose:
 *
 *   - The FIRST run records where it is and raises nothing. Otherwise Bar's
 *     first rule would answer years of reviews in one night.
 *   - A review older than the previous capture never fires, however late it
 *     shows up - a backfill is not a customer writing today.
 *   - More than MAX_PER_SNAPSHOT new ones in one snapshot raises nothing and
 *     says so: that is an import catching up, not a day's reviews.
 *   - Each review fires at most once, ever. That is STRUCTURAL: an
 *     `A1LeadsReviewEvent` row, unique per review, is created in the same
 *     transaction as the event, and the event is raised only when the insert
 *     made the row. A re-installed snapshot, a rollback, or two runs at once
 *     cannot answer one review twice.
 *
 * The subject is that row, which sits at A-1 Chimney's company - so a rule's
 * "where it applies" works, and the review's name is read from the row, not
 * from a payload a rule could edit.
 */
class A1LeadsReviewWatch extends Command
{
    protected $signature = 'ezbizi:a1leads-review-watch';

    protected $description = "Raise an automation event for each new A-1 Chimney Google review in A1 Leads' daily snapshot";

    public const MAX_PER_SNAPSHOT = 50;

    private const STATE = 'a1leads:review-watch';

    public function handle(A1LeadsClient $a1leads, TenantContext $tenant, EventEmitter $events): int
    {
        $orgId = config('services.a1leads.organization_id');
        $companyId = config('services.a1leads.company_id');
        if (! is_string($orgId) || $orgId === '' || ! is_string($companyId) || $companyId === '') {
            $this->line('A1 Leads is not named to an organization and company; nothing to watch.');

            return self::SUCCESS;
        }

        $source = $a1leads->source();
        $generation = $source['generation'] ?? null;
        $capturedAt = $source['captured_at'] ?? null;
        if (! ($source['configured'] ?? false) || ! is_string($generation) || ! is_string($capturedAt)) {
            $this->line('No readable A1 Leads snapshot.');

            return self::SUCCESS;
        }

        // ponytail: the watermark lives in the cache (database store, forever).
        // If it is ever cleared, the next run is a first run again: it raises
        // nothing for one snapshot, which is the safe direction.
        $previous = Cache::get(self::STATE);
        if (! is_array($previous)) {
            Cache::forever(self::STATE, ['generation' => $generation, 'captured_at' => $capturedAt]);
            $this->line("First run: watching from snapshot {$generation}. Nothing raised.");

            return self::SUCCESS;
        }
        if ($previous['generation'] === $generation) {
            return self::SUCCESS;
        }

        $since = Carbon::parse($previous['captured_at']);
        $fresh = $this->reviewsSince($a1leads, $since);

        if ($fresh === null) {
            $this->warn('More than '.self::MAX_PER_SNAPSHOT." new reviews in snapshot {$generation}: "
                .'treated as an import catching up. Nothing raised.');
        } else {
            $organization = Organization::withoutGlobalScopes()->findOrFail($orgId);
            $raised = $tenant->run($organization, function () use ($fresh, $companyId, $events) {
                $company = Company::query()->findOrFail($companyId);
                $raised = 0;
                foreach ($fresh as $review) {
                    $raised += DB::transaction(function () use ($review, $company, $events) {
                        $payload = self::payload($review);
                        $row = A1LeadsReviewEvent::query()->createOrFirst(
                            ['google_review_name' => $payload['google_review_name']],
                            [
                                'company_id' => $company->getKey(),
                                'rating' => $payload['rating'],
                                'reviewer_name' => $payload['reviewer_name'] !== null ? mb_substr($payload['reviewer_name'], 0, 200) : null,
                                'location_name' => $payload['location_name'] !== null ? mb_substr($payload['location_name'], 0, 200) : null,
                                'location_address' => $payload['location_address'] !== null ? mb_substr($payload['location_address'], 0, 300) : null,
                                'has_reply' => $payload['has_reply'],
                                'review_created_at' => $payload['review_created_at'],
                            ],
                        );
                        if (! $row->wasRecentlyCreated) {
                            return 0;
                        }
                        $events->emit('a1leads_gbp.review_received', $row, $payload);

                        return 1;
                    });
                }

                return $raised;
            });
            $this->line("Snapshot {$generation}: {$raised} new review(s) raised.");
        }

        Cache::forever(self::STATE, ['generation' => $generation, 'captured_at' => $capturedAt]);

        return self::SUCCESS;
    }

    /**
     * Reviews Google stamped after $since, newest first - or null when there
     * are more than MAX_PER_SNAPSHOT of them.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function reviewsSince(A1LeadsClient $a1leads, Carbon $since): ?array
    {
        $fresh = [];
        for ($offset = 0; ; $offset += 100) {
            $page = (array) ($a1leads->reviews(limit: 100, offset: $offset)['data'] ?? []);
            foreach ($page as $review) {
                $created = is_array($review) ? ($review['review_created_at'] ?? null) : null;
                if (! is_string($created) || ! Carbon::parse($created)->greaterThan($since)) {
                    // Newest first: the first one at or before the watermark
                    // ends the new ones.
                    return $fresh;
                }
                if (is_string($review['google_review_name'] ?? null) && ($review['deleted_at'] ?? null) === null) {
                    $fresh[] = $review;
                }
                if (count($fresh) > self::MAX_PER_SNAPSHOT) {
                    return null;
                }
            }
            if (count($page) < 100) {
                return $fresh;
            }
        }
    }

    /**
     * What a rule can test and write with, with no second lookup. The review's
     * own words stay out: a rule reads the rating, not the customer's prose.
     *
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    public static function payload(array $review): array
    {
        return [
            'google_review_name' => (string) $review['google_review_name'],
            'rating' => is_numeric($review['rating'] ?? null) ? (int) $review['rating'] : null,
            'reviewer_name' => is_string($review['reviewer_name'] ?? null) ? trim($review['reviewer_name']) : null,
            'reviewer_first_name' => self::firstName($review['reviewer_name'] ?? null),
            'location_name' => is_string($review['location_title'] ?? null) ? $review['location_title'] : null,
            'location_address' => is_string($review['location_address'] ?? null) ? $review['location_address'] : null,
            'has_reply' => trim((string) ($review['reply'] ?? '')) !== '',
            'review_created_at' => $review['review_created_at'] ?? null,
        ];
    }

    /**
     * A first name safe to greet with, or null.
     *
     * Rodrigo's rule for every A-1 reply: "Hi {firstName}," only "when a usable
     * name exists", and otherwise no greeting name at all. Google display names
     * are often initials, handles or businesses, so this takes the first word
     * only when it looks like a name - letters, with an apostrophe or hyphen
     * inside - and gives up otherwise. A wrong name in a public reply is worse
     * than none.
     */
    public static function firstName(mixed $reviewerName): ?string
    {
        if (! is_string($reviewerName)) {
            return null;
        }
        $first = strtok(trim($reviewerName), " 	");
        if (! is_string($first) || mb_strlen($first) < 2 || mb_strlen($first) > 30
            || preg_match("/^\p{L}+(?:['\-]\p{L}+)*$/u", $first) !== 1
            || in_array(mb_strtolower($first), ['a', 'google', 'user', 'anonymous'], true)) {
            return null;
        }

        // The reviewer's own capitals, unless they typed none at all.
        return mb_strtolower($first) === $first
            ? mb_strtoupper(mb_substr($first, 0, 1)).mb_substr($first, 1)
            : $first;
    }
}
