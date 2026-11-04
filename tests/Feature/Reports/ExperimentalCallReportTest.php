<?php

namespace Tests\Feature\Reports;

use App\Models\CallTypeCorrection;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use App\Support\Social\SocialProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The temporary RingCentral / Workiz reconciliation.
 *
 * ── These fixtures are still invented, but no longer guessed ─────
 *
 * The previous version of this file invented BOTH the payload shape and the
 * rules, and eleven green tests agreed with code that was wrong three ways.
 * Every fixture below is now copied from a payload that was read off the live
 * APIs on 2026-09-14 — the queue names, the `legs` layout, the `PstnToSip`
 * result vocabulary, `CreatedDate` versus `JobDateTime`. What these tests still
 * cannot prove is that the vendors will keep sending that shape.
 *
 * The reference day, for anyone re-measuring: 280 inbound calls, 183 answered,
 * 81 that reached nobody, 135 "A1 - New Client" and 66 "A1 - Existing Client",
 * against 66 Workiz jobs booked the same day across nine accounts.
 */
class ExperimentalCallReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── where the person is ──────────────────────────────────────

    /**
     * THE REGRESSION THAT WAS LIVE: the person is on the SIP leg, and the
     * record's own `to` block is the dialled number.
     *
     * The first draft read `to.name` and got "Main - IVR" on 278 of 280 calls,
     * which would have reported that nobody answered anything all day.
     */
    public function test_the_person_comes_from_the_sip_leg_not_the_record(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Trey Brannan', 'Call connected']])]);

        $calls = app(RingCentralCallLog::class)->forDate('x', 'y');

        $this->assertSame(['Trey Brannan'], $calls[0]['rang']);
        $this->assertSame('Trey Brannan', $calls[0]['answered_by']);
    }

    /** One call rings several people; only one of them took it. */
    public function test_ringing_and_answering_are_counted_separately(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [
            ['Jovanni Martin', 'No Answer'],
            ['Jared Prescott', 'No Answer'],
            ['Ranya Lara', 'Call connected'],
        ])]);

        $day = $this->reconcile();

        $this->assertSame(1, $this->person($day, 'Jovanni Martin')['rang']);
        $this->assertSame(0, $this->person($day, 'Jovanni Martin')['answered']);
        $this->assertSame(1, $this->person($day, 'Ranya Lara')['answered']);

        // The DAY still had exactly one answered call, not three.
        $this->assertSame(1, $day['totals']['answered']);
    }

    /**
     * Every person who rings also picks up a duplicate `IP Phone Offline` leg
     * on the live payload. Counting legs would double every number.
     */
    public function test_a_repeated_leg_for_the_same_person_counts_once(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [
            ['Natalie Escobar', 'Call connected'],
            ['Natalie Escobar', 'IP Phone Offline'],
        ])]);

        $this->assertSame(1, $this->person($this->reconcile(), 'Natalie Escobar')['rang']);
    }

    /**
     * 81 of 280 live calls never reached a phone. They are received calls that
     * reached nobody, not calls that did not happen.
     */
    public function test_a_call_that_reached_nobody_is_still_a_call(): void
    {
        // `Missed`: it reached a queue. An IVR-only `Accepted` call is spam
        // since 2026-10-05 and is not counted at all - see the test below.
        $this->fakeCallLog([$this->inbound(rang: [], queue: RingCentralCallLog::NEW_CLIENT_QUEUE, result: 'Missed')]);

        $day = $this->reconcile();

        $this->assertSame(1, $day['totals']['calls']);
        $this->assertSame(1, $day['totals']['reached_nobody']);
        $this->assertSame(0, $day['totals']['answered']);

        // And it still counted towards what the caller was asking for.
        $this->assertSame(1, $day['totals']['new_client']);
    }

    /**
     * A call that RANG people and was taken by none reached nobody too.
     *
     * The card used to count only calls that rang no phone, so these sat in
     * neither Answered nor Reached nobody and the three cards did not add up.
     */
    public function test_a_call_that_rang_and_was_not_answered_reached_nobody(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [['Trey Brannan', 'No Answer']], result: 'Missed'),
            $this->inbound(rang: [['Trey Brannan', 'Call connected']]),
        ]);

        $totals = $this->reconcile()['totals'];

        $this->assertSame(1, $totals['reached_nobody']);
        $this->assertSame($totals['calls'], $totals['answered'] + $totals['reached_nobody']);
    }

    /** Each call says why it reached nobody, from RingCentral's own result. */
    public function test_each_call_says_why_it_reached_nobody(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [], result: 'Accepted'),
            $this->inbound(rang: [], queue: RingCentralCallLog::NEW_CLIENT_QUEUE, result: 'Missed'),
            $this->inbound(rang: [], result: 'Voicemail'),
            $this->inbound(rang: [], result: 'Blocked'),
            $this->inbound(rang: [], result: 'Rejected'),
            $this->inbound(rang: [], result: 'Something new'),
            // Answered wins over the record's result, whatever it says.
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], result: 'Missed'),
        ]);

        // Spam is gone from the list altogether (Jose, 2026-10-05).
        $this->assertSame(
            ['missed', 'voicemail', 'blocked', 'blocked', 'other', 'answered'],
            array_column($this->reconcile()['calls'], 'outcome'),
        );
    }

    /**
     * SPAM IS NOT COUNTED ANYWHERE (Jose, 2026-10-05: "remove Spam Calls
     * completely, do not count them"): not in Calls, not in Missed, not in
     * the list.
     */
    public function test_a_spam_call_is_not_counted_at_all(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [], result: 'Accepted'), // sat in the menu
            $this->inbound(rang: [], queue: RingCentralCallLog::NEW_CLIENT_QUEUE, result: 'Missed'),
            $this->inbound(rang: [['Trey Brannan', 'Call connected']]),
        ]);

        $day = $this->reconcile();

        $this->assertSame(2, $day['totals']['calls']);
        $this->assertSame(1, $day['totals']['reached_nobody']);
        $this->assertNotContains('spam', array_column($day['calls'], 'outcome'));
    }

    /**
     * NOT BOOKED: an answered new-client call whose number has no Workiz job
     * in the period - unless somebody marked it booked by hand.
     */
    /** Jose, 2026-10-16: a caller who pressed the wrong key counts as what they were. */
    public function test_a_corrected_caller_type_moves_the_call_and_says_what_was_pressed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeCallLog([
            ['id' => 'c-wrong'] + $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::NEW_CLIENT_QUEUE),
            ['id' => 'c-right'] + $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::NEW_CLIENT_QUEUE),
        ]);

        $calls = app(RingCentralCallLog::class)->forDate('x', 'y');
        $day = $this->inTenant($t['org'], function () use ($t, $calls) {
            CallTypeCorrection::query()->create(['organization_id' => $t['org']->id, 'call_id' => 'c-wrong',
                'pressed_queue' => RingCentralCallLog::NEW_CLIENT_QUEUE, 'corrected_type' => 'existing',
                'reason' => 'An open job from before the call', 'evidence' => 'job_match', 'confidence' => 95, 'decided_at' => now()]);

            return app(CallReconciliation::class)->forRange($calls, [], '2026-09-14', '2026-09-14');
        });

        $this->assertSame(1, $day['totals']['new_client']);
        $this->assertSame(1, $day['totals']['existing_client']);
        $byId = array_column($day['calls'], null, 'id');
        $this->assertSame(RingCentralCallLog::EXISTING_CLIENT_QUEUE, $byId['c-wrong']['queue']);
        $this->assertSame(RingCentralCallLog::NEW_CLIENT_QUEUE, $byId['c-wrong']['reclassified']['pressed']);
        $this->assertSame('An open job from before the call', $byId['c-wrong']['reclassified']['reason']);
        $this->assertNull($byId['c-right']['reclassified']);
    }

    public function test_not_booked_matches_jobs_by_phone_and_honours_a_manual_mark(): void
    {
        $call = fn (string $id, string $phone) => array_merge(
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::NEW_CLIENT_QUEUE),
            ['id' => $id, 'from' => ['phoneNumber' => $phone, 'name' => 'X']],
        );

        $this->fakeCallLog([
            $call('c-booked', '+12125551212'),
            $call('c-marked', '+12125550000'),
            $call('c-open', '+12125559999'),
        ]);

        $day = app(CallReconciliation::class)->forRange(
            app(RingCentralCallLog::class)->forDate('x', 'y'),
            [['created_at' => '2026-09-14 10:00:00', 'phone' => '(212) 555-1212', 'created_by' => 'Trey Brannan']],
            '2026-09-14',
            '2026-09-14',
            converted: ['c-marked'],
        );

        $this->assertSame(1, $day['totals']['not_booked']);
        $byId = array_column($day['calls'], null, 'id');
        $this->assertTrue($byId['c-booked']['booked']);
        $this->assertTrue($byId['c-marked']['booked']);
        $this->assertTrue($byId['c-marked']['converted']);
        $this->assertFalse($byId['c-open']['booked']);
    }

    /**
     * A call from the client's other number is booked too (Trey, 2026-11-03:
     * 10 of 43 "AI heard a booking, no job" calls in a week were).
     */
    public function test_a_job_booked_under_the_second_phone_books_the_call(): void
    {
        $this->fakeCallLog([array_merge(
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::NEW_CLIENT_QUEUE),
            ['id' => 'c-second', 'from' => ['phoneNumber' => '+12125557777', 'name' => 'X']],
        )]);

        $day = app(CallReconciliation::class)->forRange(
            app(RingCentralCallLog::class)->forDate('x', 'y'),
            [['created_at' => '2026-09-14 10:00:00', 'phone' => '2125550001', 'second_phone' => '(212) 555-7777', 'created_by' => 'Trey Brannan']],
            '2026-09-14',
            '2026-09-14',
        );

        $this->assertTrue(array_column($day['calls'], null, 'id')['c-second']['booked']);
    }

    /**
     * A caller with an open job booked before the dates is "Existing job",
     * not "Not booked" (Jose, 2026-10-13: a reschedule read as a lost booking).
     * A job already Done does not count.
     */
    public function test_a_caller_with_an_open_earlier_job_is_an_existing_job_not_a_lost_booking(): void
    {
        $call = fn (string $id, string $phone) => array_merge(
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::NEW_CLIENT_QUEUE),
            ['id' => $id, 'from' => ['phoneNumber' => $phone, 'name' => 'X']],
        );

        $this->fakeCallLog([$call('c-open-job', '+12342014951'), $call('c-done-job', '+12125550000')]);

        $day = app(CallReconciliation::class)->forRange(
            app(RingCentralCallLog::class)->forDate('x', 'y'),
            [
                ['created_at' => '2026-09-05 10:00:00', 'phone' => '2342014951', 'status' => 'Submitted', 'scheduled_for' => '2026-09-19 09:00:00'],
                ['created_at' => '2026-09-05 10:00:00', 'phone' => '2125550000', 'status' => 'Done'],
            ],
            '2026-09-14',
            '2026-09-14',
        );

        $this->assertSame(1, $day['totals']['not_booked']);
        $byId = array_column($day['calls'], null, 'id');
        $this->assertFalse($byId['c-open-job']['booked']);
        $this->assertSame('2026-09-19 09:00:00', $byId['c-open-job']['existing_job']['scheduled_for']);
        $this->assertNull($byId['c-done-job']['existing_job']);
    }

    /**
     * Jose, 2026-10-12: "on missed calls i want also to differentiate between
     * inbound and outbound calls". An outbound call nobody picked up is listed
     * with its direction and who placed it, and is not a missed customer call;
     * an outbound call that connected is not listed at all.
     */
    public function test_an_unanswered_outbound_call_is_listed_but_not_counted_as_missed(): void
    {
        $this->fakeCallLog([
            array_merge($this->inbound(rang: [], queue: RingCentralCallLog::NEW_CLIENT_QUEUE), ['result' => 'Missed']),
            ['id' => 'out-1', 'direction' => 'Outbound', 'result' => 'No Answer', 'startTime' => '2026-09-14T15:00:00Z',
                'from' => ['phoneNumber' => '+15550001111', 'name' => 'Trey Brannan'], 'to' => ['phoneNumber' => '+12125551212'],
                'legs' => [['legType' => 'SipToPstnUnmetered', 'result' => 'No Answer']]],
            ['id' => 'out-2', 'direction' => 'Outbound', 'result' => 'Call connected', 'startTime' => '2026-09-14T16:00:00Z',
                'from' => ['phoneNumber' => '+15550001111', 'name' => 'Trey Brannan'], 'to' => ['phoneNumber' => '+12125550000'],
                'legs' => [['legType' => 'SipToPstnUnmetered', 'result' => 'Call connected']]],
        ]);

        $log = app(RingCentralCallLog::class);
        $day = app(CallReconciliation::class)->forRange($log->forDate('x', 'y'), [], '2026-09-14', '2026-09-14');

        $this->assertSame(1, $day['totals']['reached_nobody']);
        $this->assertSame(1, $day['totals']['calls']);
        $this->assertSame(1, $day['totals']['outbound_unanswered']);

        $out = array_column($day['calls'], null, 'id')['out-1'];
        $this->assertSame(['Outbound', 'no_answer', '+12125551212', 'Trey Brannan'], [$out['direction'], $out['outcome'], $out['from'], $out['placed_by']]);
        // A connected outbound call is a row for a search by number (Jose,
        // 2026-10-13), never counted: the totals above did not move.
        $connected = array_column($day['calls'], null, 'id')['out-2'];
        $this->assertSame(['Outbound', 'connected', '+12125550000'], [$connected['direction'], $connected['outcome'], $connected['from']]);
    }

    // ── new versus existing ──────────────────────────────────────

    /** The split is the queue the caller chose, not a guess from their number. */
    public function test_new_and_existing_come_from_the_queue(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::NEW_CLIENT_QUEUE),
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: RingCentralCallLog::EXISTING_CLIENT_QUEUE),
        ]);

        $trey = $this->person($this->reconcile(), 'Trey Brannan');

        $this->assertSame(1, $trey['new_client']);
        $this->assertSame(1, $trey['existing_client']);
    }

    /** "Main - IVR" is the switchboard every call passes through and says nothing. */
    public function test_the_switchboard_is_not_a_queue(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Trey Brannan', 'Call connected']], queue: null)]);

        $this->assertNull(app(RingCentralCallLog::class)->forDate('x', 'y')[0]['queue']);
    }

    /** A call answered by one person is not counted as new for the three it rang. */
    public function test_the_split_follows_whoever_took_the_call(): void
    {
        $this->fakeCallLog([$this->inbound(
            rang: [['Jared Prescott', 'No Answer'], ['Nelly Escobar', 'Call connected']],
            queue: RingCentralCallLog::NEW_CLIENT_QUEUE,
        )]);

        $day = $this->reconcile();

        $this->assertSame(0, $this->person($day, 'Jared Prescott')['new_client']);
        $this->assertSame(1, $this->person($day, 'Nelly Escobar')['new_client']);
    }

    // ── the Workiz side ──────────────────────────────────────────

    /**
     * THE OTHER DEFECT THE FIXTURES HID: `JobDateTime` is the appointment and
     * `CreatedDate` is the booking, and live rows put them a year apart.
     */
    public function test_a_job_is_counted_on_the_day_it_was_booked(): void
    {
        $day = app(CallReconciliation::class)->forDay(
            [],
            [
                // Booked on the day, van turns up in four months.
                ['account' => 'NJ', 'created_by' => 'Sasha Solano', 'created_at' => '2026-09-14 09:12:00'],
                // Van turns up ON the day, but it was booked last year.
                ['account' => 'NJ', 'created_by' => 'Sasha Solano', 'created_at' => '2025-10-29 07:07:04'],
            ],
            '2026-09-14',
        );

        $this->assertSame(1, $day['totals']['jobs']);
        $this->assertSame(1, $this->person($day, 'Sasha Solano')['jobs']);
    }

    /** Null on 21 of the first 900 live rows, so it needs a bucket, not a skip. */
    public function test_a_job_with_no_creator_is_bucketed_rather_than_dropped(): void
    {
        $day = app(CallReconciliation::class)->forDay(
            [],
            [['account' => 'CT', 'created_by' => null, 'created_at' => '2026-09-14 10:00:00']],
            '2026-09-14',
        );

        $this->assertSame(1, $day['totals']['jobs']);
        $this->assertSame(1, $this->person($day, 'No one recorded')['jobs']);
    }

    // ── the two systems disagree about names ─────────────────────

    /**
     * RingCentral's "Adeline Pierre Louis-Aghaji" is Workiz's "Adeline
     * Pierrelouis". They must NOT be merged: a near-match rule would attribute
     * one person's work to another, and a visible duplicate row is the honest
     * failure.
     */
    /**
     * THE CONFIRMED PAIR IS ONE PERSON, on Jose's word and nothing else.
     *
     * "Adeline Pierre Louis-Aghaji" answers the phone and "Adeline Pierrelouis"
     * books the job. Asked whether they are the same person, Jose: "YES". So
     * the calls and the jobs land on ONE row.
     */
    public function test_a_confirmed_alias_becomes_one_person(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Adeline Pierre Louis-Aghaji', 'Call connected']])]);

        $day = $this->reconcile([
            ['account' => 'NJ', 'created_by' => 'Adeline Pierrelouis', 'created_at' => '2026-09-14 11:00:00'],
        ]);

        $person = $this->person($day, 'Adeline Pierrelouis');

        $this->assertSame(1, $person['answered']);
        $this->assertSame(1, $person['jobs']);
        $this->assertTrue($person['in_calls']);
        $this->assertTrue($person['in_jobs']);

        // And there is no second row left behind under the other spelling.
        $this->assertSame(
            ['Adeline Pierrelouis'],
            array_column($day['people'], 'person'),
        );
    }

    public function test_similar_names_are_not_merged(): void
    {
        // An UNCONFIRMED near-pair. Nothing has said these are one person, so
        // nothing here may decide it - a resemblance rule that merged them
        // would also merge two real people who share a surname.
        $this->fakeCallLog([$this->inbound(rang: [['Nelly Escobar', 'Call connected']])]);

        $day = $this->reconcile([
            ['account' => 'NJ', 'created_by' => 'Natalie Escobar', 'created_at' => '2026-09-14 11:00:00'],
        ]);

        $rc = $this->person($day, 'Nelly Escobar');
        $wz = $this->person($day, 'Natalie Escobar');

        $this->assertTrue($rc['in_calls']);
        $this->assertFalse($rc['in_jobs']);
        $this->assertFalse($wz['in_calls']);
        $this->assertTrue($wz['in_jobs']);
    }

    // ── the accounts ─────────────────────────────────────────────

    /**
     * Named rather than counted. The accounts are DISJOINT — measured, each
     * token 204s on another account's job — so a report covering eight of nine
     * has to be able to say which eight.
     */
    public function test_workiz_reports_which_branches_it_holds_keys_for(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'New Jersey', 'nj-token');
        $this->workizBranch($tenant, 'Connecticut', 'ct-token');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $this->assertSame(
                ['New Jersey', 'Connecticut'],
                array_column(app(WorkizJobs::class)->accounts($tenant['admin']), 'name'),
            );

            // The id is published here because nothing else publishes it: a
            // data-source provider is excluded from the shared connections
            // list, so without this a credential could be connected and never
            // removed.
            foreach (app(WorkizJobs::class)->accounts($tenant['admin']) as $account) {
                $this->assertNotEmpty($account['id']);
            }
            $this->assertTrue(app(WorkizJobs::class)->configured($tenant['admin']));
        });
    }

    /** Review 2026-10-30, item 12: Sources ticks are one person's viewing choice. */
    public function test_unticking_an_account_leaves_it_out_for_that_person_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'New Jersey', 'nj-token');
        $this->workizBranch($tenant, 'Connecticut', 'ct-token');
        $other = $this->userWithRole($tenant, 'Reader', ['reports.experimental.view', 'reports.view']);
        $nj = $this->inTenant($tenant['org'], fn () => collect(app(WorkizJobs::class)->allAccounts())->firstWhere('name', 'New Jersey')['id']);

        $this->signedInAs($tenant['admin'])->putJson('/api/v1/reports/experimental/sources', ['excluded' => [$nj]])
            ->assertOk()->assertJsonPath('data.0.included', false);
        $this->signedInAs($tenant['admin'])->putJson('/api/v1/reports/experimental/sources', ['excluded' => ['not-an-account']])
            ->assertUnprocessable();

        $this->inTenant($tenant['org'], function () use ($tenant, $other) {
            Auth::setUser($tenant['admin']->fresh());
            $this->assertSame([false, true], array_column(app(WorkizJobs::class)->allAccounts(), 'included'));
            Auth::setUser($other);
            $this->assertSame([true, true], array_column(app(WorkizJobs::class)->allAccounts(), 'included'), 'nobody else changes');
        });
    }

    /**
     * A ROW WITH NO TOKEN IS NAMED, NOT SKIPPED.
     *
     * The same people book into every branch, so a branch quietly absent from
     * the count makes every person's number low rather than the report visibly
     * incomplete. That is the failure this whole class is shaped around.
     */
    public function test_a_branch_whose_token_went_missing_is_named(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'Connecticut', 'ct-token');
        $this->workizBranch($tenant, 'New Jersey', null);

        Http::fake(['api.workiz.com/*' => Http::response(['flag' => true, 'data' => [
            ['Phone' => '2125551212', 'CreatedBy' => 'Sasha Solano', 'CreatedDate' => '2026-09-14 09:00:00'],
        ]])]);

        $result = $this->inTenant(
            $tenant['org'],
            fn () => app(WorkizJobs::class)->all($tenant['admin'], 100),
        );

        // Connecticut answered, so this is not the all-failed path - which is
        // the case worth pinning. A branch that cannot be read must be NAMED
        // beside the ones that could, not quietly dropped from the count.
        $this->assertSame(['Connecticut'], array_column($result['jobs'], 'account'));
        $this->assertArrayHasKey('New Jersey', $result['failed']);
    }

    /** And when NOTHING could be read, the failure names every branch. */
    public function test_a_total_failure_names_the_branches(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'New Jersey', null);

        try {
            $this->inTenant(
                $tenant['org'],
                fn () => app(WorkizJobs::class)->all($tenant['admin'], 100),
            );
            $this->fail('a report that could read nothing returned as if it had');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('New Jersey', $e->getMessage());
        }
    }

    /** One rotated key must not lose the other nine branches. */
    public function test_one_refused_account_does_not_lose_the_others(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'Nationwide', 'good');
        $this->workizBranch($tenant, 'New Jersey', 'bad');

        Http::fake([
            'api.workiz.com/api/v1/good/*' => Http::response(['flag' => true, 'data' => [
                ['Phone' => '2125551212', 'CreatedBy' => 'Sasha Solano', 'CreatedDate' => '2026-09-14 09:00:00'],
            ]]),
            'api.workiz.com/api/v1/bad/*' => Http::response([], 403),
        ]);

        $result = $this->inTenant(
            $tenant['org'],
            fn () => app(WorkizJobs::class)->all($tenant['admin'], 100),
        );

        $this->assertCount(1, $result['jobs']);
        $this->assertSame('Nationwide', $result['jobs'][0]['account']);
        $this->assertArrayHasKey('New Jersey', $result['failed']);
    }

    /**
     * THE TOKEN IS IN THE URL, and a connection failure throws before there is
     * a response — which is how the first live run printed it into a trace.
     */
    public function test_an_unreachable_workiz_does_not_name_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'Nationwide', 'sup3rs3cr3t');

        Http::fake(fn () => throw new ConnectionException(
            'cURL error 60 for https://api.workiz.com/api/v1/sup3rs3cr3t/job/all/'
        ));

        try {
            $this->inTenant(
                $tenant['org'],
                fn () => app(WorkizJobs::class)->all($tenant['admin'], 100),
            );
            $this->fail('an unreachable Workiz did not raise');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('sup3rs3cr3t', $e->getMessage());
            $this->assertStringNotContainsString('api.workiz.com', $e->getMessage());
        }
    }

    /** An absent credential is "not connected", which is not "no calls". */
    public function test_neither_vendor_reads_as_configured_without_credentials(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        config([
            'experimental_reports.ringcentral.client_id' => null,
            'experimental_reports.ringcentral.client_secret' => null,
            'experimental_reports.ringcentral.jwt' => null,
        ]);

        // No Workiz connection on any branch.
        $this->inTenant($tenant['org'], function () use ($tenant) {
            $this->assertFalse(app(WorkizJobs::class)->configured($tenant['admin']));
        });

        $this->assertFalse(app(RingCentralCallLog::class)->configured());
    }

    // ── paging ───────────────────────────────────────────────────

    /** RingCentral says there is another page with `navigation.nextPage`, and only that way. */
    public function test_the_call_log_reads_every_page(): void
    {
        $this->fakeToken();

        Http::fake(['*/call-log*' => Http::sequence()
            // The shape the live API sends (measured 2026-10-05): no page
            // count, and `navigation.nextPage` only while there is one.
            ->push(['records' => [$this->inbound(rang: [['Trey Brannan', 'Call connected']])],
                'paging' => ['page' => 1, 'perPage' => 1000, 'pageStart' => 0, 'pageEnd' => 0],
                'navigation' => ['firstPage' => ['uri' => 'x'], 'nextPage' => ['uri' => 'x?page=2']]])
            ->push(['records' => [$this->inbound(rang: [['Nelly Escobar', 'Call connected']])],
                'paging' => ['page' => 2, 'perPage' => 1000, 'pageStart' => 1000, 'pageEnd' => 1000],
                'navigation' => ['firstPage' => ['uri' => 'x']]]),
        ]);

        $calls = app(RingCentralCallLog::class)->forDate('x', 'y');

        $this->assertSame(['Trey Brannan', 'Nelly Escobar'], array_column($calls, 'answered_by'));
    }

    /**
     * Jose, 2026-10-27: an integration's data stays in EzBizi. A finished day
     * is kept in call_log_days, so flushing the cache or losing RingCentral
     * does not take the report's history with it.
     */
    public function test_a_finished_day_is_kept_in_ezbizi_not_only_in_the_cache(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Trey Brannan', 'Call connected']])]);
        $day = CarbonImmutable::now()->subDays(3)->startOfDay();

        app(RingCentralCallLog::class)->forPeriod($day, $day->endOfDay());
        Cache::flush();
        Http::fake(['*' => Http::response([], 500)]);

        $again = app(RingCentralCallLog::class)->forPeriod($day, $day->endOfDay());

        $this->assertSame(['Trey Brannan'], array_column($again, 'answered_by'));
        $this->assertTrue(app(RingCentralCallLog::class)->hasDay($day));
    }

    /** One sign-in serves every request until it expires: RingCentral rate-limits the sign-in too. */
    public function test_the_token_is_reused_across_requests(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Trey Brannan', 'Call connected']])]);

        $log = app(RingCentralCallLog::class);
        $log->forDate('x', 'y');
        $log->forDate('x', 'y');
        $log->forDate('x', 'y');

        $signIns = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'oauth/token'));
        $this->assertCount(1, $signIns);
    }

    /** A rate-limited sign-in is waited out too, and never reported as bad credentials. */
    public function test_a_rate_limited_sign_in_is_waited_out(): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);
        Sleep::fake();

        Http::fake([
            '*/oauth/token' => Http::sequence()
                ->push([], 429, ['Retry-After' => '5'])
                ->push(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['records' => [], 'paging' => ['page' => 1]]),
        ]);

        $this->assertSame([], app(RingCentralCallLog::class)->forDate('x', 'y'));
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 5.0, 1);
    }

    /** Still limited after three tries: said as busy, not as refused credentials. */
    public function test_a_sign_in_that_stays_limited_is_named_as_busy(): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);
        Sleep::fake();
        Http::fake(['*/oauth/token' => Http::response([], 429)]);

        try {
            app(RingCentralCallLog::class)->forDate('x', 'y');
            $this->fail('expected the sign-in to give up');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('limiting sign-ins', $e->getMessage());
            $this->assertStringNotContainsString('credentials', $e->getMessage());
        }

        Sleep::assertSleptTimes(2);
    }

    /** A rate-limited page is waited for and asked again, not turned into an error. */
    public function test_a_rate_limited_page_is_retried_after_the_wait(): void
    {
        $this->fakeToken();
        Sleep::fake();

        Http::fake(['*/call-log*' => Http::sequence()
            ->push(['errorCode' => 'CMN-301'], 429, ['Retry-After' => '7'])
            ->push(['records' => [$this->inbound(rang: [['Trey Brannan', 'Call connected']])], 'paging' => ['page' => 1]]),
        ]);

        $calls = app(RingCentralCallLog::class)->forDate('x', 'y');

        $this->assertSame(['Trey Brannan'], array_column($calls, 'answered_by'));
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds === 7.0, 1);
    }

    /**
     * A FINISHED DAY IS READ ONCE: the call log is in RingCentral's ten-a-minute
     * group, so a week asked twice must not cost fourteen requests. Today is
     * always read live.
     */
    public function test_a_period_reads_each_finished_day_once_and_today_live(): void
    {
        $this->fakeToken();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16 12:00', 'America/New_York'));

        $asked = 0;
        Http::fake(['*/call-log*' => function () use (&$asked) {
            $asked++;

            return Http::response(['records' => [$this->inbound(rang: [['Trey Brannan', 'Call connected']])], 'paging' => ['page' => 1]]);
        }]);

        $log = app(RingCentralCallLog::class);
        $start = CarbonImmutable::parse('2026-09-14', 'America/New_York');
        $end = CarbonImmutable::parse('2026-09-16', 'America/New_York')->endOfDay();

        $this->assertCount(3, $log->forPeriod($start, $end)); // one call a day
        $this->assertSame(3, $asked);

        $log->forPeriod($start, $end);
        $this->assertSame(3, $asked, 'today is shared for a minute');

        $this->travel(61)->seconds();
        $log->forPeriod($start, $end);
        $this->assertSame(4, $asked, 'only today should have been asked again');

        CarbonImmutable::setTestNow();
    }

    /** Workiz caps a page at 100 and pages by offset; a short page is the last. */
    public function test_workiz_stops_on_a_short_page(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->workizBranch($tenant, 'New Jersey', 'tok');

        $full = array_fill(0, WorkizJobs::PAGE, [
            'Phone' => '2125551212', 'CreatedBy' => 'Sasha Solano', 'CreatedDate' => '2026-09-14 09:00:00',
        ]);

        Http::fake(['api.workiz.com/*' => Http::sequence()
            ->push(['flag' => true, 'data' => $full])
            ->push(['flag' => true, 'data' => [$full[0]]]),
        ]);

        $jobs = $this->inTenant(
            $tenant['org'],
            fn () => app(WorkizJobs::class)->all($tenant['admin'], 1000),
        )['jobs'];

        $this->assertCount(WorkizJobs::PAGE + 1, $jobs);
    }

    // ── fixtures, shaped from the live payload ───────────────────

    /**
     * An inbound call as RingCentral actually sends it: the record's `to` is
     * the dialled number, the switchboard is an `Accept` leg, and each person
     * who rang is a `PstnToSip` leg whose `from.name` is the extension owner.
     *
     * @param  list<array{0: string, 1: string}>  $rang  [person, leg result]
     */
    /**
     * AVERAGE TALK TIME IS THE LEG, NOT THE RECORD.
     *
     * Measured against the live API 2026-09-15: one call's record said 197
     * seconds and its connected leg said 148. The record starts when the caller
     * reaches the switchboard, so it carries the IVR and the queue wait - time
     * nobody was on the phone. The fixtures here keep the two numbers different
     * for exactly that reason: reading the wrong field cannot pass.
     */
    public function test_average_talk_time_uses_the_connected_leg(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], talkSeconds: 100, recordSeconds: 900),
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], talkSeconds: 200, recordSeconds: 900),
        ]);

        $day = $this->reconcile([]);

        $this->assertSame(150, $this->person($day, 'Trey Brannan')['avg_talk_seconds']);
        $this->assertSame(150, $day['totals']['avg_talk_seconds']);
    }

    /**
     * A CALL NOBODY TOOK HAS NO TALK TIME, and must not average in as zero.
     *
     * 81 of 280 on the first measured day reached nobody. Counting those as
     * zero-second calls would drag every mean toward nothing while looking
     * exactly like a measurement.
     */
    public function test_a_call_that_reached_nobody_does_not_average_in_as_zero(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [['Trey Brannan', 'Call connected']], talkSeconds: 200),
            $this->inbound(rang: [['Trey Brannan', 'Missed']], talkSeconds: 0),
        ]);

        $day = $this->reconcile([]);

        $this->assertSame(200, $this->person($day, 'Trey Brannan')['avg_talk_seconds']);
    }

    /** No average at all is a different fact from an average of zero. */
    public function test_somebody_who_answered_nothing_has_no_average(): void
    {
        $this->fakeCallLog([
            $this->inbound(rang: [['Trey Brannan', 'Missed']], talkSeconds: 0),
        ]);

        $day = $this->reconcile([]);

        $this->assertNull($this->person($day, 'Trey Brannan')['avg_talk_seconds']);
        $this->assertNull($day['totals']['avg_talk_seconds']);
    }

    /**
     * BOOKING NOTHING TODAY IS NOT THE SAME AS BEING UNKNOWN TO WORKIZ.
     *
     * The marker used to be computed off the DAY's jobs, so somebody who
     * answered calls and happened to book nothing that morning was flagged as
     * though Workiz had never heard of them - which reads as a name spelled
     * differently in the two systems and invites a "fix" to a pair that was
     * never broken. Three people on the first live day.
     *
     * The roster is the 30-day window the report already fetches.
     */
    public function test_somebody_in_the_window_but_not_todays_jobs_is_not_flagged(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Jared Prescott', 'Call connected']])]);

        // In Workiz, but the job is from last week rather than today.
        $day = $this->reconcile([
            ['account' => 'NJ', 'created_by' => 'Jared Prescott', 'created_at' => '2026-09-07 11:00:00'],
        ]);

        $person = $this->person($day, 'Jared Prescott');

        $this->assertSame(0, $person['jobs'], "last week's job counted toward today");
        $this->assertTrue($person['in_jobs'], 'Workiz knows this name and the report said otherwise');
    }

    /** Somebody Workiz has never seen at all IS worth flagging. */
    public function test_somebody_absent_from_the_whole_window_is_flagged(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Chaim Perez', 'Call connected']])]);

        $day = $this->reconcile([
            ['account' => 'NJ', 'created_by' => 'Trey Brannan', 'created_at' => '2026-09-14 11:00:00'],
        ]);

        $this->assertFalse($this->person($day, 'Chaim Perez')['in_jobs']);
    }

    private function inbound(array $rang, ?string $queue = null, int $talkSeconds = 148, int $recordSeconds = 197, string $result = 'Accepted'): array
    {
        $legs = [[
            'legType' => 'Accept',
            'result' => 'Accepted',
            'from' => ['name' => 'WIRELESS CALLER'],
            'to' => ['name' => 'Main - IVR'],
        ]];

        if ($queue !== null) {
            $legs[] = [
                'legType' => 'Accept',
                'result' => 'Accepted',
                'from' => ['name' => 'MKE - LS Ads'],
                'to' => ['name' => $queue],
            ];
        }

        foreach ($rang as [$person, $result]) {
            $legs[] = [
                'legType' => 'PstnToSip',
                'result' => $result,
                'from' => ['name' => $person],
                'to' => ['extensionNumber' => '748'],

                // The LEG's duration. Measured live 2026-09-15, these two
                // differ by 25% on a real call - the record's starts at the
                // switchboard and carries the IVR and the queue wait.
                'duration' => $talkSeconds,
            ];
        }

        return [
            'id' => '1234567890',
            'direction' => 'Inbound',
            'result' => $result,
            'startTime' => '2026-09-14T13:21:11.795Z',
            'from' => ['phoneNumber' => '+17579553189', 'name' => 'PERRON,NICOLE'],
            'to' => ['phoneNumber' => '+18888602433', 'name' => 'Main - IVR'],

            // Deliberately DIFFERENT from the leg's, so a test asserting the
            // average cannot pass by reading the wrong field.
            'duration' => $recordSeconds,

            'legs' => $legs,
        ];
    }

    /** @param  list<array<string, mixed>>  $records */
    private function fakeCallLog(array $records): void
    {
        $this->fakeToken();

        Http::fake(['*/call-log*' => Http::response(['records' => $records, 'paging' => ['totalPages' => 1]])]);
    }

    private function fakeToken(): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        // Http::fake stacks rather than replaces, so the token route is
        // registered first and the caller's own fake is layered over it.
        Http::fake(['*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600])]);
    }

    /** @param  list<array<string, mixed>>  $jobs */
    private function reconcile(array $jobs = []): array
    {
        return app(CallReconciliation::class)->forDay(
            app(RingCentralCallLog::class)->forDate('x', 'y'),
            $jobs,
            '2026-09-14',
        );
    }

    /** @param  array<string, mixed>  $day */
    private function person(array $day, string $name): array
    {
        foreach ($day['people'] as $row) {
            if ($row['person'] === $name) {
                return $row;
            }
        }

        $this->fail($name.' is missing from the report; it has: '.implode(', ', array_column($day['people'], 'person')));
    }

    /**
     * A branch with a Workiz account attached to it.
     *
     * `scope_type = franchise` is the whole point: it is what says WHICH branch
     * the account's jobs belong to. Not `leads_franchise_id`, which answers
     * where a connection's enquiries get assigned.
     */
    private function workizBranch(array $tenant, string $name, ?string $token): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $name, $token) {
            $franchise = Franchise::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'name' => $name,
                'is_active' => true,
            ]);

            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_FRANCHISE,
                'scope_id' => $franchise->id,
                'provider' => SocialProvider::Workiz->value,
                'remote_id' => $franchise->id,
                'display_name' => $name,
            ]);

            if ($token !== null) {
                $connection->connect(['refresh_token' => $token, 'api_token' => $token]);
            }

            $connection->save();

            return $connection;
        });
    }
}
