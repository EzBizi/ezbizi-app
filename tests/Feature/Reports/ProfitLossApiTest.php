<?php

namespace Tests\Feature\Reports;

use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reading a business's profit and loss over the API.
 *
 * The parser itself is pinned in AirtablePnlTest against a real measured week.
 * What is pinned HERE is everything around it: who may ask, which bases they
 * can reach, which week arrives when nobody named one, and whether the sheet's
 * own contradictions survive the journey to the screen.
 */
class ProfitLossApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BASE = 'appWhbwht0xgB5Yut';

    // ── the gate ─────────────────────────────────────────────────

    /**
     * REPORTS.VIEW IS NOT ENOUGH, and that is the reason this is its own tab.
     *
     * The sheet carries payroll and owner distributions by name. Somebody who
     * can measure jobs and calls holds `reports.view` and must not thereby
     * acquire the partners' drawings — Jose's call on 2026-09-17, asked before
     * building rather than after somebody noticed.
     */
    public function test_reports_view_alone_cannot_read_profit_and_loss(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $runner = $this->userWithRole($tenant, 'Runner', ['reports.view', 'reports.build']);

        $this->signedInAs($runner)
            ->getJson('/api/v1/reports/profit-loss/sources')
            ->assertForbidden();

        $this->signedInAs($runner)
            ->getJson('/api/v1/reports/profit-loss?connection=1&table=NJ')
            ->assertForbidden();

        // And nothing was asked of Airtable on the way to the refusal.
        Http::assertNothingSent();
    }

    // ── the sources ──────────────────────────────────────────────

    public function test_sources_list_each_connected_base_and_its_tables(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connectBase($tenant);
        $this->fakeAirtable();

        $sources = $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss/sources')
            ->assertOk()
            ->json('sources');

        $this->assertCount(1, $sources);
        $this->assertSame(['NJ', 'CT'], $sources[0]['tables']);
        $this->assertNull($sources[0]['unavailable']);

        // The token never comes back out, on this path as on every other.
        $this->assertStringNotContainsString('pat_secret_value', json_encode($sources));
    }

    /**
     * A BASE WHOSE TOKEN HAS STOPPED WORKING IS LISTED, NOT DROPPED.
     *
     * Dropping it makes a base somebody connected last week disappear with no
     * explanation, which reads as EzBizi having lost it. The refusal is a fact
     * about the credential and belongs on screen beside the base it concerns.
     */
    public function test_a_base_that_refuses_is_listed_with_its_reason(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->connectBase($tenant);

        Http::fake(['api.airtable.com/*' => Http::response([], 401)]);

        $sources = $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss/sources')
            ->assertOk()
            ->json('sources');

        $this->assertCount(1, $sources);
        $this->assertSame([], $sources[0]['tables']);
        $this->assertNotNull($sources[0]['unavailable']);
    }

    // ── the week ─────────────────────────────────────────────────

    /**
     * NAMING NO WEEK GIVES THE LATEST COMPLETE ONE, not the last row.
     *
     * Opening the tab has to show something, and the something cannot be a
     * half-entered week: its figures are not smaller, they are wrong, and a
     * missing cost category reads as a good week rather than an unfinished one.
     */
    public function test_no_week_named_gives_the_latest_complete_week(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connectBase($tenant);
        $this->fakeAirtable($this->twoWeeks());

        $body = $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss?connection='.$connection->id.'&table=NJ')
            ->assertOk()
            ->json();

        // Both weeks are offered for picking...
        $this->assertSame(['Week One', 'Week Two'], $body['weeks']);

        // ...and the one that opened is the complete one, even though the
        // incomplete week is LATER in the sheet.
        $this->assertSame('Week One', $body['week']['week']);
        $this->assertTrue($body['week']['complete']);
        // assertEquals, not assertSame: JSON has no int/float distinction, so
        // a whole-dollar total crosses the wire as 1000 rather than 1000.0. The
        // PHP-level type is pinned with assertSame in AirtablePnlTest, which is
        // where it is a real claim.
        $this->assertEquals(1000, $body['week']['income']);
        $this->assertEquals(400, $body['week']['costs']);
        $this->assertEquals(600, $body['week']['net']);
    }

    /**
     * ASKING FOR THE INCOMPLETE WEEK GIVES IT, carrying no figures.
     *
     * The picker offers it, so it has to be openable — and what it must not do
     * is answer with zeros. `complete: false` and nulls are the honest payload.
     */
    public function test_an_incomplete_week_carries_nulls_rather_than_zeros(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connectBase($tenant);
        $this->fakeAirtable($this->twoWeeks());

        $week = $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss?connection='.$connection->id
                .'&table=NJ&week='.urlencode('Week Two'))
            ->assertOk()
            ->json('week');

        $this->assertFalse($week['complete']);
        $this->assertNull($week['income']);
        $this->assertNull($week['net']);
    }

    /**
     * THE SHEET CONTRADICTING ITSELF REACHES THE SCREEN.
     *
     * This is the assertion the whole module exists for. A-1's real base has a
     * week whose Tech Expenses lines total $5,423.20 against a stated $3,810,
     * and the reader reports that rather than picking a side. If it were ever
     * quietly resolved, every figure would still look perfectly ordinary —
     * so the payload is asserted, not the parser's internals.
     */
    public function test_a_category_that_disagrees_with_its_own_total_is_reported(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connectBase($tenant);

        $this->fakeAirtable([
            ['Week' => 'Week One', 'Income' => 'Total', 'Income Amount' => 1000],
            ['Week' => 'Week One', 'Income' => 'Cash', 'Income Amount' => 1000],

            // The lines add to 400. The sheet says 250.
            ['Week' => 'Week One', 'Parts' => 'Total', 'Parts Amount' => 250],
            ['Week' => 'Week One', 'Parts' => 'Pipe', 'Parts Amount' => 400],
        ]);

        $week = $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss?connection='.$connection->id.'&table=NJ')
            ->assertOk()
            ->json('week');

        $this->assertCount(1, $week['disagreements']);
        $this->assertStringContainsString('Parts', $week['disagreements'][0]);

        // And the LINES decided the answer, not the total cell.
        $this->assertEquals(400, $week['costs']);
        $this->assertEquals(600, $week['net']);
    }

    /**
     * A base outside this person's view is NOT FOUND rather than readable.
     *
     * The tenant scope narrows to the organization; only `inView` narrows to
     * the company being looked at. For figures like these, one company's
     * profit surfacing in a sibling's screen is worse than most disclosures
     * this application could make.
     */
    public function test_a_connection_outside_the_view_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $other = $this->provisionTenant('Beta');

        $theirs = $this->connectBase($other);
        $this->fakeAirtable();

        $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss?connection='.$theirs->id.'&table=NJ')
            ->assertNotFound();
    }

    /**
     * AIRTABLE FAILING IS A 502, NOT A 500.
     *
     * A 500 says EzBizi is broken and sends somebody to the wrong place. The
     * sheet's host being unreachable is a fact about Airtable and a retry is
     * the reasonable response to it.
     */
    public function test_airtable_refusing_the_read_is_a_bad_gateway(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $connection = $this->connectBase($tenant);

        Http::fake(['api.airtable.com/*' => Http::response([], 500)]);

        $this->signedInAs($this->accountant($tenant))
            ->getJson('/api/v1/reports/profit-loss?connection='.$connection->id.'&table=NJ')
            ->assertStatus(502);
    }

    // ── scaffolding ──────────────────────────────────────────────

    /** Somebody who holds the new grant and nothing else that would explain it. */
    private function accountant(array $tenant)
    {
        return $this->userWithRole($tenant, 'Accountant', ['profit_loss.view']);
    }

    private function connectBase(array $tenant): SocialConnection
    {
        return $this->inTenant($tenant['org'], function () use ($tenant) {
            $connection = new SocialConnection;

            $connection->forceFill([
                'organization_id' => $tenant['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => (string) $tenant['org']->id,
                'provider' => SocialProvider::Airtable->value,
                'remote_id' => self::BASE,
                'display_name' => 'Profit And Loss',
                'handle' => self::BASE,
            ]);

            $connection->connect([
                'refresh_token' => 'pat_secret_value',
                'api_token' => 'pat_secret_value',
                'base_id' => self::BASE,
            ]);

            $connection->save();

            return $connection;
        });
    }

    /**
     * One complete week followed by an incomplete one.
     *
     * The incomplete week is LATER on purpose: it is what makes
     * "latest complete" distinguishable from "last in the sheet". With the
     * order reversed both rules give the same answer and the test proves
     * nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function twoWeeks(): array
    {
        return [
            ['Week' => 'Week One', 'Income' => 'Total', 'Income Amount' => 1000],
            ['Week' => 'Week One', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => 'Week One', 'Parts' => 'Total', 'Parts Amount' => 400],
            ['Week' => 'Week One', 'Parts' => 'Pipe', 'Parts Amount' => 400],

            // Income has lines; Parts has a heading and no lines at all, which
            // is the "nobody has filled this in yet" case.
            ['Week' => 'Week Two', 'Income' => 'Cash', 'Income Amount' => 900],
            ['Week' => 'Week Two', 'Parts' => '', 'Parts Amount' => null],
        ];
    }

    /** @param  list<array<string, mixed>>|null  $records */
    private function fakeAirtable(?array $records = null): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/bases' => Http::response([
                'bases' => [['id' => self::BASE, 'name' => 'Profit And Loss']],
            ]),

            'api.airtable.com/v0/meta/bases/*' => Http::response([
                'tables' => [
                    ['id' => 'tbl1', 'name' => 'NJ'],
                    ['id' => 'tbl2', 'name' => 'CT'],
                ],
            ]),

            'api.airtable.com/*' => Http::response([
                'records' => array_map(
                    static fn (array $fields): array => ['fields' => $fields],
                    $records ?? [],
                ),
            ]),
        ]);
    }
}
