<?php

namespace Tests\Feature\Reports;

use App\Models\AuditEvent;
use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\CallReportLabel;
use App\Models\CallReportScore;
use App\Models\CallReportTranscript;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\SocialConnection;
use App\Models\WorkizCall;
use App\Models\WorkizJob;
use App\Support\Reports\ReportView;
use App\Support\Settings\SettingsResolver;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The endpoint behind the Experimental tab (TEMPORARY).
 *
 * The payload names every member of staff and how many calls each one took, so
 * the gate is the point of this file — more than the arithmetic, which
 * ExperimentalCallReportTest covers. This module has already disclosed people
 * organization-wide once by reasoning about where a record lives instead of
 * who may see it.
 */
class ExperimentalCallReportApiTest extends TestCase
{
    use BuildsTenants {
        provisionTenant as private tenantWithoutQueues;
    }
    use RefreshDatabase;

    /** A tenant with A-1's two queue names set, as Settings holds them (no names in code). */
    protected function provisionTenant(string $name = 'Alpha', ?string $email = null): array
    {
        $t = $this->tenantWithoutQueues($name, $email);
        $this->inTenant($t['org'], function () use ($t) {
            app(SettingsResolver::class)->set('leads.new_client_queue', $t['org'], 'A1 - New Client');
            app(SettingsResolver::class)->set('leads.existing_client_queue', $t['org'], 'A1 - Existing Client');
        });

        return $t;
    }

    // ── the gate ─────────────────────────────────────────────────

    /**
     * A report's days are the organization's own (Jose, 2026-11-04: on UTC,
     * "Yesterday" held the evening before and lost its own evening).
     */
    public function test_a_reports_day_is_cut_in_the_organizations_time_zone(): void
    {
        config(['app.timezone' => 'UTC']);
        $t = $this->provisionTenant('Alpha');
        $this->inTenant($t['org'], fn () => app(SettingsResolver::class)->set('timezone', $t['org'], 'America/New_York'));

        $this->actingAs($t['admin']);
        // A request has resolved its organization by the time a report asks.
        $this->inTenant($t['org'], fn () => $this->assertSame('America/New_York', ReportView::zone()));

        auth()->forgetGuards();
        $this->assertSame('UTC', ReportView::zone(), 'nobody signed in');
    }

    /** Running saved reports is a weaker right and must not open this. */
    public function test_reports_view_alone_cannot_read_it(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view']);

        $this->signedInAs($runner)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();
    }

    /** Somebody with no Reports permission at all gets nothing. */
    public function test_a_person_with_no_reports_permission_cannot_read_it(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $nobody = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $this->signedInAs($nobody)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();
    }

    /** `reports.build` is the permission that already means organization-wide. */
    public function test_reports_build_can_read_it(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $builder = $this->userWithRole($tenant, 'Analyst', [
            'reports.build',
            'reports.view',
            'reports.experimental.view',
        ]);

        $this->notConnected();

        $this->signedInAs($builder)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertOk();
    }

