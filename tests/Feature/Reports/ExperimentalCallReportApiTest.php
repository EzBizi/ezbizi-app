<?php

namespace Tests\Feature\Reports;

use App\Models\CallReportConversion;
use App\Models\CallReportDispatcherTag;
use App\Models\Franchise;
use App\Models\SocialConnection;
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
    use BuildsTenants;
    use RefreshDatabase;

    // ── the gate ─────────────────────────────────────────────────

    /** Running saved reports is a weaker right and must not open this. */
    public function test_reports_view_alone_cannot_read_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view']);

        $this->signedInAs($runner)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();
    }

    /** Somebody with no Reports permission at all gets nothing. */
    public function test_a_person_with_no_reports_permission_cannot_read_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $nobody = $this->userWithRole($tenant, 'Technician', ['jobs.view']);

        $this->signedInAs($nobody)
            ->getJson('/api/v1/reports/experimental/calls')
            ->assertForbidden();
    }

    /** `reports.build` is the permission that already means organization-wide. */
    public function test_reports_build_can_read_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');

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

    // ── an absent vendor is a state, not an empty report ─────────

    /**
     * "Nobody called today" and "nothing is plugged in" look identical on a
     * screen and mean opposite things.
     */
    public function test_a_missing_ringcentral_reads_as_not_connected(): void
    {
        $tenant = $this->provisionTenant('Alpha');

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
        $tenant = $this->provisionTenant('Alpha');

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

    /**
     * THE BOUNDARY IS PART OF THE ANSWER. A day an hour out moves calls
     * between days and nothing about the result would look wrong, so the zone
     * that produced the counts is echoed back rather than assumed.
     */
    public function test_the_timezone_the_day_was_cut_in_is_in_the_payload(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14&timezone=America/Chicago')
            ->assertOk()
            ->json();

        $this->assertSame('America/Chicago', $body['timezone']);
    }

    public function test_an_unparseable_timezone_is_refused_rather_than_guessed(): void
    {
        $tenant = $this->provisionTenant('Alpha');

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
        $tenant = $this->provisionTenant('Alpha');

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

    /** One refused account must be named, not folded into a smaller number. */
    public function test_a_refused_workiz_account_is_named(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant, njRefuses: true);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('New Jersey', $body['workiz_failed']);
        $this->assertArrayNotHasKey('Nationwide', $body['workiz_failed']);

        // And the calls still came through.
        $this->assertSame(1, $body['totals']['answered']);
    }

    /**
     * EVERY account refusing is a different arm from one refusing.
     *
     * It builds the failure map itself rather than receiving it, and it did so
     * with `array_fill_keys($accounts, ...)` after `accounts()` started
     * carrying {id, name} maps for the disconnect chips — array keys, which is
     * a TypeError rather than a wrong answer. Nothing caught it because the
     * other refusal test refuses exactly one account.
     */
    public function test_every_workiz_account_refusing_still_returns_the_calls(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant, allRefuse: true);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('Nationwide', $body['workiz_failed']);
        $this->assertArrayHasKey('New Jersey', $body['workiz_failed']);

        // The calls survive. A blank jobs column must not read as "nobody
        // booked anything".
        $this->assertSame(1, $body['totals']['answered']);
    }

    /**
     * A BOOK WE WILL NEVER READ, named rather than absent.
     *
     * Jose, 2026-09-15: "we dont pay the api access there". Massachusetts has
     * no Workiz API subscription, so the undercount is permanent rather than a
     * configuration somebody will get round to — and since the same people book
     * into every account, it makes individual people's numbers low rather than
     * omitting a region. "Not connected" would read as a task.
     */
    public function test_an_account_with_no_api_subscription_is_named_as_unreachable(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        $this->assertSame('Massachusetts', $body['workiz_unreachable'][0]['name']);
        $this->assertStringContainsString(
            'not paid for',
            $body['workiz_unreachable'][0]['reason'],
        );
    }

    /**
     * ROUNDS, not one-account-at-a-time, and a short page is what drops out.
     *
     * `all()` fans out concurrently now: every still-hungry branch asks for its
     * next page in one round, and a branch returning fewer than PAGE rows stops
     * being asked. Nine live branches over ~840 rows settle in two rounds
     * instead of seventeen sequential requests (measured 9.2s in series).
     *
     * The loop is the part worth pinning. A branch with a FULL first page must
     * be asked again, and one with a short page must not - get that wrong and
     * the report either loses rows silently or loops forever against an API
     * that ignores the offset.
     *
     * ── IT DOES NOT BELONG TO THIS FILE'S OWNER, AND IT STAYS ANYWAY ──
     *
     * `WorkizJobs` is the Workiz session's file as of 2026-09-23
     * (`local_ea9c0484-3b23-40b6-8262-10a1fccc1a3a`). Every other test here
     * asserts what the REPORT does with what that client returns, which is why
     * they live on this side of the boundary. THIS one is the exception: it
     * asserts what the CLIENT does with an offset, and it is the only test
     * anywhere of that loop.
     *
     * Left here deliberately rather than moved or copied. A second copy on
     * their side would carry the same invented fixtures and prove only that it
     * agrees with itself, and moving it would put the report's own paging
     * expectation out of reach of the report's suite.
     *
     * So: IF THIS TEST IS EVER RETIRED OR REWRITTEN, TELL THEM FIRST. The loop
     * goes to zero coverage silently otherwise, and its own docblock records a
     * first draft that asked for `records=1000` - which would have failed on
     * every live run with nothing red, because every fixture in this file is
     * invented.
     */
    public function test_a_branch_with_a_full_page_is_asked_for_the_next_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        config([
            'experimental_reports.ringcentral.server' => 'https://platform.ringcentral.test',
            'experimental_reports.ringcentral.client_id' => 'id',
            'experimental_reports.ringcentral.client_secret' => 'secret',
            'experimental_reports.ringcentral.jwt' => 'jwt',
        ]);

        $this->workizBranch($tenant, 'Nationwide', 'nat');
        $this->workizBranch($tenant, 'New Jersey', 'nj');

        // Nationwide: a FULL page, then a short one. New Jersey: short at once.
        $full = array_fill(0, 100, [
            'Phone' => '2125551212',
            'CreatedBy' => 'Trey Brannan',
            'CreatedDate' => '2026-09-14 09:00:00',
        ]);

        Http::fake([
            '*/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/call-log*' => Http::response(['paging' => ['totalPages' => 1], 'records' => []]),

            /*
             * ANSWERED BY THE OFFSET ASKED FOR, not in sequence. A sequence
             * answered page two whatever was asked, so this test stayed green
             * while the loop asked Workiz for page 100 (Workiz session,
             * 2026-09-28). `offset` is a page number: 0, then 1.
             */
            'api.workiz.com/api/v1/nat/*' => fn ($request) => Http::response(match ((string) ($request->data()['offset'] ?? '')) {
                '0' => ['flag' => true, 'data' => $full],
                '1' => ['flag' => true, 'data' => [[
                    'Phone' => '2125559999',
                    'CreatedBy' => 'Trey Brannan',
                    'CreatedDate' => '2026-09-14 10:00:00',
                ]]],
                default => ['flag' => true, 'data' => []],
            }),

            'api.workiz.com/api/v1/nj/*' => Http::response(['flag' => true, 'data' => []]),
        ]);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-14')
            ->assertOk()
            ->json();

        // 100 + 1. The second page is only fetched if the first being full put
        // Nationwide back into the next round.
        $this->assertSame(101, $body['totals']['jobs']);

        // And New Jersey, short on its first page, was not asked twice - which
        // a sequence with nothing left to push would have turned into a failure.
        $this->assertSame([], (array) $body['workiz_failed']);
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
        $tenant = $this->provisionTenant('Alpha');
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
        $tenant = $this->provisionTenant('Alpha');
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
        $tenant = $this->provisionTenant('Alpha');
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
        $tenant = $this->provisionTenant('Alpha');
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

    // ── period, tags, convert (Jose, 2026-10-05) ─────────────────────

    /** A week runs Monday to Sunday around the day asked for. */
    public function test_a_week_is_asked_of_ringcentral_monday_to_sunday(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connected($tenant);

        $body = $this->signedInAs($tenant['admin'])
            ->getJson('/api/v1/reports/experimental/calls?day=2026-09-16&period=week&timezone=America/New_York')
            ->assertOk()
            ->json();

        $this->assertSame(['2026-09-14', '2026-09-20', 'week'], [$body['from'], $body['to'], $body['period']]);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'call-log')
            && str_contains(urldecode($request->url()), 'dateFrom=2026-09-14T00:00:00-04:00')
            && str_contains(urldecode($request->url()), 'dateTo=2026-09-20T23:59:59-04:00'));
    }

    /** A dispatcher's tag is set by hand and comes back on their row. */
    public function test_a_dispatcher_tag_is_set_and_shown(): void
    {
        $tenant = $this->provisionTenant('Alpha');
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
        $tenant = $this->provisionTenant('Alpha');
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

    /** Both writes sit behind the pane's own gate. */
    public function test_tag_and_convert_need_the_experimental_tab(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $builder = $this->userWithRole($tenant, 'Analyst', ['reports.view', 'reports.build']);

        $this->signedInAs($builder)
            ->putJson('/api/v1/reports/experimental/people/tag', ['person' => 'X', 'tag' => 'new'])
            ->assertForbidden();

        $this->signedInAs($builder)
            ->postJson('/api/v1/reports/experimental/calls/1/convert')
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

        $this->workizBranch($tenant, 'Nationwide', 'nat');
        $this->workizBranch($tenant, 'New Jersey', 'nj');

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
    private function workizBranch(array $tenant, string $name, string $token): void
    {
        $this->inTenant($tenant['org'], function () use ($tenant, $name, $token) {
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
        });
    }
}
