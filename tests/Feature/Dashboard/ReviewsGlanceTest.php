<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Review;
use App\Models\ReviewReply;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\ReviewsGlance;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Reviews glance, and three sentences that spoke for the whole business.
 *
 * ── Why this file exists ──
 *
 * "No reviews yet." was cited to UI/UX as evidence that the board already
 * wrote the compliant plain form, and the ruling went the wrong way for two
 * hours on the strength of it. It was the defect standing where the proof
 * should have been: a claim about the ORGANIZATION, made by a box scoped to
 * one reader's franchise reach. It had no test, which is how it lasted long
 * enough to be quoted.
 *
 * The one that matters most is not the empty state. "Every review has been
 * answered." is the sentence that tells somebody nothing needs their
 * attention, and an unanswered one-star sits in public at the top of the
 * listing while they believe it.
 */
class ReviewsGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org' => $r['organization'], 'admin' => $r['administrator'],
            'company' => $r['company'], 'franchise' => $r['franchise'],
        ];
    }

    /**
     * The frozen clock must not outlive the test that froze it.
     *
     * Without this, the bucket test's 22 September becomes "now" for every case
     * that runs after it in this class, and a fixture written with now()->subDay()
     * lands in a period the next test did not intend.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function review(?Franchise $franchise, ?int $rating = 5, bool $answered = false): Review
    {
        static $n = 0;
        $n++;

        return $this->inTenant(function () use ($franchise, $rating, $answered, $n) {
            $review = Review::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'franchise_id'    => $franchise?->id,
                'remote_name'     => "accounts/a/locations/l/reviews/r-{$n}",
                'location_name'   => 'accounts/a/locations/l',
                'reviewer_name'   => "Reviewer {$n}",
                'rating'          => $rating,
                'reviewed_at'     => now()->subDay(),
            ]);

            if ($answered) {
                ReviewReply::create([
                    'organization_id' => $this->alpha['org']->id,
                    'review_id'       => $review->id,
                    'body'            => 'Thank you.',
                    'status'          => ReviewReply::STATUS_PUBLISHED,
                    'published_at'    => now(),
                ]);
            }

            return $review;
        });
    }

    /** @return array{0: Franchise, 1: User} */
    private function branchReader(): array
    {
        return $this->inTenant(function () {
            $franchise = Franchise::create([
                'organization_id' => $this->alpha['org']->id,
                'company_id'      => $this->alpha['company']->id,
                'name'            => 'Second', 'is_active' => true,
            ]);

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id'         => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name'            => 'Branch', 'email' => 'branch@alpha.test',
                'password'        => 'a-real-password', 'is_active' => true,
            ]);

            Assignment::create([
                'organization_id'       => $this->alpha['org']->id,
                'user_id'               => $user->id,
                'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /** @return array<string, mixed> */
    private function glance(User $user): array
    {
        return app(ReviewsGlance::class)
            ->resolve($user, DashboardPeriod::for($user), [])
            ->toArray()['data'];
    }

    /**
     * The line has one column per period bucket, zeros included.
     *
     * ── The case every other assertion here is blind to ──────────────
     *
     * Raised by Dashboard and the deploy desk, 2026-09-22: this suite asserted
     * the series was NOT EMPTY and never how long it was. A dropped bucket
     * carrying the identity value - a zero - leaves assertNotEmpty true, moves
     * no other number, and is invisible. It is also self-healing: one new row
     * the next day shifts the series and a later correct write restores a green
     * that never covered the gap.
     *
     * That is the case the buckets exist FOR. The docblock on emptyBuckets
     * promises a quiet stretch still draws zeros, and a line going quiet is the
     * thing somebody reads this box to see.
     *
     * ── Why the clock is frozen and the number is written out ────────
     *
     * The default period is the calendar MONTH, so the bucket count depends on
     * today's date - which is why no fixed number could be asserted before. A
     * frozen clock makes it fixed, and the expected count is derived here by
     * hand rather than from the code, so this cannot pass by agreeing with the
     * implementation it is checking:
     *
     *   period `month` runs 1 September to 22 September 2026
     *   buckets are start-of-week (Sunday) dates for those days:
     *     1-5 Sep  -> Sun 30 Aug
     *     6-12 Sep -> Sun 6 Sep
     *     13-19    -> Sun 13 Sep
     *     20-22    -> Sun 20 Sep
     *   = FOUR columns
     */
    public function test_the_series_draws_one_column_per_bucket_including_zeros(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-22 12:00:00', 'America/New_York'));

        $this->review($this->alpha['franchise']);

        /*
         * THE WEEK START IS STATED HERE, NOT INHERITED.
         *
         * The axis below is hand-derived from a SUNDAY week start. Until now
         * that half of the expectation came from the registry default and this
         * test read as though it had chosen it. A neighbouring glance test
         * asserting the same inherited premise failed once, alone, with its
         * first column missing - precisely the axis a MONDAY start draws - and
         * has not reproduced. Cause still open (see CustomersGlanceTest and
         * 726768d0); ordering rules out a peer's test writing the setting.
         *
         * Pinning the input costs a line and makes the expectation depend on a
         * value this test declares rather than one it borrows.
         */
        $this->inTenant(fn () => app(SettingsResolver::class)
            ->set('week_starts_on', $this->alpha['org'], 'sunday'));

        $data = $this->glance($this->alpha['admin']);

        $points = $data['series'][0]['points'];

        /*
         * THE KEYS, NOT A COUNT - Customers' correction, 2026-09-22, and it is
         * the better assertion.
         *
         * A count cannot say the axis STARTS at the period's start rather than
         * at the first day that had data: four columns beginning a week late is
         * still four columns. The labels can only be produced by zero-filling
         * from the period's own start, and nothing supplies them as a fallback.
         */
        $this->assertSame(
            ['2026-08-30', '2026-09-06', '2026-09-13', '2026-09-20'],
            array_column($points, 'at'),
            'the axis must begin at the period start and carry every week of it, '
            .'including the quiet ones - a dropped or shifted bucket changes no '
            .'other number and no other assertion here sees it',
        );

        /*
         * And the VALUES, because an axis with the right labels and no zeros
         * would otherwise pass. The quiet weeks are the whole point: three of
         * the four had nothing, and each must still be a column.
         */
        $this->assertSame(
            [0, 0, 0, 1],
            array_column($points, 'value'),
            'the three quiet weeks must be zeros rather than absent',
        );
    }

    /**
     * The box sends what it draws, and nothing else.
     *
     * ── Why an absence needs the two assertions above it ─────────────
     *
     * `organization_total` sat here for weeks and no test moved when it was
     * removed - this suite asserted the sentences and never the key, which is
     * also how the same field reached FIVE glances, each written by starting
     * from the last. An absence on its own is satisfied by a payload that is
     * empty, errored, or scoped to nothing, so the series and the sentences
     * are asserted PRESENT first: then the missing key means withheld rather
     * than nothing happened.
     *
     * Written against the whole payload rather than that one key, so a sixth
     * organization-wide figure added by the same copy cannot pass it.
     */
    public function test_the_box_sends_no_figure_it_does_not_draw(): void
    {
        $this->review($this->alpha['franchise']);
        $this->review($this->alpha['franchise'], rating: 3);

        $data = $this->glance($this->alpha['admin']);

        // The controls: this payload is populated, so the absence below is a
        // decision rather than an empty answer.
        $this->assertFalse($data['empty']);
        $this->assertNotEmpty($data['series'][0]['points']);
        $this->assertNotEmpty($data['sentences']);

        $this->assertSame(
            ['empty', 'series', 'slices', 'sentences', 'figures', 'attention', 'notes'],
            array_keys($data),
            'the Reviews box draws a line, its slices, its sentences and its figures; any other '
            .'key is a query on every dashboard load for a figure nobody sees, and '
            .'an organization-wide one reaches a reader scoped to one franchise',
        );
    }

    public function test_a_reader_who_sees_none_is_not_told_the_business_has_none(): void
    {
        [, $branch] = $this->branchReader();

        $this->review($this->alpha['franchise']);
        $this->review($this->alpha['franchise']);

        $data = $this->glance($branch);

        $this->assertTrue($data['empty']);
        $this->assertSame('No reviews in this view.', $data['sentence']);

        // The control: the organization really does hold reviews, so "yet" was
        // false here rather than merely clumsy.
        $this->assertSame(2, $this->inTenant(fn () => Review::query()->withoutGlobalScopes()->count()));
    }

    /**
     * The most expensive sentence on the box.
     *
     * A branch reader whose own review is answered used to be told that EVERY
     * review had been — while an unanswered one sat in another branch, in
     * public, at the top of the listing.
     */
    public function test_all_answered_speaks_for_the_view_and_not_the_business(): void
    {
        [$second, $branch] = $this->branchReader();

        $this->review($second, answered: true);
        $this->review($this->alpha['franchise'], answered: false);

        $data = $this->glance($branch);

        $this->assertFalse($data['empty']);
        $this->assertContains('Every review in this view has been answered.', $data['sentences']);

        // The control, on the other side of the boundary: the administrator
        // sees the one nobody has answered, so the reassurance above was about
        // reach and not about an organization with nothing outstanding.
        $this->assertContains('1 review unanswered.', $this->glance($this->alpha['admin'])['sentences']);
    }

    /**
     * Google occasionally returns a review with no stars. Those reviews are
     * HERE, holding no rating — not awaited, which is what "yet" claimed.
     */
    public function test_unrated_reviews_are_described_rather_than_awaited(): void
    {
        $this->review(null, rating: null);
        $this->review(null, rating: null);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertContains('No star ratings across 2.', $data['sentences']);

        foreach ($data['sentences'] as $sentence) {
            $this->assertStringNotContainsString(' yet', $sentence);
            $this->assertStringNotContainsString('in reach', $sentence);
        }
    }

    /** With stars, the same slot reads the same shape. */
    public function test_the_average_names_what_it_averaged(): void
    {
        $this->review(null, rating: 5);
        $this->review(null, rating: 4);

        $this->assertContains(
            '4.5 stars across 2.',
            $this->glance($this->alpha['admin'])['sentences'],
        );
    }

    /**
     * The headline compares today so far with yesterday to the same hour.
     *
     * `review()` dates each review a day before the clock. Yesterday
     * afternoon's review is after noon, so a whole-day comparison would say 2
     * and the same span says 1.
     */
    public function test_the_arrived_figure_compares_with_the_same_span_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', 'America/New_York'));
        $this->review(null, answered: true); // left 10:00 yesterday
        Carbon::setTestNow(Carbon::parse('2026-08-24 13:00:00', 'America/New_York'));
        $this->review(null); // left 13:00 yesterday, after the span

        Carbon::setTestNow(Carbon::parse('2026-08-25 09:00:00', 'America/New_York'));
        $this->review(null); // left 09:00 today
        $this->review(null);
        $this->review(null, answered: true);

        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $data = app(ReviewsGlance::class)
            ->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'day'), [])
            ->toArray()['data'];

        $this->assertSame([
            ['key' => 'arrived', 'label' => 'arrived today', 'value' => 3, 'previous' => 1],
            ['key' => 'unanswered', 'label' => 'unanswered', 'value' => 3, 'previous' => null],
        ], $data['figures']);
    }

    /** With nothing anywhere, the same sentence is simply true. */
    public function test_an_organization_with_no_reviews_says_the_same_thing(): void
    {
        $data = $this->glance($this->alpha['admin']);

        $this->assertTrue($data['empty']);
        $this->assertSame('No reviews in this view.', $data['sentence']);
    }
}