    /** Jose, 2026-10-19: "im on everdoors and it's showing all the a-1chimney info". */
    public function test_a_company_view_reads_only_that_companys_calls_and_workiz(): void
    {
        // The organization the env RingCentral account belongs to.
        $tenant = $this->provisionTenant('A-1 Chimney');
        $this->connected($tenant);
        // The env RingCentral account and both Workiz branches are the first company's.
        config(['experimental_reports.ringcentral.company_id' => $tenant['company']->id]);
        $other = $this->inTenant($tenant['org'], fn () => Company::create(['organization_id' => $tenant['org']->id, 'name' => 'Everdoors']));
        $doors = $this->extraFranchise($tenant, 'Doors Branch');
        $this->inTenant($tenant['org'], fn () => $doors->forceFill(['company_id' => $other->id])->save());
        $this->workizBranchAt($tenant, $doors, 'doors');

        // One scored call of the first company's account.
        config(['services.grok_cli.since' => '2026-09-01']);
        $this->inTenant($tenant['org'], function () use ($tenant) {
            CallReportTranscript::query()->create(['organization_id' => $tenant['org']->id, 'call_id' => 'c-1', 'transcript' => 'x',
                'source' => 'whisper', 'answered_by' => 'Trey Brannan', 'call_started_at' => '2026-09-14 15:00:00']);
            CallReportScore::query()->create(['organization_id' => $tenant['org']->id, 'call_id' => 'c-1', 'score' => 8, 'checks' => []]);
        });

        $as = fn (string $view) => $this->signedInAs($tenant['admin'])->withHeader('X-EzBizi-View', $view)
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')->assertOk()->json();

        $mine = $as('company:'.$tenant['company']->id);
        $this->assertTrue($mine['connected']);
        $this->assertSame(['Nationwide', 'New Jersey'], collect($mine['workiz_accounts'])->pluck('name')->sort()->values()->all());

        $theirs = $as('company:'.$other->id);
        $this->assertFalse($theirs['connected'], "the first company's RingCentral calls are not Everdoors'");

        $scoring = fn (string $view) => $this->signedInAs($tenant['admin'])->withHeader('X-EzBizi-View', $view)
            ->getJson('/api/v1/reports/scoring?from=2026-09-14&to=2026-09-14')->assertOk()->json('data.people');
        $this->assertCount(1, $scoring('company:'.$tenant['company']->id));
        $this->assertSame([], $scoring('company:'.$other->id));
    }

    /**
     * Jose, 2026-10-15: people assigned to one company "should be able to see
     * it regardless". The tab is the gate, and every branch's Workiz counts.
     */
    public function test_the_tab_alone_reads_it_from_one_branch(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        // The env RingCentral account is this branch's company's (2026-10-19).
        config(['experimental_reports.ringcentral.company_id' => $tenant['company']->id]);
        $branchOnly = $this->userWithRole($tenant, 'Lead Manager', ['reports.experimental.view'], onlyAt: $tenant['franchise']);

        $report = $this->signedInAs($branchOnly)
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();
        // Jose, 2026-10-19: Reports follows the current view. A branch-only
        // reader sees the branches in their view, which here have no Workiz.
        $this->assertSame([], $report['workiz_accounts']);

        $this->assertNotSame(403, $this->signedInAs($branchOnly)->get('/api/v1/reports/experimental/calls/rec-9/recording')->status());
    }

    // ── an absent vendor is a state, not an empty report ─────────

    /**
     * "Nobody called today" and "nothing is plugged in" look identical on a
     * screen and mean opposite things.
     */
    public function test_a_missing_ringcentral_reads_as_not_connected(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $this->notConnected();

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertOk()
            ->json();

        $this->assertFalse($body['connected']);
        $this->assertSame('RingCentral', $body['missing']);
        $this->assertArrayNotHasKey('people', $body);
    }

    // ── the payload ──────────────────────────────────────────────

    public function test_it_returns_the_day_per_person(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/New_York')
            ->assertOk()
            ->json();

        $this->assertTrue($body['connected']);
        $this->assertSame('2026-09-14', $body['day']);
        $this->assertSame(1, $body['totals']['answered']);
        $this->assertSame('Trey Brannan', $body['people'][0]['person']);
        $this->assertSame(1, $body['people'][0]['answered']);
    }

