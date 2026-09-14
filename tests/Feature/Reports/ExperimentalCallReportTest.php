<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use App\Support\Reports\Experimental\CallReconciliation;
use App\Support\Reports\Experimental\RingCentralCallLog;
use App\Support\Reports\Experimental\WorkizJobs;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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
        $this->fakeCallLog([$this->inbound(rang: [], queue: RingCentralCallLog::NEW_CLIENT_QUEUE)]);

        $day = $this->reconcile();

        $this->assertSame(1, $day['totals']['calls']);
        $this->assertSame(1, $day['totals']['reached_nobody']);
        $this->assertSame(0, $day['totals']['answered']);

        // And it still counted towards what the caller was asking for.
        $this->assertSame(1, $day['totals']['new_client']);
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
    public function test_similar_names_are_not_merged(): void
    {
        $this->fakeCallLog([$this->inbound(rang: [['Adeline Pierre Louis-Aghaji', 'Call connected']])]);

        $day = $this->reconcile([
            ['account' => 'NJ', 'created_by' => 'Adeline Pierrelouis', 'created_at' => '2026-09-14 11:00:00'],
        ]);

        $rc = $this->person($day, 'Adeline Pierre Louis-Aghaji');
        $wz = $this->person($day, 'Adeline Pierrelouis');

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
                app(WorkizJobs::class)->accounts($tenant['admin']),
            );
            $this->assertTrue(app(WorkizJobs::class)->configured($tenant['admin']));
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

    /** RingCentral pages on `totalPages`; a full last page is not a short page. */
    public function test_the_call_log_reads_every_page(): void
    {
        $this->fakeToken();

        Http::fake(['*/call-log*' => Http::sequence()
            ->push(['records' => [$this->inbound(rang: [['Trey Brannan', 'Call connected']])], 'paging' => ['totalPages' => 2]])
            ->push(['records' => [$this->inbound(rang: [['Nelly Escobar', 'Call connected']])], 'paging' => ['totalPages' => 2]]),
        ]);

        $calls = app(RingCentralCallLog::class)->forDate('x', 'y');

        $this->assertSame(['Trey Brannan', 'Nelly Escobar'], array_column($calls, 'answered_by'));
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
    private function inbound(array $rang, ?string $queue = null): array
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
            ];
        }

        return [
            'id' => '1234567890',
            'direction' => 'Inbound',
            'result' => 'Accepted',
            'startTime' => '2026-09-14T13:21:11.795Z',
            'from' => ['phoneNumber' => '+17579553189', 'name' => 'PERRON,NICOLE'],
            'to' => ['phoneNumber' => '+18888602433', 'name' => 'Main - IVR'],
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
