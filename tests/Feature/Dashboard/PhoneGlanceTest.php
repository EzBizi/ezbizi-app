<?php

namespace Tests\Feature\Dashboard;

use App\Models\Assignment;
use App\Models\PhoneCall;
use App\Models\PhoneConnection;
use App\Models\PhoneNumber;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Dashboard\DashboardPeriod;
use App\Support\Dashboard\Glances\PhoneGlance;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The Phone box on the home screen.
 *
 * It replaced a placeholder that had been saying "open the Phone tab for the
 * desk" since the module went live on 2026-08-25 — the only box naming a
 * working module and showing nothing.
 *
 * What it answers is whether the phone is being ANSWERED, which is a
 * comparison and not a count: 40 of 44 reads differently from 40 of 90, and
 * the bare 40 is identical on both days. So every test below is about the
 * relationship between the slices, not about a total.
 */
class PhoneGlanceTest extends TestCase
{
    use RefreshDatabase;

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function inTenant(callable $fn): mixed
    {
        return app(TenantContext::class)->run($this->alpha['org'], $fn);
    }

    private function line(): PhoneNumber
    {
        return $this->inTenant(function () {
            $connection = PhoneConnection::factory()->create([
                'scope_type' => PhoneConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->alpha['org']->id,
            ]);

            $number = PhoneNumber::factory()->create([
                'connection_id' => $connection->id,
                'scope_type' => PhoneNumber::SCOPE_ORGANIZATION,
                'scope_id' => $this->alpha['org']->id,
                'e164' => '+13057249001',
            ]);

            return $number;
        });
    }

    private function ring(PhoneNumber $number, string $status, array $extra = []): PhoneCall
    {
        return $this->inTenant(fn () => PhoneCall::query()->create([
            'connection_id' => $number->connection_id,
            'number_id' => $number->id,
            'twilio_sid' => 'CA'.bin2hex(random_bytes(16)),
            'direction' => PhoneCall::DIRECTION_INBOUND,
            'from_e164' => '+13055550100',
            'to_e164' => $number->e164,
            'status' => $status,
            ...$extra,
        ]));
    }

    private function payload(?User $user = null, string $period = 'month'): array
    {
        $user ??= $this->alpha['admin'];

        return app(PhoneGlance::class)
            ->resolve($user, DashboardPeriod::for($user, $period), [])
            ->toArray();
    }

    public function test_it_is_no_longer_coming_soon(): void
    {
        $this->line();

        $this->assertFalse($this->payload()['coming_soon'] ?? true);
    }

    public function test_answered_and_missed_are_separate_slices(): void
    {
        $number = $this->line();

        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER);

        $slices = collect($this->payload()['data']['slices'])->keyBy('label');

