<?php

namespace Tests\Feature\Reports;

use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The temporary RingCentral / Workiz reconciliation.
 *
 * Every fixture here is INVENTED — no RingCentral or Workiz credential exists
 * yet — so these tests prove the counting rules and the shape handling, and
 * they prove NOTHING about whether the real payloads look like this. That
 * distinction is the whole of what an invented fixture buys, and it has
 * already cost this module one shipped defect (the empty-array/empty-object
 * grey panel). The first real response must be diffed against these fixtures
 * before any number here is believed.
 */
class ExperimentalCallReportTest extends TestCase
{
    // ── the counting rules ───────────────────────────────────────

    public function test_inbound_calls_are_counted_per_person(): void
    {
        $result = (new CallReconciliation)->forDay(
            [
                $this->aCall(person: 'Ana', from: '+15551110000'),
                $this->aCall(person: 'Ana', from: '+15552220000'),
                $this->aCall(person: 'Bar', from: '+15553330000'),
            ],
            [],
            '2026-09-10',
        );

        $this->assertSame('Ana', $result['people'][0]['person']);
        $this->assertSame(2, $result['people'][0]['received']);
        $this->assertSame(1, $result['people'][1]['received']);
        $this->assertSame(3, $result['totals']['received']);
    }

    /** "How many calls did they receive" is not a question about calls made. */
    public function test_outbound_calls_are_not_counted(): void
    {
        $result = (new CallReconciliation)->forDay(
            [
                $this->aCall(person: 'Ana', from: '+15551110000'),
                $this->aCall(person: 'Ana', from: '+15552220000', direction: 'Outbound'),
            ],
            [],
            '2026-09-10',
        );

        $this->assertSame(1, $result['totals']['received']);
    }

    public function test_a_missed_call_still_counts_as_received_and_is_also_counted_as_missed(): void
    {
        $result = (new CallReconciliation)->forDay(
            [
                $this->aCall(person: 'Ana', from: '+15551110000', result: 'Missed'),
                $this->aCall(person: 'Ana', from: '+15552220000'),
            ],
            [],
            '2026-09-10',
        );

        $this->assertSame(2, $result['people'][0]['received']);
        $this->assertSame(1, $result['people'][0]['missed']);
    }

    // ── new against existing ─────────────────────────────────────

    /**
     * THE ONE MOST LIKELY TO BE SILENTLY WRONG.
     *
     * RingCentral says `+15551234567`; Workiz says `(555) 123-4567`. Comparing
     * the formatted strings puts every caller in the NEW column, and the report
     * looks entirely plausible while being entirely wrong — the numbers are all
     * there, they are just all in one place.
     */
    public function test_a_caller_is_existing_when_their_number_is_on_a_job_however_it_is_written(): void
    {
        $result = (new CallReconciliation)->forDay(
            [$this->aCall(person: 'Ana', from: '+15551234567')],
            [['phone' => '(555) 123-4567', 'created_by' => 'Ana', 'created_at' => '2026-01-01', 'status' => 'done']],
            '2026-09-10',
        );

        $this->assertSame(1, $result['people'][0]['from_existing']);
        $this->assertSame(0, $result['people'][0]['from_new']);
    }

    public function test_an_unknown_caller_is_new(): void
    {
        $result = (new CallReconciliation)->forDay(
            [$this->aCall(person: 'Ana', from: '+15559999999')],
            [['phone' => '(555) 123-4567', 'created_by' => 'Ana', 'created_at' => '2026-01-01', 'status' => 'done']],
            '2026-09-10',
        );

        $this->assertSame(1, $result['people'][0]['from_new']);
        $this->assertSame(0, $result['people'][0]['from_existing']);
    }

    /**
     * A number too short to be a real caller ID is NEW rather than matched on a
     * truncated key, which would pair unrelated callers with each other.
     */
    public function test_a_number_with_too_few_digits_is_not_matched_against_anything(): void
    {
        $result = (new CallReconciliation)->forDay(
            [$this->aCall(person: 'Ana', from: '204')],
            [['phone' => '204', 'created_by' => 'Ana', 'created_at' => '2026-01-01', 'status' => 'done']],
            '2026-09-10',
        );

        $this->assertSame(1, $result['people'][0]['from_new']);
    }

    // ── the Workiz comparison ────────────────────────────────────

