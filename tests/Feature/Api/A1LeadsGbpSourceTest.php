<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\User;
use App\Services\A1Leads\A1LeadsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The A1 Leads bridge: read-only, and honest about how old its data is.
 *
 * ── The failure these are aimed at ───────────────────────────────
 *
 * The data is a daily snapshot of somebody else's system. A refresh that stops
 * running leaves yesterday's file in place and every request still answers 200,
 * so the tab keeps drawing a perfectly plausible list of reviews that is a week
 * old. Nothing errors. The only thing that can catch it is the snapshot's own
 * capture time, travelling with the data and measured against the clock.
 *
 * The second failure is the opposite shape and worse: an unreachable source
 * rendering as an empty list, which says A-1 Chimney has no reviews. It has
 * thirteen thousand.
 */
class A1LeadsGbpSourceTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/a1leads-gbp';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.a1leads.base_url', 'http://127.0.0.1:3456');
        config()->set('services.a1leads.api_key', 'test-partner-key');
        config()->set('services.a1leads.stale_after_hours', 30);
        config()->set('services.a1leads.receipt', $this->receiptFile('2026-09-23T15:01:07.302Z'));
    }

    /**
     * A reader at the organization A1 Leads' data belongs to.
     *
     * Every test here grants the permission AND names the organization, because
     * the permission alone is not the boundary: this module is available to
     * every tenant by default and holds one business's reviews. The test below
     * that varies only the organization is the one that proves it.
     *
     * @param  array<string, mixed>  $t
     */
    private function readerAt(array $t): User
    {
        config()->set('services.a1leads.organization_id', $t['org']->getKey());
        config()->set('services.a1leads.company_id', $t['company']->getKey());

        return $this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']);
    }

    /**
     * One page of A1 Leads' board, in the shape measured on 2026-09-24.
     *
     * Trimmed to the fields that matter here, but nested exactly as the live
     * route nests them - `contact` is an object whose phone and email were
     * null on all 387 real cards, and the fixture keeps them null for the same
     * reason: a fixture that invented a phone number would prove a column the
     * real feed cannot fill.
     *
     * @return array<string, mixed>
     */
    private static function cardsPage(): array
    {
        return [
            'data' => [[
                'review_ref' => 'accounts/a/locations/l/reviews/r-1',
                'status' => 'Working on it',
                'raw_status' => 'working',
                'note' => 'Outreach made, waiting on the customer.',
                'assignee' => 'Adeline',
                'contact' => [
                    'status' => 'none', 'name' => null, 'phone' => null, 'email' => null,
                    'address' => null, 'info' => null, 'matched_lead_id' => 7,
                ],
                'customer_match' => [
                    'status' => 'matched', 'matched' => true,
                    'candidates' => [['customerName' => 'A guess', 'jobId' => 'G1']],
                ],
                // The measured shape of a matched card, 2026-09-25: 8 of 388
                // carry one, with the evidence beside it.
                'customer' => [
                    'matched' => true, 'name' => 'Dana Whitfield', 'phone' => '+15555550101',
                    'service_address' => '12 Elm St, Warren, MI', 'technician' => null,
                    'workiz_tenant' => 'nationwide', 'workiz_url' => 'https://app.workiz.com/job/ABC123',
                    'last_job' => ['id' => 'ABC123', 'number' => 100077846, 'date' => '2026-09-01', 'tech' => 'Amit'],
                    'evidence' => ['source' => 'workiz', 'jobId' => 'ABC123'],
                ],
                'report' => ['dishonest' => false, 'reasons' => ['r'], 'state' => 'queued', 'reason' => 'why'],
                'updates' => [['id' => 1, 'author' => 'Tiara', 'body' => 'Called, left voicemail.', 'created_at' => '2026-09-22T14:00:00Z']],
            ]],
            'meta' => ['count' => 1, 'has_more' => false, 'next_cursor' => null],
        ];
    }

    /** A receipt on disk, because that is how the real one arrives. */
    private function receiptFile(string $capturedAt, ?string $installedAt = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'a1receipt');

        file_put_contents($path, json_encode([
            'installed_generation' => '20260923T150107Z-23510eb0',
            'source_release_sha' => 'eb72dbcd42b88dca8de2281a4847f09fb8d61472',
            'snapshot_captured_at' => $capturedAt,
            'installed_at' => $installedAt ?? $capturedAt,
        ]));

        return $path;
    }

    private function fakeA1Leads(): void
    {
        Http::fake([
            '*/_info' => Http::response(['version' => '1.10.1'], 200),
            '*/api/v1/reviews/summary' => Http::response(['data' => ['total' => 13169]], 200),
            // Before `reviews*`, which would otherwise answer for it: Http::fake
            // takes the FIRST pattern that matches, and this URL matches both.
            '*/api/v1/reviews/workflow/cards*' => Http::response(self::cardsPage(), 200),
            '*/api/v1/reviews*' => Http::response([
                'data' => [['google_review_name' => 'accounts/a/locations/l/reviews/r-1', 'rating' => 5]],
                'meta' => ['total' => 13169],
            ], 200),
            '*/api/v1/gbp/health' => Http::response(['data' => ['locations' => 3]], 200),
            /*
             * The REAL shape, captured from the live service after Rodrigo's
             * release c7ac999b was installed on 2026-09-24: 46 rows, every one
             * carrying `gbp_location_name`. The day before it carried no id at
             * all, which is why this filter was briefly a list of cities.
             */
            '*/api/v1/gbp/locations' => Http::response(['data' => [
                [
                    'gbp_location_name' => 'locations/111',
                    'title' => 'A1 Chimney',
                    'address' => '8145 Austin Ave, Morton Grove, IL, 60053',
                    'zip' => '60053',
                ],
                [
                    'gbp_location_name' => 'locations/222',
                    'title' => 'A1 Chimney',
                    'address' => '101 Arch St #831, Boston, MA, 02110',
                    'zip' => '02110',
                    'rating' => 4.8,
                    'reviews' => 221,
                ],
                // A service area, which has no one city.
                [
                    'gbp_location_name' => 'locations/333',
                    'title' => 'A1 Chimney',
                    'address' => 'Serves Cobb County, GA, USA, Henry County, GA, USA +8 more',
                    'zip' => null,
                ],
                // No id: cannot be filtered on, so it must not be offered.
                [
                    'title' => 'A1 Chimney',
                    'address' => '1 Nowhere Rd, Nowhere, TX, 75001',
                    'zip' => '75001',
                ],
            ]], 200),
        ]);

        // Anything this file does not stub is a real request leaving the
        // machine, and the first one only surfaced as a two-second cURL timeout
        // inside an unrelated assertion.
        Http::preventStrayRequests();
    }

    /** THE POSITIVE CONTROL, first: the tab reads and says when the data is from. */
    public function test_it_returns_reviews_with_the_snapshot_date_beside_them(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Alpha');
        $reader = $this->readerAt($t);

        $data = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertTrue($data['source']['configured']);
        $this->assertTrue($data['source']['reachable']);
        $this->assertFalse($data['source']['stale']);
        $this->assertSame('20260923T150107Z-23510eb0', $data['source']['generation']);
        $this->assertCount(1, $data['reviews']);
        $this->assertSame(13169, $data['summary']['total']);
    }

    /**
     * A snapshot older than the window is STALE while still answering 200.
     *
     * This is the case the whole design exists for. Nothing about the response
     * changes except the flag: the reviews are still there, still plausible,
     * and a day and a half out of date.
     */
    public function test_a_snapshot_past_the_window_is_marked_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25T02:00:00Z'));
        config()->set('services.a1leads.receipt', $this->receiptFile('2026-09-23T15:01:07.302Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Bravo');
        $reader = $this->readerAt($t);

        $source = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data.source');

        $this->assertTrue($source['stale'], 'a snapshot older than the window must say so');
        $this->assertGreaterThan(30, $source['hours_old']);
        $this->assertTrue($source['reachable'], 'and it is stale rather than unreachable');
    }

    /**
     * Age is measured from the CAPTURE, not the install.
     *
     * The discriminating case: a refresh that reinstalls the same old snapshot
     * has a fresh install time and stale data. Measuring the wrong one makes
     * exactly that look healthy.
     */
    public function test_a_fresh_install_of_old_data_is_still_stale(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-25T02:00:00Z'));
        config()->set('services.a1leads.receipt', $this->receiptFile(
            '2026-09-23T15:01:07.302Z',
            '2026-09-25T01:59:00Z',
        ));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Charlie');
        $reader = $this->readerAt($t);

        $source = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data.source');

        $this->assertTrue($source['stale'], 'installed a minute ago, captured a day and a half ago');
    }

    /**
     * An unreachable source is said out loud, never drawn as an empty business.
     */
    public function test_a_refusal_is_reported_as_a_source_problem(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        Http::fake([
            '*/_info' => Http::response(['version' => '1.10.1'], 200),
            // What the consumer policy answers for a route it does not list.
            '*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $t = $this->provisionTenant('Delta');
        $reader = $this->readerAt($t);

        $data = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertFalse($data['source']['reachable']);
        $this->assertStringContainsString('403', (string) $data['source']['because']);
        $this->assertSame([], $data['reviews']);
        $this->assertNull($data['summary']);
    }

    /** With no key at all it says so, rather than pretending to be empty. */
    public function test_an_unconfigured_environment_says_so(): void
    {
        config()->set('services.a1leads.api_key', null);

        $t = $this->provisionTenant('Echo');
        $reader = $this->readerAt($t);

        $source = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data.source');

        $this->assertFalse($source['configured']);
        $this->assertStringContainsString('key', (string) $source['because']);
    }

    /** The tab is its own grant, and our own modules do not open it. */
    public function test_the_permission_is_its_own(): void
    {
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Foxtrot');

        config()->set('services.a1leads.organization_id', $t['org']->getKey());

        $this->signedInAs($this->userWithRole($t, 'Reviews', ['reviews.view']))
            ->getJson(self::ENDPOINT)
            ->assertForbidden();
    }

    /**
     * THE PERMISSION IS NOT THE BOUNDARY: the organization is.
     *
     * A module with no platform flag is available to every organization -
     * `ModuleAvailability` fails open, deliberately - so an administrator at
     * any other tenant can grant themselves `a1leads_gbp.view` from their own
     * Roles screen. A1 Leads holds one business's thirteen thousand reviews,
     * and no role at another company can say anything about whose they are.
     *
     * The two halves are asserted in one run against the same fake, so the
     * refusal cannot be an unreachable service or a missing key.
     */
    public function test_only_the_organization_the_data_belongs_to_may_read_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $owner = $this->provisionTenant('Owner');
        $stranger = $this->provisionTenant('Stranger');

        // Named to the owner, and both users hold the identical grant.
        config()->set('services.a1leads.organization_id', $owner['org']->getKey());
        config()->set('services.a1leads.company_id', $owner['company']->getKey());

        $mine = $this->signedInAs($this->userWithRole($owner, 'Bridge', ['a1leads_gbp.view']))
            ->getJson(self::ENDPOINT)->assertOk()->json('data');

        $theirs = $this->signedInAs($this->userWithRole($stranger, 'Bridge', ['a1leads_gbp.view']))
            ->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertTrue($mine['source']['configured'], 'the positive control');
        $this->assertNotEmpty($mine['reviews']);

        $this->assertFalse($theirs['source']['configured']);
        $this->assertSame([], $theirs['reviews'], 'and not one row of somebody else\'s reviews');
        $this->assertStringContainsString(
            'not the one it belongs to',
            (string) $theirs['source']['because'],
            'and it says which kind of nothing this is',
        );
    }

    /**
     * THE COMPANY IS THE BOUNDARY TOO: another company in the SAME organization
     * reads nothing. Jose, 2026-09-24: "this belongs to a-1chimney ... right
     * now i can see it everywhere no matter what company or franchise im in".
     *
     * One user, one run, one fake - only the view changes, so the refusal
     * cannot be a missing grant or an unreachable service. The franchise case
     * reaches the franchise branch of the rule, which the organization and
     * company views do not.
     */
    public function test_only_the_company_the_data_belongs_to_may_read_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('A1-Group');
        $reader = $this->readerAt($t);
        $sibling = $this->inTenant($t['org'], fn () => Company::create([
            'organization_id' => $t['org']->id,
            'name' => 'Everdoors',
        ]));

        $as = fn (string $view) => $this->signedInAs($reader)
            ->withHeader('X-EzBizi-View', $view)
            ->getJson(self::ENDPOINT)->assertOk()->json('data');

        $organization = $as('organization');
        $owner = $as('company:'.$t['company']->id);
        $ownFranchise = $as('franchise:'.$t['franchise']->id);
        $other = $as('company:'.$sibling->id);

        $this->assertTrue($organization['source']['configured'], 'the organization view contains A-1 Chimney');
        $this->assertNotEmpty($owner['reviews'], 'the positive control');
        $this->assertNotEmpty($ownFranchise['reviews'], 'a franchise of the owning company');

        $this->assertFalse($other['source']['configured']);
        $this->assertSame([], $other['reviews'], 'not one row of A-1 Chimney\'s reviews under Everdoors');
        $this->assertStringContainsString('Switch to A-1 Chimney', (string) $other['source']['because']);
    }

    /** No company named: nobody has said whose reviews these are, so nobody reads them. */
    public function test_an_unnamed_company_reads_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Hotel');
        $reader = $this->readerAt($t);
        config()->set('services.a1leads.company_id', null);

        $data = $this->signedInAs($reader)->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertFalse($data['source']['configured']);
        $this->assertSame([], $data['reviews']);
    }

    /**
     * Unset fails CLOSED, which is the opposite of the module flag.
     *
     * An absent platform flag means nobody has restricted the module. An absent
     * organization here means nobody has said whose data this is, and the safe
     * answer to that is nobody.
     */
    public function test_an_unnamed_organization_reads_nothing(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Golf');
        config()->set('services.a1leads.organization_id', null);

        $data = $this->signedInAs($this->userWithRole($t, 'Bridge', ['a1leads_gbp.view']))
            ->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertFalse($data['source']['configured']);
        $this->assertSame([], $data['reviews']);
    }

    /**
     * THE LOCATION FILTER IS ONE ENTRY PER LISTING, on its exact GBP id.
     *
     * Until Rodrigo's release c7ac999b, `/api/v1/gbp/locations` carried no id
     * and the filter had to be `city`, which merges two listings in one city.
     * Now each row carries `gbp_location_name` and `/api/v1/reviews` filters on
     * exactly that value.
     *
     * The discriminating row is the one with NO id: it cannot be filtered on,
     * so it must not be offered - a dropdown entry that narrows to nothing is
     * worse than an absence. And a service area is labelled with its own
     * address, because it has no single city to name.
     */
    public function test_the_location_filter_offers_each_listing_by_its_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Juliett');

        $locations = $this->signedInAs($this->readerAt($t))
            ->getJson(self::ENDPOINT)->assertOk()->json('data.locations');

        $this->assertSame(
            [
                ['name' => 'locations/222', 'label' => 'Boston, MA'],
                ['name' => 'locations/111', 'label' => 'Morton Grove, IL'],
                ['name' => 'locations/333', 'label' => 'Serves Cobb County, GA, USA, Henry County, GA, USA +8 more'],
            ],
            $locations,
            'one entry per listing with an id, labelled by place, the id-less row left out',
        );
    }

    /**
     * STATUS AND NOTES ARRIVE BESIDE THE REVIEW THEY BELONG TO.
     *
     * Joined on `google_review_name` = the card's `review_ref`. The positive
     * half is the joined card; the negative half is what does NOT travel -
     * match evidence, match candidates and report reasoning are operator data
     * Rodrigo asked us not to pass around.
     *
     * The customer and job context DOES travel since his cutover checklist
     * (row 5): the matched Workiz customer, their last job and technician,
     * the assignee, the report state and the updates.
     */
    public function test_the_board_status_and_note_are_joined_onto_the_review(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Kilo');

        $data = $this->signedInAs($this->readerAt($t))
            ->getJson(self::ENDPOINT)->assertOk()->json('data');

        $card = $data['reviews'][0]['board'];

        $this->assertTrue($data['board']['available']);
        $this->assertSame('Working on it', $card['status']);
        $this->assertSame('Outreach made, waiting on the customer.', $card['note']);
        $this->assertSame(['name' => null, 'phone' => null, 'email' => null], $card['contact']);

        $this->assertSame([
            'name' => 'Dana Whitfield', 'phone' => '+15555550101', 'address' => '12 Elm St, Warren, MI',
            'technician' => 'Amit', 'job_number' => '100077846', 'job_date' => '2026-09-01',
            'workiz_url' => 'https://app.workiz.com/job/ABC123',
        ], $card['customer'], 'the technician falls back to the last job');
        $this->assertSame('Adeline', $card['assignee']);
        $this->assertSame('queued', $card['report_state']);
        $this->assertSame([['author' => 'Tiara', 'body' => 'Called, left voicemail.', 'at' => '2026-09-22T14:00:00Z']], $card['updates']);

        $this->assertArrayNotHasKey('customer_match', $card, 'candidates are guesses and stay on their side');
        $this->assertArrayNotHasKey('evidence', $card['customer']);
        $this->assertArrayNotHasKey('report', $card, 'report reasoning stays on their side');
        $this->assertArrayNotHasKey('cards', $data['board'], 'the whole board is never sent to the screen');
    }

    /**
     * Only a MATCHED customer travels. A1 Leads also sends customers it has
     * not matched (a name it guessed from the review); drawing one on the card
     * would present a guess as A-1's customer.
     */
    public function test_an_unmatched_customer_is_not_passed_on(): void
    {
        $card = new \ReflectionMethod(A1LeadsClient::class, 'card');

        $unmatched = $card->invoke(null, [
            'status' => 'Working on it',
            'customer' => ['matched' => false, 'name' => 'A guess', 'phone' => '+15555550199'],
        ]);
        $matched = $card->invoke(null, [
            'status' => 'Working on it',
            'customer' => ['matched' => true, 'name' => 'Dana Whitfield'],
        ]);

        $this->assertNull($unmatched['customer']);
        $this->assertSame('Dana Whitfield', $matched['customer']['name'], 'the control');
    }

    /**
     * A board that cannot be read leaves the reviews standing.
     *
     * Rodrigo's revocation is "remove only this new policy entry", after which
     * the route answers 403. That must cost the tab two columns, not the whole
     * page - and the sentence must say which kind of nothing it is.
     */
    public function test_a_refused_board_costs_two_columns_not_the_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24T18:00:00Z'));
        Http::fake([
            '*/_info' => Http::response(['version' => '1.10.1'], 200),
            '*/api/v1/reviews/summary' => Http::response(['data' => ['total' => 13169]], 200),
            '*/api/v1/reviews/workflow/cards*' => Http::response(['error' => 'forbidden'], 403),
            '*/api/v1/reviews*' => Http::response([
                'data' => [['google_review_name' => 'accounts/a/locations/l/reviews/r-1', 'rating' => 1]],
                'meta' => ['total' => 1],
            ], 200),
            '*/api/v1/gbp/health' => Http::response(['data' => []], 200),
            '*/api/v1/gbp/locations' => Http::response(['data' => []], 200),
        ]);
        Http::preventStrayRequests();

        $t = $this->provisionTenant('Lima');

        $data = $this->signedInAs($this->readerAt($t))
            ->getJson(self::ENDPOINT)->assertOk()->json('data');

        $this->assertTrue($data['source']['reachable'], 'the source is fine');
        $this->assertCount(1, $data['reviews'], 'and the reviews are still there');
        $this->assertNull($data['reviews'][0]['board']);
        $this->assertFalse($data['board']['available']);
        $this->assertStringContainsString('403', (string) $data['board']['because']);
    }

    /**
     * EVERY PAGE, and no `after` on the first.
     *
     * Rodrigo's feed truncated at 200 before his fix, and a timestamp-only
     * cursor skipped tied rows. So this follows `next_cursor` while `has_more`,
     * and a card on page two must arrive. The first request must not carry an
     * empty `after=`: the route answers 400 to a parameter it does not expect.
     * (Laravel's client drops a null query value itself - a sabotage that
     * removed our own filter for it stayed green - so this pins the behaviour
     * we rely on rather than code of ours.)
     */
    public function test_the_board_is_read_to_its_last_page(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-24T18:00:00Z'));
        Http::fake([
            '*/api/v1/reviews/workflow/cards*' => Http::sequence()
                ->push([
                    'data' => [['review_ref' => 'ref-1', 'status' => 'Removed', 'note' => null]],
                    'meta' => ['has_more' => true, 'next_cursor' => 'cursor-2'],
                ], 200)
                ->push([
                    'data' => [['review_ref' => 'ref-2', 'status' => 'Awaiting report', 'note' => null]],
                    'meta' => ['has_more' => false, 'next_cursor' => null],
                ], 200),
        ]);
        Http::preventStrayRequests();

        $cards = app(A1LeadsClient::class)->cards();

        $this->assertSame(['ref-1', 'ref-2'], array_keys($cards), 'the second page arrived');
        $this->assertSame('Awaiting report', $cards['ref-2']['status']);

        $sent = Http::recorded()->map(fn ($pair) => $pair[0]->url())->values()->all();

        $this->assertStringNotContainsString('after=', $sent[0], 'no empty cursor on the first page');
        $this->assertStringContainsString('after=cursor-2', $sent[1]);
        $this->assertStringContainsString('business=a1-chimney', $sent[0]);
    }

    /**
     * THE FILTERS REACH THE OTHER SERVICE, which is the whole of the tab.
     *
     * A screen that drew "Negative (1-3 stars)" in its dropdown and sent no
     * `rating` would look correct and answer the wrong question: thirteen
     * thousand five-star reviews on top of the four somebody opened the tab
     * for. The list route is asserted as well as the tab route, because paging
     * that quietly dropped the filters would widen the list a page at a time.
     */
    public function test_the_filters_are_passed_through_to_a1_leads(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('Hotel');
        $reader = $this->readerAt($t);

        $this->signedInAs($reader)
            ->getJson(self::ENDPOINT.'/reviews?rating=negative&replied=false&matched=true'
                .'&sort=oldest&from=2026-01-01T00:00:00Z&offset=40&limit=25')
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/reviews')) {
                return false;
            }

            return str_contains($request->url(), 'rating=negative')
                && str_contains($request->url(), 'replied=false')
                && str_contains($request->url(), 'matched=true')
                && str_contains($request->url(), 'sort=oldest')
                && str_contains($request->url(), 'offset=40')
                && str_contains($request->url(), 'limit=25');
        });
    }

    /**
     * And a filter neither side understands does not travel.
     *
     * The discriminating half of the test above: a pass-through built by
     * forwarding the query string wholesale would satisfy it. A1 Leads ignores
     * what it does not know, so a filter that reached it and did nothing would
     * come back as a list that looks narrowed and is not.
     */
    public function test_a_filter_neither_side_knows_is_dropped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        $t = $this->provisionTenant('India');

        $this->signedInAs($this->readerAt($t))
            ->getJson(self::ENDPOINT.'/reviews?rating=5&franchise_id=abc&order_by=whatever')
            ->assertOk();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/reviews')) {
                return false;
            }

            return str_contains($request->url(), 'rating=5')
                && ! str_contains($request->url(), 'franchise_id')
                && ! str_contains($request->url(), 'order_by');
        });
    }

    /**
     * THE STRUCTURAL REFUSAL: this client cannot be made to write.
     *
     * The host refuses mutations two ways already, and neither is ours. This
     * asserts the third, which is: there is no method here that can produce
     * anything but a GET, and a route outside the allowed six fails before it
     * reaches the network rather than as somebody else's 403.
     */
    public function test_the_client_cannot_reach_a_route_it_was_not_granted(): void
    {
        $this->fakeA1Leads();

        $client = app(A1LeadsClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no route');

        // Every public method is a named read; this is the only way to ask for
        // something else, and it is refused here rather than over the wire.
        (fn () => $this->get('leads'))->call($client);
    }

    /** And the key travels in a header, never in the URL. */
    public function test_the_key_is_never_put_in_the_query_string(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-23T18:00:00Z'));
        $this->fakeA1Leads();

        app(A1LeadsClient::class)->reviewsSummary();

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/api/v1/reviews/summary')) {
                return true;
            }

            return ! str_contains($request->url(), 'test-partner-key')
                && $request->header('X-API-Key')[0] === 'test-partner-key';
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
