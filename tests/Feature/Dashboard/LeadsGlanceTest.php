<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Lead;
use App\Models\LeadConversation;
use App\Models\SocialConnection;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\LeadsGlance;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Leads glance, and the one sentence on it that was a claim.
 *
 * ── Why this file exists ──
 *
 * It did not before, which is how "No enquiries yet." survived on the most
 * scope-sensitive box on the board. The glance filters by franchise reach, so
 * the reader it speaks to may be seeing a slice; "yet" told them the BUSINESS
 * had none. In this module that is the worst available error: the standing
 * instruction on Leads is that not a single enquiry may be missed, and a box
 * that says "none yet" to somebody whose branch is empty reports the quiet
 * week on the morning somebody else is fielding forty.
 *
 * The strings are pinned deliberately. A board-wide ruling moved them
 * (UI/UX 30ff2369) and `docs/UI-DESIGN-QUEUE.md` is what decides; this test is
 * what makes a stale proposal go red instead of shipping.
 */
class LeadsGlanceTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        // Permissions are a PLATFORM table filled by the module registry
        // rather than by provisioning, so a scoped reader needs the rows.
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

    private ?SocialConnection $account = null;

    /**
     * The account these enquiries arrived through.
     *
     * Every row here is `local_services`, and since 2026-09-16 a Google or
     * Thumbtack enquiry with no connection is left out of this count - it means
     * the account was disconnected, and the box must agree with the Leads list
     * beside it. Without this the fixtures were rows that cannot exist in
     * production and the counts below would be measuring the wrong absence.
     */
    private function account(): SocialConnection
    {
        return $this->account ??= $this->inTenant(function () {
            $row = SocialConnection::create([
                'organization_id' => $this->alpha['org']->id,
                'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id'        => $this->alpha['org']->id,
                'provider'        => SocialProvider::GoogleAds->value,
                'remote_id'       => '4051729356',
                'display_name'    => 'A1 Chimney',
            ]);
            $row->connect(['refresh_token' => 'rt-good']);
            $row->save();

            return $row;
        });
    }

    private function lead(?Franchise $franchise, string $status = Lead::STATUS_NEW, bool $test = false): Lead
    {
        static $n = 0;
        $n++;

        $account = $this->account();

        return $this->inTenant(fn () => Lead::create([
            'organization_id' => $this->alpha['org']->id,
            'company_id'      => $this->alpha['company']->id,
            'franchise_id'    => $franchise?->id,
            'connection_id'   => $account->getKey(),
            'source'          => Lead::SOURCE_LOCAL_SERVICES,
            'is_test'         => $test,
            'remote_id'       => 'L-'.$n,
            'status'          => $status,
            'received_at'     => now()->subHour(),
        ]));
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
                'organization_id'     => $this->alpha['org']->id,
                'user_id'             => $user->id,
                'assigned_franchise_id' => $franchise->id,
            ]);

            return [$franchise, $user];
        });
    }

    /** @return array<string, mixed> */
    private function glance(User $user): array
    {
        return app(LeadsGlance::class)
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

        $this->lead($this->alpha['franchise']);

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
     * `organization_total` sat in this payload and came out with the source
     * filter; no test moved when it did, which is also how the same field
     * reached FIVE glances, each written by starting from the last.
     *
     * The two controls above the assertion are the point: an absence on its
     * own is satisfied by a payload that is empty, errored, or scoped to
     * nothing. With the series and the sentences asserted PRESENT, a missing
     * key means withheld. Pinned as the whole key list rather than by name, so
     * a sixth organization-wide figure added the same way cannot pass it.
     */
    public function test_the_box_sends_no_figure_it_does_not_draw(): void
    {
        $this->lead($this->alpha['franchise']);
        $this->lead($this->alpha['franchise'], status: Lead::STATUS_BOOKED);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertNotEmpty($data['series'][0]['points']);
        $this->assertNotEmpty($data['sentences']);

        $this->assertSame(
            ['empty', 'series', 'slices', 'sentences', 'figures', 'notes'],
            array_keys($data),
            'the Leads box draws a line, its slices, its sentences and its figures; any other key '
            .'is a query on every dashboard load for a figure nobody sees, and an '
            .'organization-wide one reaches a reader scoped to one franchise',
        );
    }

    /**
     * THE DEFECT. A reader who sees none is not told the business has none.
     *
     * The fixture is the real shape: enquiries placed in one branch, read by a
     * manager of another. Both leads exist, neither is theirs, and the old
     * sentence announced that none had ever arrived.
     */
    public function test_a_reader_who_sees_none_is_not_told_the_business_has_none(): void
    {
        [$second, $branch] = $this->branchReader();

        $this->lead($this->alpha['franchise']);
        $this->lead($this->alpha['franchise']);

        $data = $this->glance($branch);

        $this->assertTrue($data['empty']);
        $this->assertSame('No enquiries in this view.', $data['sentence']);

        // The control, and it is what makes the assertion above readable: the
        // organization really does hold enquiries, so "yet" was false here
        // rather than merely clumsy. Without this the test passes on an empty
        // organization, where every wording is true.
        $this->assertSame(2, $this->inTenant(fn () => Lead::query()->withoutGlobalScopes()->count()));
        $this->assertNotNull($second->id);
    }

    /**
     * The second control: the same reader, with one enquiry that IS theirs.
     *
     * Without it, an empty box proves nothing — a glance that returned empty
     * for this user whatever the data would pass the test above, and the
     * sentence would be honest about a scoping bug rather than about scope.
     */
    public function test_the_same_reader_sees_a_lead_placed_in_their_own_branch(): void
    {
        [$second, $branch] = $this->branchReader();

        $this->lead($this->alpha['franchise']);
        $this->lead($second);

        $data = $this->glance($branch);

        $this->assertFalse($data['empty']);
        $this->assertContains('1 lead not yet dealt with.', $data['sentences']);
    }

    /**
     * An organization-wide enquiry belongs to everybody who can open the box.
     *
     * Most enquiries have no franchise at all — the connection is scoped at
     * the top — so a glance that filtered on the column alone would show every
     * reader nothing, and the empty sentence would be drawn constantly.
     */
    public function test_an_unplaced_enquiry_reaches_a_branch_reader(): void
    {
        [, $branch] = $this->branchReader();

        $this->lead(null);

        $data = $this->glance($branch);

        $this->assertFalse($data['empty']);
        $this->assertContains('1 lead not yet dealt with.', $data['sentences']);
    }

    /** With nothing anywhere, the same sentence is simply true. */
    public function test_an_organization_with_no_enquiries_says_the_same_thing(): void
    {
        $data = $this->glance($this->alpha['admin']);

        $this->assertTrue($data['empty']);
        $this->assertSame('No enquiries in this view.', $data['sentence']);
    }

    /**
     * The count lines keep their qualifiers, and one of them keeps "yet".
     *
     * "not yet dealt with" is the box describing its own rows, not a claim
     * about the organization, so the ruling that removed "Nothing posted yet."
     * does not reach it. Pinned here so a later sweep that greps for the word
     * goes red rather than quietly flattening the only number on this board
     * that stands for a person waiting for a call back.
     */
    public function test_the_counts_qualify_themselves_and_never_claim_the_business(): void
    {
        $this->lead(null);
        $this->lead(null);
        $this->lead(null, Lead::STATUS_ARCHIVED);

        $data = $this->glance($this->alpha['admin']);

        $this->assertFalse($data['empty']);
        $this->assertContains('2 leads not yet dealt with.', $data['sentences']);
        // The period word is the dashboard's, not ours: `month` is the
        // default, and asserting the whole sentence keeps the number and its
        // qualifier pinned together.
        $this->assertContains('3 arrived this month.', $data['sentences']);

        foreach ($data['sentences'] as $sentence) {
            $this->assertStringNotContainsString('in reach', $sentence);
        }
    }

    /**
     * A specimen must not raise the alarm this box exists to raise.
     *
     * `ezbizi:thumbtack-test-lead` fires Thumbtack's own webhook, so the row
     * it produces is real in every respect except the customer. The number
     * this glance leads with means somebody is waiting for a call back, and a
     * test enquiry sitting in it is a false alarm of exactly the kind the
     * module's standing instruction is about, pointing the other way.
     */
    public function test_a_specimen_enquiry_is_not_counted_as_somebody_waiting(): void
    {
        $this->lead(null, test: true);

        $data = $this->glance($this->alpha['admin']);

        // The control, and it is the whole test: the row EXISTS and is
        // reachable by this reader, so an empty box here can only be the
        // marker doing its work rather than an empty table.
        $this->assertSame(1, $this->inTenant(fn () => Lead::query()->withoutGlobalScopes()->count()));
        $this->assertTrue($data['empty']);
        $this->assertSame('No enquiries in this view.', $data['sentence']);
    }

    /**
     * The headline compares today so far with yesterday to the same hour.
     *
     * Yesterday afternoon's enquiry is after noon, so a whole-day comparison
     * would say 2 and the same span says 1.
     */
    public function test_the_arrived_figure_compares_with_the_same_span_the_day_before(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-23 11:00:00', 'America/New_York'));
        $this->lead(null); // received 10:00 yesterday
        Carbon::setTestNow(Carbon::parse('2026-08-23 14:00:00', 'America/New_York'));
        $this->lead(null); // received 13:00 yesterday, after the span

        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $this->lead(null);
        $this->lead(null);
        $this->lead(null, Lead::STATUS_BOOKED);

        $data = app(LeadsGlance::class)
            ->resolve($this->alpha['admin'], DashboardPeriod::for($this->alpha['admin'], 'day'), [])
            ->toArray()['data'];

        $this->assertSame([
            ['key' => 'arrived', 'label' => 'arrived today', 'value' => 3, 'previous' => 1],
            ['key' => 'waiting', 'label' => 'not yet dealt with', 'value' => 4, 'previous' => null],
        ], $data['figures']);
    }

    /** Customers who spoke last and can be answered: red once overdue, amber before. */
    public function test_waiting_customers_are_the_attention_rows(): void
    {
        $said = fn (Lead $lead, string $type, int $minutesAgo) => $this->inTenant(fn () => $lead->forceFill([
            'lead_type' => $type,
            'last_contact_from' => LeadConversation::FROM_CONSUMER,
            'last_contact_at' => now()->subMinutes($minutesAgo),
        ])->save());

        $said($this->lead(null), 'MESSAGE', 30);
        $said($this->lead(null), 'MESSAGE', 40);
        $said($this->lead(null), 'MESSAGE', 2);
        $said($this->lead(null), 'PHONE_CALL', 30);          // a call has no reply box
        $said($this->lead(null), 'MESSAGE', 60 * 30);        // left for over a day

        $this->assertSame([
            ['tone' => 'bad', 'text' => '2 customers waiting over 10 minutes for a reply'],
            ['tone' => 'caution', 'text' => '1 customer waiting for a reply'],
        ], $this->glance($this->alpha['admin'])['attention']);
    }

    public function test_a_specimen_beside_real_enquiries_is_left_out_of_every_count(): void
    {
        $this->lead(null);
        $this->lead(null);
        $this->lead(null, test: true);

        $data = $this->glance($this->alpha['admin']);

        $this->assertContains('2 leads not yet dealt with.', $data['sentences']);
        $this->assertContains('2 arrived this month.', $data['sentences']);
    }
}
