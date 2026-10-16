<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ReviewsController;
use App\Models\A1LeadsReview;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Review;
use App\Models\ReviewNote;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Copy A1Leads GBP's work on each review into EzBizi's own Reviews before that
 * tab is deleted (Jose, 2026-10-16): the card's status, its note, the customer
 * it was matched to. Joined on `locations/<id>/reviews/<id>`, because the
 * account part of the name differs between Rodrigo's grant and ours.
 *
 * Never overwrites: a status, note or customer already set in Reviews wins.
 * Safe to run again. --dry-run counts and writes nothing.
 */
class ReviewsImportA1Leads extends Command
{
    protected $signature = 'ezbizi:reviews-import-a1leads {organization : the organization id} {--dry-run}';

    protected $description = 'Copy A1Leads GBP statuses, notes and customer matches into Reviews';

    /** A1 Leads' words for a card, as Reviews' status, and what else they mean. */
    private const STATUS = [
        "Can't locate" => 'cant_locate',
        'Working on it' => 'working',
        'Awaiting report' => 'awaiting_report',
        'Done' => 'done',
    ];

    public function handle(TenantContext $tenant): int
    {
        $organization = Organization::query()->findOrFail($this->argument('organization'));
        $dry = (bool) $this->option('dry-run');
        $n = ['cards' => 0, 'unmatched_review' => 0, 'status' => 0, 'reported' => 0, 'notes' => 0, 'customers' => 0, 'customer_ambiguous' => 0];

        $tenant->run($organization, function () use (&$n, $dry) {
            $byKey = Review::query()->get(['id', 'remote_name', 'status', 'customer_id', 'reviewed_at', 'reported_at', 'organization_id'])
                ->keyBy(fn (Review $r) => self::key($r->remote_name));

            A1LeadsReview::query()->where(fn ($q) => $q->whereNotNull('card')->orWhereIn('match_tier', ['high', 'medium']))
                ->orderBy('id')->each(function (A1LeadsReview $a) use (&$n, $dry, $byKey) {
                    $n['cards']++;
                    $review = $byKey[self::key($a->google_review_name)] ?? null;
                    if ($review === null) {
                        $n['unmatched_review']++;

                        return;
                    }
                    $card = is_array($a->card) ? $a->card : [];
                    $at = isset($card['updated_at']) ? Carbon::parse($card['updated_at']) : now();
                    $changes = [];
                    $notes = [];

                    $words = (string) ($card['status'] ?? '');
                    if (str_starts_with($words, 'Reported - ') && $review->reported_at === null) {
                        $failed = $words === 'Reported - Failed';
                        $date = $failed ? null : rescue(fn () => Carbon::parse(substr($words, 11)), null, false);
                        $changes += $failed ? [] : ['reported_at' => $date ?? $at];
                        $changes += $review->status === null ? ['status' => $failed ? 'awaiting_report' : 'done'] : [];
                        $notes[] = $failed ? 'A1 Leads: the report to Google failed.' : 'A1 Leads: reported to Google.';
                        $n['reported'] += $failed ? 0 : 1;
                    } elseif ($words === 'Removed') {
                        $changes += $review->status === null ? ['status' => 'done'] : [];
                        $notes[] = 'A1 Leads: removed from Google.';
                    } elseif (isset(self::STATUS[$words]) && $review->status === null) {
                        $changes['status'] = self::STATUS[$words];
                    }
                    $n['status'] += isset($changes['status']) ? 1 : 0;

                    if (trim((string) ($card['note'] ?? '')) !== '') {
                        array_unshift($notes, trim((string) $card['note']));
                    }

                    $phone = (string) ($card['customer']['phone'] ?? $card['workiz_contact']['phone'] ?? '');
                    if ($review->customer_id === null && ($card['customer']['matched'] ?? false) && strlen($digits = preg_replace('/\D/', '', $phone)) >= 10) {
                        $found = Customer::query()->whereRaw("REGEXP_REPLACE(phone, '[^0-9]', '') LIKE ?", ['%'.substr($digits, -10)])->limit(2)->get();
                        if ($found->count() === 1) {
                            $changes += ['customer_id' => $found[0]->getKey(), 'customer_match' => 'auto',
                                'job_id' => ReviewsController::jobFor($found[0], $review)];
                            $n['customers']++;
                        } elseif ($found->count() > 1) {
                            $n['customer_ambiguous']++;
                        }
                    }

                    if ($dry) {
                        $n['notes'] += count($notes);

                        return;
                    }
                    if ($changes !== []) {
                        $review->forceFill($changes)->save();
                    }
                    foreach ($notes as $body) {
                        if (! ReviewNote::query()->where('review_id', $review->id)->where('body', $body)->exists()) {
                            ReviewNote::create(['organization_id' => $review->organization_id, 'review_id' => $review->id,
                                'user_id' => null, 'body' => $body, 'created_at' => $at]);
                            $n['notes']++;
                        }
                    }
                });
        });

        $this->table(array_keys($n), [array_values($n)]);
        $this->info($dry ? 'Dry run: nothing written.' : 'Done.');

        return self::SUCCESS;
    }

    /** `locations/<id>/reviews/<id>` from either grant's full name. */
    private static function key(?string $name): string
    {
        return preg_match('~(locations/[^/]+/reviews/[^/]+)$~', (string) $name, $m) ? $m[1] : (string) $name;
    }
}