    /** Workiz's own scores per person, beside ours (Jose, 2026-10-12). */
    public function test_workiz_scores_are_summed_per_person_for_the_dates(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $this->inTenant($tenant['org'], function () use ($tenant) {
            foreach ([
                ['2026-09-14 09:00:00', 80, true, 'great'],
                ['2026-09-14 17:00:00', 60, false, 'negative'],
                ['2026-09-15 09:00:00', 10, false, 'negative'],   // another day
                ['2026-09-14 10:00:00', null, null, null],        // not scored
            ] as $n => [$at, $score, $booked, $sentiment]) {
                WorkizCall::query()->create([
                    'franchise_id' => $tenant['franchise']->id, 'remote_id' => 'call-'.$n,
                    'direction' => 'inbound', 'status' => 'answered', 'user_name' => 'Trey Brannan',
                    'dispatcher_score' => $score, 'booked' => $booked, 'sentiment' => $sentiment,
                    'pricing_discussed' => $booked, 'called_at' => $at, 'synced_at' => now(),
                ]);
            }
        });

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/New_York')
            ->assertOk()
            ->assertJsonPath('workiz_scores.Trey Brannan', [
                'calls' => 2, 'score' => 70, 'booked' => 50, 'pricing' => 50, 'positive' => 50,
            ]);
    }

    /**
     * THE BOUNDARY IS PART OF THE ANSWER. A day an hour out moves calls
     * between days and nothing about the result would look wrong, so the zone
     * that produced the counts is echoed back rather than assumed.
     */
    public function test_the_timezone_the_day_was_cut_in_is_in_the_payload(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/Chicago')
            ->assertOk()
            ->json();

        $this->assertSame('America/Chicago', $body['timezone']);
    }

    public function test_an_unparseable_timezone_is_refused_rather_than_guessed(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?timezone=Pacific/Nowhere')
            ->assertStatus(422);
    }

    /**
     * The accounts are disjoint and the same people book into all of them, so
     * a missing account gets a person's job count WRONG rather than merely
     * omitting a region. The screen has to be able to name what it covers.
     */
    public function test_it_names_the_workiz_accounts_it_covers(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertSame(
            ['Nationwide', 'New Jersey'],
            array_column($body['workiz_accounts'], 'name'),
        );

        // And each one carries the id that disconnects it. Nothing else
        // publishes it - a data-source provider is excluded from the shared
        // connections list on purpose.
        foreach ($body['workiz_accounts'] as $account) {
            $this->assertNotEmpty($account['id']);
        }
    }

    // ── fixtures ─────────────────────────────────────────────────

    // ── the calls behind the numbers ─────────────────────────────

    /**
     * THE CALLS THEMSELVES TRAVEL, so a person's row can be opened.
     *
     * Jose, 2026-09-17: "arrow on side, when clicked, open list of calls with
     * recordings for that person, so i can see more details". The aggregate
     * columns cannot answer that on their own.
     *
     * Sent FLAT rather than nested under each person: a queue call rings
     * several people, and nesting would send the same call once per person —
     * the same double-count the `rang` column exists to warn about, arriving
     * as bytes.
     */
    public function test_the_calls_behind_the_numbers_are_in_the_payload(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $calls = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json('calls');

        $this->assertCount(1, $calls);
        $this->assertSame('Trey Brannan', $calls[0]['answered_by']);
        $this->assertSame('rec-9', $calls[0]['recording_id']);
        $this->assertSame('PERRON,NICOLE', $calls[0]['caller_label']);

        // The names in the drill-down are canonicalised the same way the
        // columns are. Left raw, a call would sit under a row labelled with a
        // different spelling of the same person.
        $this->assertContains('Trey Brannan', $calls[0]['rang']);
    }

    // ── the recording ────────────────────────────────────────────

    public function test_a_recording_needs_the_same_permission_as_the_report(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view']);

        $this->signedInAs($runner)
            ->get('/api/v1/reports/experimental/calls/rec-9/recording')
            ->assertForbidden();
    }

