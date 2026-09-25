<?php

namespace Tests\Feature;

use App\Console\Commands\A1LeadsReviewWatch;
use App\Models\A1LeadsReviewEvent;
use App\Models\AutomationEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `a1leads_gbp.review_received`: once per NEW review, and never for the past.
 *
 * The failure this is aimed at is Bar's first rule answering years of reviews
 * in one night. So the cases that matter are the ones that must raise NOTHING:
 * the first run, a review older than the previous capture, a snapshot holding
 * more than a day could, and the same review seen in a second snapshot.
 */
class A1LeadsReviewWatchTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $reviews = [];

    private array $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.organization_id', $this->tenant['org']->id);
        config()->set('services.a1leads.company_id', $this->tenant['company']->id);
        config()->set('services.a1leads.base_url', 'http://127.0.0.1:3456');
        config()->set('services.a1leads.api_key', 'test-partner-key');

        Http::fake([
            '*/_info' => Http::response(['version' => '1.10.1']),
            '*/api/v1/reviews*' => fn () => Http::response(['data' => $this->reviews, 'meta' => ['total' => count($this->reviews)]]),
        ]);
    }

    private function snapshot(string $generation, string $capturedAt): void
    {
        $path = tempnam(sys_get_temp_dir(), 'a1receipt');
        file_put_contents($path, json_encode([
            'installed_generation' => $generation,
            'snapshot_captured_at' => $capturedAt,
            'installed_at' => $capturedAt,
        ]));
        config()->set('services.a1leads.receipt', $path);
    }

    private static function review(string $id, string $createdAt, int $rating = 5, ?string $reply = null): array
    {
        return [
            'google_review_name' => "accounts/a/locations/l/reviews/{$id}",
            'review_created_at' => $createdAt,
            'rating' => $rating,
            'reviewer_name' => "Reviewer {$id}",
            'location_title' => 'A1 Chimney',
            'location_address' => '12 Elm St, Warren, MI',
            'reply' => $reply,
            'deleted_at' => null,
        ];
    }

    private function raised(): array
    {
        return AutomationEvent::withoutGlobalScopes()
            ->where('event_key', 'a1leads_gbp.review_received')
            ->orderBy('occurred_at')
            ->get()
            ->all();
    }

    public function test_a_new_snapshot_raises_each_new_review_once_and_only_new_ones(): void
    {
        Carbon::setTestNow('2026-09-25T04:00:00Z');

        // First run: remembers where it is, raises nothing - though reviews exist.
        $this->snapshot('gen-1', '2026-09-24T03:00:00Z');
        $this->reviews = [self::review('old', '2026-09-20T10:00:00Z')];
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();
        $this->assertSame([], $this->raised(), 'the first run answers nothing from the past');

        // Next day's snapshot: two written since yesterday's capture, one before.
        $this->snapshot('gen-2', '2026-09-25T03:00:00Z');
        $this->reviews = [
            self::review('new-5', '2026-09-25T00:33:23Z', 5),
            self::review('new-1', '2026-09-24T17:15:54Z', 1, 'Thank you'),
            self::review('before', '2026-09-24T02:04:32Z'),
            self::review('old', '2026-09-20T10:00:00Z'),
        ];
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();

        $events = $this->raised();
        $this->assertCount(2, $events);
        $byName = collect($events)->keyBy(fn ($e) => $e->payload['google_review_name']);
        $five = $byName['accounts/a/locations/l/reviews/new-5'];
        $this->assertSame(5, $five->payload['rating']);
        $this->assertSame('Reviewer new-5', $five->payload['reviewer_name']);
        $this->assertSame('Reviewer', $five->payload['reviewer_first_name'], 'the greeting takes the first name only');
        $this->assertSame('A1 Chimney', $five->payload['location_name']);
        $this->assertFalse($five->payload['has_reply']);
        $this->assertTrue($byName['accounts/a/locations/l/reviews/new-1']->payload['has_reply']);
        // The subject is the review's own row, sitting at A-1 Chimney's company.
        $this->assertSame(A1LeadsReviewEvent::class, $five->subject_type);
        $row = A1LeadsReviewEvent::withoutGlobalScopes()->findOrFail($five->subject_id);
        $this->assertSame('accounts/a/locations/l/reviews/new-5', $row->google_review_name);
        $this->assertSame(['company', $this->tenant['company']->id], $row->automationScope());
        $this->assertSame('company', $five->scope_snapshot['type']);
        $this->assertArrayNotHasKey('comment', $five->payload, "the customer's words stay out");

        // The same generation again: nothing.
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();
        $this->assertCount(2, $this->raised());

        // A ROLLBACK to yesterday's snapshot and then today's again - which
        // A1 Leads' own revert path can do. The watermark goes back with it,
        // so today's reviews look new a second time; only the once-per-review
        // memory stops them being answered twice.
        $this->snapshot('gen-1-restored', '2026-09-24T03:00:00Z');
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();
        $this->snapshot('gen-2-again', '2026-09-25T03:00:00Z');
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();
        $this->assertCount(2, $this->raised(), 'no review is raised twice');
    }

    public function test_a_snapshot_with_more_new_reviews_than_a_day_can_hold_raises_nothing(): void
    {
        Carbon::setTestNow('2026-09-25T04:00:00Z');
        $this->snapshot('gen-1', '2026-09-24T03:00:00Z');
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();

        $this->snapshot('gen-2', '2026-09-25T03:00:00Z');
        $this->reviews = [];
        for ($i = 0; $i <= A1LeadsReviewWatch::MAX_PER_SNAPSHOT; $i++) {
            $this->reviews[] = self::review("burst-{$i}", '2026-09-25T01:00:00Z');
        }
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();

        $this->assertSame([], $this->raised(), 'an import catching up is not a day of customers');

        // And the watermark moved: the next snapshot is judged from here.
        $this->snapshot('gen-3', '2026-09-26T03:00:00Z');
        $this->reviews = [self::review('today', '2026-09-25T20:00:00Z'), ...$this->reviews];
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();
        $this->assertCount(1, $this->raised());
    }

    /**
     * Greet by first name only, and only when there is one - Rodrigo's rule.
     * A wrong name in a public reply is worse than none.
     */
    public function test_the_first_name_is_given_only_when_it_is_one(): void
    {
        $this->assertSame('Dana', A1LeadsReviewWatch::firstName('Dana Whitfield'));
        $this->assertSame('Dana', A1LeadsReviewWatch::firstName('dana'));
        $this->assertSame("O'Brien", A1LeadsReviewWatch::firstName("O'Brien x"));
        $this->assertSame('Mary-Jane', A1LeadsReviewWatch::firstName('Mary-Jane O'));
        $this->assertSame('José', A1LeadsReviewWatch::firstName('José Luis'));
        foreach (['J.', 'A Google user', '@handle', 'Anonymous', '', null] as $unusable) {
            $this->assertNull(A1LeadsReviewWatch::firstName($unusable), var_export($unusable, true));
        }
    }

    public function test_with_no_company_named_it_watches_nothing(): void
    {
        config()->set('services.a1leads.company_id', null);
        $this->snapshot('gen-1', '2026-09-24T03:00:00Z');
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();
        $this->snapshot('gen-2', '2026-09-25T03:00:00Z');
        $this->reviews = [self::review('new', '2026-09-25T00:00:00Z')];
        $this->artisan('ezbizi:a1leads-review-watch')->assertSuccessful();

        $this->assertSame([], $this->raised());
    }
}