    public function test_jobs_created_on_the_day_are_counted_against_the_calls(): void
    {
        $result = (new CallReconciliation)->forDay(
            [
                $this->aCall(person: 'Ana', from: '+15551110000'),
                $this->aCall(person: 'Ana', from: '+15552220000'),
                $this->aCall(person: 'Ana', from: '+15553330000'),
            ],
            [
                ['phone' => '+15551110000', 'created_by' => 'Ana', 'created_at' => '2026-09-10 09:00:00', 'status' => 'x'],
                ['phone' => '+15559990000', 'created_by' => 'Ana', 'created_at' => '2026-09-09 09:00:00', 'status' => 'x'],
            ],
            '2026-09-10',
        );

        // Only the job created ON the day counts; yesterday's does not.
        $this->assertSame(1, $result['people'][0]['workiz_jobs']);
        $this->assertSame(2, $result['people'][0]['difference']);
    }

    /**
     * Somebody who booked jobs but took no calls is the most interesting row on
     * the page, and leaving them off reads as "no data" rather than as a
     * finding.
     */
    public function test_a_person_with_jobs_but_no_calls_still_appears(): void
    {
        $result = (new CallReconciliation)->forDay(
            [$this->aCall(person: 'Ana', from: '+15551110000')],
            [['phone' => '+15557770000', 'created_by' => 'Bar', 'created_at' => '2026-09-10 10:00:00', 'status' => 'x']],
            '2026-09-10',
        );

        $rows = array_column($result['people'], 'person');

        $this->assertContains('Bar', $rows);
        $this->assertSame(0, $result['people'][array_search('Bar', $rows, true)]['received']);
        $this->assertSame(1, $result['people'][array_search('Bar', $rows, true)]['workiz_jobs']);
    }

    // ── the connections ──────────────────────────────────────────

    /**
     * Workiz is ONE TOKEN PER ACCOUNT - A-1 runs Nationwide plus nine state
     * sub-accounts - so "configured" is a question about which accounts, not a
     * boolean about whether a token exists. A reader built on one token returns
     * real rows and silently omits eight states.
     */
    public function test_workiz_reports_which_accounts_it_holds_keys_for(): void
    {
        config()->set('experimental_reports.workiz.accounts', [
            'Nationwide' => 'tok-1',
            'NJ' => 'tok-2',
        ]);

        $this->assertSame(['Nationwide', 'NJ'], (new WorkizJobs)->accounts());
        $this->assertTrue((new WorkizJobs)->configured());
    }

    public function test_neither_vendor_reads_as_configured_without_credentials(): void
    {
        config()->set('experimental_reports.ringcentral', [
            'server' => 'https://platform.ringcentral.com',
            'client_id' => null, 'client_secret' => null, 'jwt' => null,
        ]);
        config()->set('experimental_reports.workiz.accounts', []);

        // Not connected and empty are different facts, and the screen shows
        // different sentences for each.
        $this->assertFalse((new RingCentralCallLog)->configured());
        $this->assertFalse((new WorkizJobs)->configured());
    }

    public function test_the_call_log_reads_every_page(): void
    {
        config()->set('experimental_reports.ringcentral', [
            'server' => 'https://platform.test', 'client_id' => 'id',
            'client_secret' => 'secret', 'jwt' => 'jwt',
        ]);

        // One fake with every pattern in it. A second Http::fake() call would
        // STACK rather than replace, and the first registration keeps winning.
        Http::fake([
            'platform.test/restapi/oauth/token' => Http::response(['access_token' => 'tok']),
            'platform.test/restapi/v1.0/account/*page=1*' => Http::response([
                'records' => [$this->rcRecord('Ana')],
                'paging'  => ['totalPages' => 2],
            ]),
            'platform.test/restapi/v1.0/account/*' => Http::response([
                'records' => [$this->rcRecord('Bar')],
                'paging'  => ['totalPages' => 2],
            ]),
        ]);

        $calls = (new RingCentralCallLog)->forDate('2026-09-10T00:00:00Z', '2026-09-10T23:59:59Z');

        // Two pages, so stopping when a page came back short would have
        // returned one — and an exact multiple of the page size would make
        // that bug invisible on most days.
        $this->assertCount(2, $calls);
        $this->assertSame(['Ana', 'Bar'], array_column($calls, 'person'));
    }

    // ── fixtures ─────────────────────────────────────────────────

    private function aCall(
        string $person,
        string $from,
        string $direction = 'Inbound',
        string $result = 'Answered',
    ): array {
        return [
            'extension' => '101', 'person' => $person, 'direction' => $direction,
            'result' => $result, 'from' => $from, 'to' => '+15550000000',
            'started_at' => '2026-09-10T09:00:00.000Z',
        ];
    }

    private function rcRecord(string $person): array
    {
        return [
            'direction' => 'Inbound',
            'result'    => 'Answered',
            'startTime' => '2026-09-10T09:00:00.000Z',
            'from'      => ['phoneNumber' => '+15551234567'],
            'to'        => ['phoneNumber' => '+15550000000', 'name' => $person, 'extensionNumber' => '101'],
        ];
    }
}