    /**
     * A 403 FROM RINGCENTRAL NAMES THE MISSING PERMISSION.
     *
     * Measured against the live account on 2026-09-17: the recordings exist
     * and the audio returns 403 `ReadCallRecording`, because EzBizi's
     * RingCentral app was never granted that scope. Nothing in this codebase
     * fixes it.
     *
     * So the refusal has to say where the fix is. "RingCentral refused (403)"
     * reads as a broken build and sends somebody into the wrong repository for
     * an afternoon.
     */
    public function test_a_recording_refused_for_permission_says_where_the_fix_is(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/recording/*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $message = $this->signedInAs($tenant['admin'])
            ->get('/api/v1/reports/experimental/calls/rec-9/recording')
            ->assertStatus(502)
            ->json('message');

        $this->assertStringContainsString('ReadCallRecording', $message);

        // And it says the change is not here, which is the actionable half.
        $this->assertStringContainsString('developer console', $message);
    }

    public function test_a_recording_that_loads_comes_back_as_audio(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/recording/*' => Http::response('ID3-and-then-some', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $response = $this->signedInAs($tenant['admin'])
            ->get('/api/v1/reports/experimental/calls/rec-9/recording')
            ->assertOk();

        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));

        // Inline, not an attachment: one click must not drop customer call
        // audio into somebody's Downloads folder.
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('ID3-and-then-some', $response->getContent());
    }

    // ── jobs come from the synced copy (2026-10-06) ──────────────────

    /**
     * WORKIZ IS NOT ASKED: the report reads EzBizi's synced copy, which is
     * what took its load from seconds to a database read (Jose, 2026-10-06:
     * "Experimental loading slow").
     */
    public function test_jobs_come_from_the_synced_copy_and_workiz_is_not_called(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/New_York')
            ->assertOk()
            ->json();

        // The one synced job, booked that day by Trey Brannan.
        $this->assertSame(1, $body['totals']['jobs']);
        $this->assertSame(1, collect($body['people'])->firstWhere('person', 'Trey Brannan')['jobs']);
        $this->assertNotNull($body['workiz_synced_at']);
        $this->assertSame([], $body['workiz_unreachable'], 'Massachusetts is connected now');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.workiz.com'));
    }

    // ── period, tags, convert (Jose, 2026-10-05) ─────────────────────

    /** A week runs Monday to Sunday around the day asked for. */
    public function test_a_week_is_asked_of_ringcentral_monday_to_sunday(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-16&period=week&timezone=America/New_York')
            ->assertOk()
            ->json();

        $this->assertSame(['2026-09-14', '2026-09-20', 'week'], [$body['from'], $body['to'], $body['period']]);

        // Read a day at a time (so finished days can be cached): seven
        // requests, the first starting Monday and the last ending Sunday.
        $days = collect(Http::recorded())
            ->map(fn ($pair) => urldecode($pair[0]->url()))
            ->filter(fn ($url) => str_contains($url, 'call-log'));

        $this->assertCount(7, $days);
        $this->assertStringContainsString('dateFrom=2026-09-14T00:00:00-04:00', $days->first());
        $this->assertStringContainsString('dateTo=2026-09-20T23:59:59-04:00', $days->last());
    }

    /** Any range, or one date, chosen by hand - and not more than a quarter. */
    public function test_a_custom_range_is_read_from_its_first_day_to_its_last(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?from=2026-09-10&to=2026-09-12&timezone=America/New_York')
            ->assertOk()
            ->json();

        $this->assertSame(['2026-09-10', '2026-09-12', 'custom'], [$body['from'], $body['to'], $body['period']]);
        $this->assertCount(3, collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'call-log')));

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?from=2026-01-01&to=2026-09-12')
            ->assertStatus(422);