        $this->assertSame(2, $slices['Answered']['total']);
        $this->assertSame(1, $slices['Missed']['total']);
    }

    /**
     * Every status that is not answered lands somewhere.
     *
     * Missed is a subtraction, not a list. A list is what lets a status added
     * later fall out of the total silently, and the symptom is a chart showing
     * 38 of 44 with no sixth slice to account for the gap.
     */
    public function test_no_call_falls_out_of_the_total(): void
    {
        $number = $this->line();

        foreach ([
            PhoneCall::STATUS_COMPLETED,
            PhoneCall::STATUS_NO_ANSWER,
            PhoneCall::STATUS_BUSY,
            PhoneCall::STATUS_FAILED,
            PhoneCall::STATUS_CANCELED,
            PhoneCall::STATUS_IN_PROGRESS,
        ] as $status) {
            $this->ring($number, $status);
        }

        $slices = $this->payload()['data']['slices'];

        $this->assertSame(6, array_sum(array_column($slices, 'total')));
    }

    /**
     * A blocked caller is not a missed call.
     *
     * They never rang a phone. Counting them as missed puts a robocaller in
     * the number somebody reads as "calls we let down", which is the one
     * reading of this box that would make it worse than the placeholder.
     */
    public function test_a_blocked_caller_is_not_counted_as_missed(): void
    {
        $number = $this->line();

        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_BLOCKED);

        $slices = collect($this->payload()['data']['slices'])->keyBy('label');

        $this->assertSame(1, $slices['Answered']['total']);
        $this->assertSame(1, $slices['Blocked']['total']);

        // Missed stays on the chart at zero, and zero is the assertion. The
        // two poles of "is the phone being answered" both belong there even
        // when one of them is empty, because "we missed none" is the good
        // answer and hiding it would make a good week look like a broken box.
        $this->assertSame(
            0,
            $slices['Missed']['total'],
            'a blocked call was counted as one nobody answered',
        );
    }

    /**
     * A company that blocks nobody never sees the word.
     *
     * Unlike Missed, Blocked is an aside rather than a pole of the question. A
     * permanent zero-width slice would put a term on the home screen that
     * somebody has to go and look up to discover it does not apply to them.
     */
    public function test_with_nothing_blocked_there_is_no_blocked_slice(): void
    {
        $number = $this->line();
        $this->ring($number, PhoneCall::STATUS_COMPLETED);

        $this->assertSame(
            ['Answered', 'Missed'],
            array_column($this->payload()['data']['slices'], 'label'),
        );
    }

    /**
     * Voicemails waiting is a queue standing now, not a share of the period.
     *
     * In the pie it would make the slices add to something that is not the
     * total — a chart that lies quietly — and it would drop messages left last
     * month, which are exactly the ones somebody has forgotten.
     */
    public function test_voicemails_waiting_are_a_sentence_and_survive_the_period(): void
    {
        $number = $this->line();

        Carbon::setTestNow(now()->subMonths(3));
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER, ['voicemail' => true]);
        Carbon::setTestNow();

        $this->ring($number, PhoneCall::STATUS_COMPLETED);

        $data = $this->payload(period: 'week')['data'];

        $this->assertSame(['1 voicemail is waiting.'], $data['sentences']);

        // And it is NOT in the slices — the old message is outside this week.
        $this->assertSame(1, array_sum(array_column($data['slices'], 'total')));
    }

    public function test_a_dealt_with_voicemail_stops_waiting(): void
    {
        $number = $this->line();
        $call = $this->ring($number, PhoneCall::STATUS_NO_ANSWER, ['voicemail' => true]);

        $this->assertSame(
            ['1 voicemail is waiting.'],
            $this->payload()['data']['sentences'],
        );

        $this->inTenant(fn () => $call->forceFill(['dealt_at' => now()])->save());

        $this->assertArrayNotHasKey('sentences', $this->payload()['data']);
    }

    /**
     * Outbound calls are not part of "is the phone being answered".
     *
     * A busy sales week would otherwise show as a wall of Answered and hide
     * the inbound picture the box exists for.
     */
    public function test_outbound_calls_are_left_out(): void
    {
        $number = $this->line();

        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_COMPLETED, [
            'direction' => PhoneCall::DIRECTION_OUTBOUND,
        ]);

        $this->assertSame(1, array_sum(array_column(
            $this->payload()['data']['slices'],
            'total',
        )));
    }

    /**
     * Somebody with no line in reach sees nothing, and is told so.
     *
     * Not zero slices: an empty pie reads as "the phone had no calls", which
     * is a different and wrong answer to "you cannot see this".
     *
     * The branch user carries the ADMIN'S ROLE, so `phone.view` is not what
     * stops them — reach is. Per-number grants stopped deciding anything on
     * 2026-09-02 (`PhoneNumberAccess::isGranted` returns true), and a test
     * written around a grant row would pass while proving nothing.
     */
    public function test_without_a_line_in_reach_the_box_says_so(): void
    {
        $number = $this->line();
        $this->ring($number, PhoneCall::STATUS_COMPLETED);

        $branch = $this->inTenant(function () {
            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => \Tests\Concerns\BranchManagerRole::idFor($this->alpha['admin']),
                'name' => 'Branch', 'email' => 'branch@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            // Assigned to one franchise, so canActOnOrganization is false and
            // the organization-scoped line above is out of reach.
            Assignment::create([
                'organization_id' => $this->alpha['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return $user;
        });

        $data = $this->payload($branch)['data'];

        $this->assertTrue($data['empty']);
        $this->assertArrayNotHasKey('slices', $data);
        $this->assertSame('No phone lines to show.', $data['sentence']);

        // Positive control: the admin, on the same data, sees the call. So the
        // empty above is this user's reach and not a glance that shows nobody
        // anything.
        $this->assertSame(1, array_sum(array_column(
            $this->payload()['data']['slices'],
            'total',
        )));
    }

    public function test_no_calls_at_all_is_a_sentence(): void
    {
        $this->line();

        $data = $this->payload()['data'];

        $this->assertTrue($data['empty']);
        $this->assertSame('No calls to show for this period.', $data['sentence']);
    }

    /**
     * Answered and missed compare with yesterday up to the same time, not the
     * whole of yesterday: the afternoon calls below would make both previous
     * figures larger if the window were a whole day.
     */
    public function test_the_figures_compare_with_the_same_span_the_day_before(): void
    {
        $number = $this->line();

        Carbon::setTestNow(Carbon::parse('2026-08-23 10:00:00', 'America/New_York'));
        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER);
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER);

        Carbon::setTestNow(Carbon::parse('2026-08-23 13:00:00', 'America/New_York'));
        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER);

        Carbon::setTestNow(Carbon::parse('2026-08-24 12:00:00', 'America/New_York'));
        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->ring($number, PhoneCall::STATUS_IN_PROGRESS);
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER, ['voicemail' => true]);
        $this->ring($number, PhoneCall::STATUS_BLOCKED);

        $this->assertSame([
            ['key' => 'answered', 'label' => 'answered today', 'value' => 3, 'previous' => 1],
            ['key' => 'missed', 'label' => 'missed', 'value' => 1, 'previous' => 2],
            ['key' => 'voicemail', 'label' => 'waiting', 'value' => 1, 'previous' => null],
        ], $this->payload(period: 'day')['data']['figures']);
    }

    /**
     * What needs somebody, worst first: a phone account that stopped
     * working, a voicemail left over a day, then newer ones (Dashboard,
     * 2026-10-05).
     */
    public function test_the_rows_that_need_somebody_are_worst_first(): void
    {
        $number = $this->line();

        Carbon::setTestNow(Carbon::parse('2026-08-22 10:00:00', 'America/New_York'));
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER, ['voicemail' => true]);

        Carbon::setTestNow(Carbon::parse('2026-08-24 10:00:00', 'America/New_York'));
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER, ['voicemail' => true]);
        $this->ring($number, PhoneCall::STATUS_NO_ANSWER, ['voicemail' => true]);
        $this->ring($number, PhoneCall::STATUS_COMPLETED);
        $this->inTenant(fn () => $number->connection->forceFill(['status' => PhoneConnection::STATUS_BROKEN])->save());

        $this->assertSame([
            ['tone' => 'bad', 'text' => '1 phone account is not working'],
            ['tone' => 'bad', 'text' => '1 voicemail waiting over a day'],
            ['tone' => 'caution', 'text' => '2 new voicemails waiting'],
        ], $this->payload(period: 'day')['data']['attention']);
    }

    /** Nothing to act on, no rows at all. */
    public function test_with_nothing_to_act_on_there_are_no_rows(): void
    {
        $number = $this->line();
        $this->ring($number, PhoneCall::STATUS_COMPLETED);

        $this->assertArrayNotHasKey('attention', $this->payload(period: 'day')['data']);
    }
}
