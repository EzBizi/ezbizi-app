<?php

namespace Tests\Feature;

use App\Console\Commands\A1LeadsDeltaSync;
use App\Models\A1LeadsReview;
use App\Models\AutomationEvent;
use App\Services\A1Leads\A1LeadsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The live copy of A-1's reviews, kept in step with A1 Leads' change feed,
 * and the tab reading it.
 *
 * Rodrigo's contract is the spec: page from cursor 0 while `has_more`, save
 * the cursor only after the page is applied, upsert by exact review name in
 * `seq` order (one review may repeat), `tombstone` means removed-but-shown,
 * `review: null` means gone. The records here are the measured live shape.
 */
class A1LeadsDeltaSyncTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const LIVE = 'http://127.0.0.1:3457';

    private array $t;

    /** @var array<string, array<string, mixed>> cursor => page */
    private array $pages = [];

    /** Http::fake stacks rather than replaces, so a refusal is a switch on the one fake. */
    private bool $refuse = false;

    /** Refuse only the page at this cursor: an import interrupted mid-run. */
    private ?string $refuseAt = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t = $this->provisionTenant('A1-Group');
        config()->set('services.a1leads.organization_id', $this->t['org']->id);
        config()->set('services.a1leads.company_id', $this->t['company']->id);
        config()->set('services.a1leads.review_ops_url', self::LIVE);
        config()->set('services.a1leads.review_ops_key', 'review-ops-key');
        config()->set('services.a1leads.base_url', 'http://127.0.0.1:3456');
        config()->set('services.a1leads.api_key', 'read-key');

        Http::fake([
            self::LIVE.'/api/v1/ezbizi/reviews/delta*' => function (Request $r) {
                parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
                if ($this->refuse || ($this->refuseAt !== null && ($q['cursor'] ?? '0') === $this->refuseAt)) {
                    return Http::response(['error' => ['code' => 'forbidden']], 403);
                }

                return Http::response($this->pages[$q['cursor'] ?? '0'] ?? ['data' => [], 'meta' => [
                    'next_cursor' => $q['cursor'] ?? '0', 'has_more' => false, 'source_as_of' => '2026-09-25T16:00:00Z',
                ]]);
            },
            'http://127.0.0.1:3456/*' => Http::response(['data' => []]),
        ]);
    }

    private static function record(int $seq, string $id, array $review = [], ?array $card = null, bool $tombstone = false, ?string $replyText = null): array
    {
        $name = "accounts/a/locations/l/reviews/{$id}";

        return [
            'seq' => $seq, 'review_ref' => $name, 'kind' => 'review', 'changed_at' => '2026-09-25T15:00:00Z',
            'tombstone' => $tombstone,
            'review' => [
                'id' => $seq, 'google_review_name' => $name, 'gbp_location_name' => 'locations/111',
                'location_title' => 'A1 Chimney', 'reviewer_name' => "Reviewer {$id}", 'rating' => 5,
                'comment' => 'Great work', 'review_created_at' => '2026-09-20T10:00:00Z',
                'deleted_at' => null, 'match_tier' => null, 'match_customer_name' => null,
                'workiz_tenant' => null, 'workiz_url' => null, ...$review,
            ],
            'card' => $card,
            'reply' => ['status' => $replyText ? 'sent' : 'none', 'text' => $replyText, 'at' => $replyText ? '2026-09-21T10:00:00Z' : null],
            'report' => ['status' => 'none', 'reason' => null],
        ];
    }

    private function page(string $cursor, array $records, string $next, bool $more, string $feedId = 'feed-1'): void
    {
        $this->pages[$cursor] = ['data' => $records, 'meta' => [
            'next_cursor' => $next, 'has_more' => $more, 'source_as_of' => '2026-09-25T16:00:00Z', 'feed_id' => $feedId,
        ]];
    }

    /** The same review reached through another Google account. */
    private static function via(string $account, array $record): array
    {
        $name = str_replace('accounts/a/', "accounts/{$account}/", $record['review_ref']);
        $record['review_ref'] = $name;
        if (is_array($record['review'] ?? null)) {
            $record['review']['google_review_name'] = $name;
        }

        return $record + ['business_id' => 1];
    }

    private function rows(): array
    {
        return $this->inTenant($this->t['org'], fn () => A1LeadsReview::query()->orderBy('google_review_name')->get()->keyBy('google_review_name')->all());
    }

    public function test_it_pages_to_the_end_upserting_in_order_and_saves_where_it_got_to(): void
    {
        $this->page('0', [
            self::record(1, 'a', ['rating' => 1]),
            self::record(2, 'b'),
            // The same review again, later in the feed: the later word wins.
            self::record(3, 'a', ['rating' => 2], card: ['review_ref' => 'accounts/a/locations/l/reviews/a', 'status' => 'Working on it', 'note' => 'Called', 'assignee' => null, 'updates' => []]),
        ], '3', true);
        $this->page('3', [
            self::record(4, 'b', tombstone: true, review: ['deleted_at' => '2026-09-25T09:00:00Z']),
            ['seq' => 5, 'review_ref' => 'accounts/a/locations/l/reviews/c', 'kind' => 'review', 'tombstone' => true, 'review' => null],
        ], '5', false);

        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows['accounts/a/locations/l/reviews/a']->rating, 'the later record wins');
        $this->assertSame('Working on it', $rows['accounts/a/locations/l/reviews/a']->card['status']);
        $this->assertTrue($rows['accounts/a/locations/l/reviews/b']->removed, 'a tombstone is kept, marked removed');

        $state = Cache::get(A1LeadsDeltaSync::STATE);
        $this->assertSame('5', $state['cursor']);
        $this->assertArrayHasKey('caught_up_at', $state);

        // A hard delete of a review we HAD removes it.
        $this->page('5', [['seq' => 6, 'review_ref' => 'accounts/a/locations/l/reviews/a', 'kind' => 'review', 'tombstone' => true, 'review' => null]], '6', false);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();
        $this->assertArrayNotHasKey('accounts/a/locations/l/reviews/a', $this->rows());
    }

    /** Row G of REPLACING-RODRIGO-BOT: a review Google took down is raised, once. */
    public function test_a_review_google_removes_is_raised_once(): void
    {
        $this->inTenant($this->t['org'], fn () => A1LeadsDeltaSync::apply(self::record(1, 'a', ['rating' => 1, 'comment' => 'Awful'])));
        $this->inTenant($this->t['org'], fn () => A1LeadsDeltaSync::apply(self::record(2, 'a', ['rating' => 1, 'comment' => 'Awful', 'deleted_at' => '2026-09-25T09:00:00Z'], tombstone: true)));
        $this->inTenant($this->t['org'], fn () => A1LeadsDeltaSync::apply(self::record(3, 'a', ['rating' => 1, 'comment' => 'Awful', 'deleted_at' => '2026-09-25T09:00:00Z'], tombstone: true)));

        $raised = AutomationEvent::withoutGlobalScopes()->where('event_key', 'a1leads_gbp.review_removed')->get();
        $this->assertCount(1, $raised);
        $this->assertSame(1, $raised[0]->payload['rating']);
        $this->assertSame('Awful', $raised[0]->payload['comment']);
    }

    public function test_an_older_record_never_overwrites_a_newer_one(): void
    {
        $this->inTenant($this->t['org'], fn () => A1LeadsDeltaSync::apply(self::record(9, 'a', ['rating' => 1])));
        $this->inTenant($this->t['org'], fn () => A1LeadsDeltaSync::apply(self::record(4, 'a', ['rating' => 5])));

        $this->assertSame(1, $this->rows()['accounts/a/locations/l/reviews/a']->rating);
    }

    public function test_a_refused_page_leaves_the_cursor_where_it_was(): void
    {
        Cache::forever(A1LeadsDeltaSync::STATE, ['cursor' => '7']);
        $this->refuse = true;

        $this->artisan('ezbizi:a1leads-delta-sync')->assertFailed();

        $this->assertSame('7', Cache::get(A1LeadsDeltaSync::STATE)['cursor']);
    }

    public function test_rebuild_starts_again_from_cursor_zero(): void
    {
        Cache::forever(A1LeadsDeltaSync::STATE, ['cursor' => '9999']);
        $this->page('0', [self::record(1, 'a')], '1', false);

        $this->artisan('ezbizi:a1leads-delta-sync', ['--rebuild' => true])->assertSuccessful();

        $this->assertCount(1, $this->rows());
        $this->assertSame('1', Cache::get(A1LeadsDeltaSync::STATE)['cursor']);
    }

    public function test_the_tab_reads_the_live_copy_with_its_filters_and_says_it_is_live(): void
    {
        Carbon::setTestNow('2026-09-25T16:05:00Z');
        $matched = ['match_tier' => 'high', 'match_customer_name' => 'Dana Whitfield'];
        $this->page('0', [
            self::record(1, 'neg-unanswered', ['rating' => 1, ...$matched], card: [
                'review_ref' => 'accounts/a/locations/l/reviews/neg-unanswered', 'status' => 'Working on it',
                'note' => 'Called', 'assignee' => 'Tiara', 'updates' => [],
                'customer' => ['matched' => true, 'name' => 'Dana Whitfield', 'phone' => '+15555550101',
                    'service_address' => '12 Elm St', 'technician' => null, 'workiz_url' => 'https://app.workiz.com/job/X',
                    'last_job' => ['id' => 'X', 'number' => 7, 'date' => '2026-09-01', 'tech' => 'Amit']],
            ]),
            self::record(2, 'neg-answered', ['rating' => 2], replyText: 'Thank you'),
            self::record(3, 'pos', ['rating' => 5]),
        ], '3', false);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $reader = $this->userWithRole($this->t, 'Bridge', ['a1leads_gbp.view']);
        $data = $this->signedInAs($reader)->getJson('/api/v1/a1leads-gbp?rating=negative&replied=false')
            ->assertOk()->json('data');

        $this->assertTrue($data['source']['live']);
        $this->assertSame(1, $data['meta']['total']);
        $row = $data['reviews'][0];
        $this->assertSame('accounts/a/locations/l/reviews/neg-unanswered', $row['google_review_name']);
        $this->assertSame('high', $row['match_tier']);
        $this->assertSame('Working on it', $row['board']['status']);
        $this->assertSame('Dana Whitfield', $row['board']['customer']['name']);
        $this->assertSame('Amit', $row['board']['customer']['technician'], 'from the last job');
        $this->assertNull($row['reply']);

        $answered = $this->signedInAs($reader)->getJson('/api/v1/a1leads-gbp/reviews?replied=true')->assertOk()->json('data');
        $this->assertSame('Thank you', $answered['reviews'][0]['reply']);
        $this->assertSame(1, $this->signedInAs($reader)->getJson('/api/v1/a1leads-gbp/reviews?matched=true')->json('data.meta.total'));
    }

    /**
     * Rodrigo, 2 October: a matched customer must show whether or not the
     * review has a workflow card, and `match` events carry it as well as
     * `review` ones. The shape is the measured live one (seq 13179, 5 October).
     */
    public function test_a_matched_customer_shows_without_a_card_and_from_a_match_event(): void
    {
        Carbon::setTestNow('2026-09-25T16:05:00Z');
        $match = self::record(2, 'm', ['rating' => 1, 'customer_match' => [
            'matched' => true, 'candidates' => [['customerName' => 'A guess']], 'evidence' => ['source' => 'operator_selection'],
        ]]);
        $match['kind'] = 'match';
        $match['customer'] = [
            'matched' => true, 'match_basis' => 'operator_selected', 'client_id' => 5222, 'name' => 'Travis Leighton',
            'phone' => '6034893132', 'phones' => ['6034893132', '6035550000'], 'email' => 'travis@example.com',
            'service_address' => 'W358 Surrey Dr', 'workiz_tenant' => 'IL', 'workiz_url' => 'https://app.workiz.com/root/client/5222',
            'technician' => 'ms chimney', 'assignee_kind' => 'team',
            'last_job' => ['id' => 'OJFQOZ', 'number' => 400594793, 'date' => '2026-09-22 14:00:00', 'tech' => 'ms chimney'],
        ];
        $match['contact'] = ['name' => 'Travis Leighton', 'phone' => '6034893132', 'phones' => [], 'email' => null, 'address' => 'W358 Surrey Dr'];
        $this->page('0', [self::record(1, 'm', ['rating' => 1]), $match], '2', false);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $reader = $this->userWithRole($this->t, 'Bridge', ['a1leads_gbp.view']);
        $row = $this->signedInAs($reader)->getJson('/api/v1/a1leads-gbp')->assertOk()->json('data.reviews.0');

        $this->assertNull($row['board'], 'no card');
        $this->assertSame([
            'name' => 'Travis Leighton', 'basis' => 'operator_selected', 'manual' => true,
            'workiz_account' => 'IL', 'client_id' => '5222', 'job_number' => '400594793',
            'job_date' => '2026-09-22 14:00:00', 'technician' => 'ms chimney', 'assignee_kind' => 'team',
            'phones' => ['6034893132', '6035550000'], 'email' => 'travis@example.com', 'address' => 'W358 Surrey Dr',
            'workiz_url' => 'https://app.workiz.com/root/client/5222',
        ], $row['customer']);
        $this->assertArrayNotHasKey('customer_match', $row, 'candidates and evidence stay on their side');
        $this->assertArrayNotHasKey('contact', $row);

        // A card's contact is `workiz_contact` on the wire.
        $card = A1LeadsClient::card(['workiz_contact' => ['phone' => '6034893132', 'email' => 'travis@example.com']]);
        $this->assertSame('6034893132', $card['contact']['phone']);
    }

    public function test_an_unmatched_review_carries_no_customer(): void
    {
        Carbon::setTestNow('2026-09-25T16:05:00Z');
        $this->page('0', [self::record(1, 'u', ['customer' => ['matched' => false, 'name' => 'A guess']])], '1', false);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $reader = $this->userWithRole($this->t, 'Bridge', ['a1leads_gbp.view']);
        $this->assertNull($this->signedInAs($reader)->getJson('/api/v1/a1leads-gbp')->assertOk()->json('data.reviews.0.customer'));
    }

    public function test_a_copy_that_stopped_syncing_is_not_served(): void
    {
        Carbon::setTestNow('2026-09-25T16:05:00Z');
        $this->page('0', [self::record(1, 'a')], '1', false);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        Carbon::setTestNow('2026-09-25T17:05:00Z');   // an hour with no sync
        $reader = $this->userWithRole($this->t, 'Bridge', ['a1leads_gbp.view']);
        $data = $this->signedInAs($reader)->getJson('/api/v1/a1leads-gbp')->json('data');

        $this->assertArrayNotHasKey('live', $data['source'], 'falls back to the snapshot, which states its own age');
    }

    // ── Rodrigo, suggestion 01a13160 (2026-10-12): one row per review ──

    public function test_an_account_alias_is_one_review_and_its_retirement_keeps_the_survivor(): void
    {
        $this->page('0', [
            self::via('alias', self::record(1, 'r', ['rating' => 1], replyText: 'Sorry about that')),
            self::via('canon', self::record(2, 'r', ['rating' => 1])),
            // The alias retired, after the survivor was asserted.
            ['seq' => 3, 'business_id' => 1, 'review_ref' => 'accounts/alias/locations/l/reviews/r', 'kind' => 'review',
                'tombstone' => true, 'tombstone_reason' => 'account_alias',
                'canonical_review_ref' => 'accounts/canon/locations/l/reviews/r', 'review' => null],
            // A plain delete of the retired alias name is not the survivor's.
            ['seq' => 4, 'business_id' => 1, 'review_ref' => 'accounts/alias/locations/l/reviews/r', 'kind' => 'deleted', 'tombstone' => true, 'review' => null],
        ], '4', false);

        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $rows = $this->rows();
        $this->assertSame(['accounts/canon/locations/l/reviews/r'], array_keys($rows), 'one row, written through the canonical name');
        $this->assertSame('Sorry about that', $rows['accounts/canon/locations/l/reviews/r']->reply['text'], 'the sent reply is kept');
    }

    public function test_a_page_applied_twice_or_cut_short_before_its_cursor_leaves_no_duplicate(): void
    {
        $this->page('0', [self::via('a', self::record(1, 'r')), self::via('b', self::record(2, 's'))], '2', true);
        $this->page('2', [self::via('c', self::record(3, 'r')), self::via('c', self::record(4, 's'))], '4', false);

        // Interrupted: page 2 refused, so the cursor stops after page 0.
        $this->refuseAt = '2';
        $this->artisan('ezbizi:a1leads-delta-sync')->assertFailed();
        $this->assertSame('2', Cache::get(A1LeadsDeltaSync::STATE)['cursor']);

        // Resumed, then the same pages replayed from 0.
        $this->refuseAt = null;
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();
        Cache::forever(A1LeadsDeltaSync::STATE, [...Cache::get(A1LeadsDeltaSync::STATE), 'cursor' => '0']);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $this->assertSame(
            ['accounts/c/locations/l/reviews/r', 'accounts/c/locations/l/reviews/s'],
            array_keys($this->rows()),
            'two reviews, two rows, under the latest name',
        );
    }

    public function test_a_withdrawn_reply_or_match_is_not_the_review_deleted(): void
    {
        $this->page('0', [
            self::via('a', self::record(1, 'r', replyText: 'Thanks')),
            ['seq' => 2, 'business_id' => 1, 'review_ref' => 'accounts/a/locations/l/reviews/r', 'kind' => 'reply', 'tombstone' => true, 'review' => null],
            ['seq' => 3, 'business_id' => 1, 'review_ref' => 'accounts/a/locations/l/reviews/r', 'kind' => 'match', 'tombstone' => true, 'review' => null],
        ], '3', false);

        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $row = $this->rows()['accounts/a/locations/l/reviews/r'] ?? null;
        $this->assertNotNull($row, 'the review stays');
        $this->assertFalse($row->has_reply);
    }

    public function test_a_new_feed_rebuilds_from_zero_and_raises_nothing(): void
    {
        $this->page('0', [self::via('a', self::record(1, 'r', ['rating' => 1]))], '1', false);
        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        // A1 Leads restarted its history: same review, now removed, new feed.
        $this->pages = [];
        $this->page('1', [], '1', false, feedId: 'feed-2');
        $this->page('0', [self::via('a', self::record(1, 'r', ['rating' => 1, 'deleted_at' => '2026-10-12T00:00:00Z'], tombstone: true))], '1', false, feedId: 'feed-2');

        $this->artisan('ezbizi:a1leads-delta-sync')->assertSuccessful();

        $this->assertSame('feed-2', Cache::get(A1LeadsDeltaSync::STATE)['feed_id']);
        $this->assertTrue($this->rows()['accounts/a/locations/l/reviews/r']->removed);
        $this->assertSame(0, AutomationEvent::withoutGlobalScopes()->where('event_key', 'a1leads_gbp.review_removed')->count(), 'a rebuild is not news');
    }
}