        $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?from=2026-09-12&to=2026-09-10')
            ->assertStatus(422);
    }

    /** A dispatcher's tag is set by hand and comes back on their row. */
    public function test_a_dispatcher_tag_is_set_and_shown(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $this->signedInAs($tenant['admin'])
            ->putJson('/api/v1/reports/experimental/people/tag', ['person' => 'Trey Brannan', 'tag' => 'existing'])
            ->assertOk();

        $people = collect($this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->json('people'))->keyBy('person');

        $this->assertSame('existing', $people['Trey Brannan']['tag']);

        // And cleared.
        $this->signedInAs($tenant['admin'])
            ->putJson('/api/v1/reports/experimental/people/tag', ['person' => 'Trey Brannan', 'tag' => null])
            ->assertOk();

        $this->assertSame(0, CallReportDispatcherTag::withoutGlobalScopes()->count());
    }

    /**
     * CONVERT TO BOOKED marks the call booked and writes its history line;
     * the call in the fixture has no Workiz job for its number, so only the
     * mark can make it booked.
     */
    public function test_convert_to_booked_marks_the_call_and_records_who(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);

        $before = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')->json();

        $this->assertSame(1, $before['totals']['not_booked']);
        $this->assertFalse($before['calls'][0]['booked']);

        $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/reports/experimental/calls/1/convert', ['started_at' => '2026-09-14T13:21:11Z'])
            ->assertOk()
            ->assertJsonPath('data.converted_by', $tenant['admin']->name);

        $after = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')->json();

        $this->assertSame(0, $after['totals']['not_booked']);
        $this->assertTrue($after['calls'][0]['booked']);
        $this->assertSame('1', $after['conversions'][0]['call_id']);
        $this->assertSame($tenant['admin']->name, $after['conversions'][0]['converted_by']);
    }

    /**
     * Jose, 2026-11-03, for Trey: a call's client and booking set by hand,
     * both ways, with who; the counts follow, and deleting reverts.
     */
    public function test_a_call_s_client_and_booking_are_set_by_hand_and_revert(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);
        $as = $this->signedInAs($tenant['admin']);
        $day = fn () => $this->signedInAs($tenant['admin'])->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')->json();
        $queue = $day()['calls'][0]['queue'];

        $as->putJson('/api/v1/reports/experimental/calls/1/override', ['field' => 'booking', 'value' => 'booked'])
            ->assertOk()->assertJsonPath('data.set_by', $tenant['admin']->name);
        $after = $day();
        $this->assertTrue($after['calls'][0]['booked']);
        $this->assertSame(0, $after['totals']['not_booked']);
        $this->assertSame($tenant['admin']->name, $after['calls'][0]['set_by']['booking']);

        $flip = $queue === 'A1 - New Client' ? 'existing' : 'new';
        $this->signedInAs($tenant['admin'])->putJson('/api/v1/reports/experimental/calls/1/override', ['field' => 'client', 'value' => $flip])->assertOk();
        $this->assertNotSame($queue, $day()['calls'][0]['queue']);
        $this->assertSame($queue, $day()['calls'][0]['reclassified']['pressed']);

        $this->signedInAs($tenant['admin'])->putJson('/api/v1/reports/experimental/calls/1/override', ['field' => 'booking', 'value' => 'shipped'])->assertStatus(422);
        $this->signedInAs($tenant['admin'])->deleteJson('/api/v1/reports/experimental/calls/1/override?field=client')->assertOk();
        $this->signedInAs($tenant['admin'])->deleteJson('/api/v1/reports/experimental/calls/1/override?field=booking')->assertOk();
        $back = $day();
        $this->assertSame($queue, $back['calls'][0]['queue']);
        $this->assertFalse($back['calls'][0]['booked']);
        $this->assertNull($back['calls'][0]['set_by']);
    }

    /**
     * Trey, 2026-11-04 ("sometimes we get spam calls", "not a cx since we dont
     * do home inspections"): a marked call leaves the booking rate and Not
     * booked, stays listed with its mark, and says who marked it.
     */
    public function test_a_call_marked_not_a_customer_leaves_the_booking_rate_and_stays_listed(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);
        $day = fn () => $this->signedInAs($tenant['admin'])->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')->json();
        $before = $day();
        $person = fn (array $report) => collect($report['people'])->firstWhere('person', $before['calls'][0]['answered_by']);
        $this->assertSame(1, $before['totals']['not_booked']);
        $this->assertSame(1, $person($before)['new_client']);

        $this->signedInAs($tenant['admin'])->putJson('/api/v1/reports/experimental/calls/1/override', ['field' => 'mark', 'value' => 'not_a_customer'])
            ->assertOk()->assertJsonPath('data.set_by', $tenant['admin']->name);
        $after = $day();
        $this->assertSame(0, $after['totals']['not_booked']);
        $this->assertSame(0, $person($after)['new_client']);
        $this->assertSame('not_a_customer', $after['calls'][0]['mark']);
        $this->assertSame($tenant['admin']->name, $after['calls'][0]['set_by']['mark']);
        $this->assertSame(1, AuditEvent::query()->withoutGlobalScopes()->where('action', 'call_report.overridden')
            ->where('actor_id', $tenant['admin']->id)->where('metadata->value', 'not_a_customer')->count());

        $this->signedInAs($tenant['admin'])->putJson('/api/v1/reports/experimental/calls/1/override', ['field' => 'mark', 'value' => 'rude'])->assertStatus(422);
        $this->signedInAs($tenant['admin'])->deleteJson('/api/v1/reports/experimental/calls/1/override?field=mark')->assertOk();
        $back = $day();
        $this->assertSame(1, $back['totals']['not_booked']);
        $this->assertNull($back['calls'][0]['mark']);
    }

    /**
     * Jose, 2026-11-12 ("make a new filter for the AI heard a booking, no job
     * yet, call it Needs Attention"): neither booked nor not booked until a
     * job matches, and then it leaves on its own.
     */
    public function test_a_call_the_ai_heard_book_with_no_job_needs_attention_until_one_matches(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $this->connected($tenant);
        $day = fn () => $this->signedInAs($tenant['admin'])->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')->assertOk()->json();
        $this->inTenant($tenant['org'], fn () => CallReportLabel::query()->create(['call_id' => '1', 'provider' => 'grok', 'label' => 'booked']));

        $open = $day();
        $person = fn (array $report) => collect($report['people'])->firstWhere('person', $open['calls'][0]['answered_by']);
        $this->assertTrue($open['calls'][0]['needs_attention']);
        $this->assertSame(0, $open['totals']['not_booked']);
        $this->assertSame(1, $open['totals']['needs_attention']);
        $this->assertSame(1, $person($open)['needs_attention']);

        // The job is booked in Workiz for the caller's number.
        $nationwide = $this->inTenant($tenant['org'], fn () => (string) SocialConnection::query()->where('display_name', 'Nationwide')->value('id'));
        $this->syncedJob($tenant, $nationwide, '7579553189', 'Trey Brannan', '2026-09-14 10:00:00');
        $done = $day();
        $this->assertFalse($done['calls'][0]['needs_attention']);
        $this->assertTrue($done['calls'][0]['booked']);
        $this->assertSame(0, $done['totals']['needs_attention']);
        $this->assertSame(1, $person($done)['booked_new']);
    }

    /** Both writes sit behind the pane's own gate. */
    public function test_tag_and_convert_need_the_experimental_tab(): void
    {
        $tenant = $this->asRingCentralEnvOwner($this->provisionTenant('Alpha'));
        $builder = $this->userWithRole($tenant, 'Analyst', ['reports.view', 'reports.build']);

        $this->signedInAs($builder)
            ->putJson('/api/v1/reports/experimental/people/tag', ['person' => 'X', 'tag' => 'new'])
            ->assertForbidden();

        $this->signedInAs($builder)
            ->postJson('/api/v1/reports/experimental/calls/1/convert')
            ->assertForbidden();

        $this->signedInAs($builder)
            ->putJson('/api/v1/reports/experimental/calls/1/override', ['field' => 'booking', 'value' => 'booked'])
            ->assertForbidden();

        $this->assertSame(0, CallReportConversion::withoutGlobalScopes()->count());
    }

    private function notConnected(): void
    {
        config([
            'experimental_reports.ringcentral.client_id' => null,
            'experimental_reports.ringcentral.client_secret' => null,
            'experimental_reports.ringcentral.jwt' => null,
        ]);
    }

    private function connected(array $tenant, bool $njRefuses = false, bool $allRefuse = false): void
    {
        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        $nationwide = $this->workizBranch($tenant, 'Nationwide', 'nat');
        $this->workizBranch($tenant, 'New Jersey', 'nj');
        $this->syncedJob($tenant, $nationwide, '2125551212', 'Trey Brannan', '2026-09-14 09:00:00');

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),

            '*/call-log*' => Http::response([
                'paging' => ['totalPages' => 1],
                'records' => [[
                    'id' => '1',
                    'direction' => 'Inbound',
                    'result' => 'Accepted',
                    'startTime' => '2026-09-14T13:21:11.795Z',
                    'from' => ['phoneNumber' => '+17579553189', 'name' => 'PERRON,NICOLE'],
                    'to' => ['phoneNumber' => '+18888602433', 'name' => 'Main - IVR'],
                    // Present on 175 of 250 real inbound calls, measured
                    // 2026-09-17. Its ABSENCE is equally ordinary, which is why
                    // recording_id is nullable rather than assumed.
                    'recording' => [
                        'id' => 'rec-9',
                        'type' => 'Automatic',
                        'contentUri' => 'https://media.ringcentral.test/restapi/v1.0/account/1/recording/rec-9/content',
                    ],

                    'legs' => [
                        ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'Main - IVR']],
                        ['legType' => 'Accept', 'result' => 'Accepted', 'to' => ['name' => 'A1 - New Client']],
                        ['legType' => 'PstnToSip', 'result' => 'Call connected', 'from' => ['name' => 'Trey Brannan'], 'to' => ['extensionNumber' => '748']],
                    ],
                ]],
            ]),

            'api.workiz.com/api/v1/nj/*' => ($njRefuses || $allRefuse)
                ? Http::response([], 403)
                : Http::response(['flag' => true, 'data' => []]),

            'api.workiz.com/*' => $allRefuse ? Http::response([], 403) : Http::response(['flag' => true, 'data' => [
                ['Phone' => '2125551212', 'CreatedBy' => 'Trey Brannan', 'CreatedDate' => '2026-09-14 09:00:00'],
            ]]),
        ]);
    }

    /**
     * A branch with a Workiz account attached, which is where the tokens live
     * now — `scope_type = franchise`, per Jose's ruling.
     */
    private function workizBranchAt(array $tenant, Franchise $franchise, string $token): string
    {
        return $this->inTenant($tenant['org'], function () use ($tenant, $franchise, $token) {
            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_FRANCHISE, 'scope_id' => $franchise->id,
                'provider' => SocialProvider::Workiz->value, 'remote_id' => $franchise->id, 'display_name' => $franchise->name,
            ]);
            $connection->connect(['refresh_token' => $token, 'api_token' => $token]);
            $connection->save();

            return (string) $connection->getKey();
        });
    }

    private function workizBranch(array $tenant, string $name, string $token): string
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
            $connection->connect(['refresh_token' => $token, 'api_token' => $token]);
            $connection->save();

            return (string) $connection->getKey();
        });
    }

    /** One job in the synced copy, shaped as Workiz sends it. */
    private function syncedJob(array $tenant, string $connectionId, string $phone, string $by, string $bookedAt): void
    {
        $this->inTenant($tenant['org'], fn () => WorkizJob::query()->create([
            'franchise_id' => SocialConnection::query()->find($connectionId)->scope_id,
            'connection_id' => $connectionId,
            'remote_id' => strtoupper(bin2hex(random_bytes(4))),
            'booked_at' => $bookedAt,
            'payload' => ['Phone' => $phone, 'CreatedBy' => $by, 'CreatedDate' => $bookedAt],
            'synced_at' => now(),
        ]));
    }
}
