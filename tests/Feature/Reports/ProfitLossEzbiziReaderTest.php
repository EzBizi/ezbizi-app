<?php

namespace Tests\Feature\Reports;

use App\Models\AirtableRecord;
use App\Models\FinancialSource;
use App\Models\FinancialTable;
use App\Models\SocialConnection;
use App\Support\Reports\Texts\PeriodReport;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The P&L base cut over to EzBizi (plan 2026-11-03, section 6): the screen
 * reads EzBizi's Financials rows, and gives exactly what live Airtable gave
 * for the same rows, week by week, an incomplete week included.
 */
class ProfitLossEzbiziReaderTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BASE = 'appWhbwht0xgB5Yut';

    private const WEEKS = ['26 Oct - 1 Nov', '2 Nov - 8 Nov', '9 Nov - 15 Nov'];

    private array $t;

    private SocialConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->connection = $this->connect();

        // The Airtable snapshot, then EzBizi's copy of it (the real backfill).
        $this->inTenant($this->t['org'], function () {
            foreach ($this->sheet() as $i => $fields) {
                AirtableRecord::query()->create([
                    'organization_id' => $this->t['org']->id, 'base_id' => self::BASE, 'table_id' => 'tblNJ', 'record_id' => 'rec'.$i,
                    'table_name' => 'NJ', 'fields' => $fields, 'fields_hash' => md5(json_encode($fields)), 'fetched_at' => now(),
                ]);
            }
            FinancialSource::create(['base_id' => self::BASE, 'table_id' => 'tblNJ', 'role' => 'pnl', 'franchise_id' => $this->t['franchise']->id, 'field_map' => []]);
        });
        $this->artisan('ezbizi:airtable-backfill', ['organization' => $this->t['org']->slug, 'base' => self::BASE])->assertSuccessful();
    }

    public function test_the_cut_over_screen_gives_what_live_airtable_gave_for_every_week(): void
    {
        $this->fakeAirtable($this->sheet());
        $airtable = $this->screen();

        $this->cutOver();
        $sent = count(Http::recorded());
        $ezbizi = $this->screen();

        // Cut over, Airtable is not asked at all (fakes stack, so a second fake would prove nothing).
        $this->assertCount($sent, Http::recorded());

        $this->assertSame(self::WEEKS, $ezbizi['']['weeks']);
        $this->assertEquals($airtable, $ezbizi);

        // The distinguishing cases survived: the complete week has figures and
        // the sheet's disagreement; the unfinished week has nulls, not zeros.
        $this->assertSame('2 Nov - 8 Nov', $ezbizi['']['week']['week']);
        $this->assertEquals(1500, $ezbizi['2 Nov - 8 Nov']['week']['net']);
        $this->assertCount(1, $ezbizi['26 Oct - 1 Nov']['week']['disagreements']);
        $this->assertFalse($ezbizi['9 Nov - 15 Nov']['week']['complete']);
        $this->assertNull($ezbizi['9 Nov - 15 Nov']['week']['income']);
        $this->assertNull($ezbizi['9 Nov - 15 Nov']['week']['net']);
    }

    public function test_before_cut_over_the_screen_still_reads_airtable(): void
    {
        $this->fakeAirtable([['Week' => '2 Nov - 8 Nov', 'Income' => 'Cash', 'Income Amount' => 1, 'Parts' => 'Pipe', 'Parts Amount' => 1]]);

        $week = $this->signedInAs($this->accountant())
            ->getJson('/api/v1/reports/profit-loss?connection='.$this->connection->id.'&table=NJ')->assertOk()->json('week');

        $this->assertEquals(0, $week['net']); // Airtable's one row, not EzBizi's copy
    }

    public function test_a_cut_over_base_lists_its_tables_without_asking_airtable(): void
    {
        $this->cutOver();
        Http::fake();

        $sources = $this->signedInAs($this->accountant())->getJson('/api/v1/reports/profit-loss/sources')->assertOk()->json('sources');

        $this->assertSame(['NJ'], $sources[0]['tables']);
        $this->assertNull($sources[0]['unavailable']);
        Http::assertNothingSent();
    }

    /** The report's P&L (weeks, missing, income, costs, net, why, monthly) is the same from either store. */
    public function test_the_period_report_pnl_is_the_same_from_either_store(): void
    {
        $report = app(PeriodReport::class);
        $read = fn (string $store, string $from, string $to) => $this->inTenant($this->t['org'],
            fn () => FinancialSource::readingFrom($store, fn () => $report->profitAndLoss($this->t['franchise'], $from, $to)));

        foreach ([['2026-10-26', '2026-11-01'], ['2026-11-09', '2026-11-15'], ['2026-10-01', '2026-11-30']] as [$from, $to]) {
            $this->assertEquals($read('airtable', $from, $to), $read('ezbizi', $from, $to), "{$from}..{$to}");
        }

        // A month with a missing and an incomplete week carries no total.
        $month = $read('ezbizi', '2026-10-01', '2026-11-30');
        $this->assertNotSame([], $month['missing']);
        $this->assertNull($month['net']);
        $this->assertNull($month['income']);
    }

    private function screen(): array
    {
        $out = [];
        foreach (['', ...self::WEEKS] as $week) {
            $out[$week] = $this->signedInAs($this->accountant())
                ->getJson('/api/v1/reports/profit-loss?connection='.$this->connection->id.'&table=NJ'.($week === '' ? '' : '&week='.urlencode($week)))
                ->assertOk()->json();
        }

        return $out;
    }

    private function cutOver(): void
    {
        $this->inTenant($this->t['org'], fn () => FinancialTable::query()->where('source_base_id', self::BASE)->update(['cut_over_at' => now()]));
    }

    /**
     * Three weeks: one whose Parts lines (400) disagree with the sheet's Total
     * (250), one clean, and one with Parts not filled in yet (Airtable sends no key for an empty cell).
     *
     * @return list<array<string, mixed>>
     */
    private function sheet(): array
    {
        return [
            ['Week' => '26 Oct - 1 Nov', 'Income' => 'Total Balance', 'Income Amount' => 600, 'Parts' => 'Total', 'Parts Amount' => 250],
            ['Week' => '26 Oct - 1 Nov', 'Income' => 'Total', 'Income Amount' => 1000, 'Parts' => 'Pipe', 'Parts Amount' => 400],
            ['Week' => '26 Oct - 1 Nov', 'Income' => 'Cash', 'Income Amount' => 1000],
            ['Week' => '2 Nov - 8 Nov', 'Income' => 'Zelle', 'Income Amount' => 2000, 'Parts' => 'Valve', 'Parts Amount' => 300.25],
            ['Week' => '2 Nov - 8 Nov', 'Income' => 'Total', 'Income Amount' => 2000, 'Parts' => 'Pipe', 'Parts Amount' => 199.75],
            ['Week' => '9 Nov - 15 Nov', 'Income' => 'Cash', 'Income Amount' => 900],
        ];
    }

    private $accountant = null;

    private function accountant()
    {
        return $this->accountant ??= $this->userWithRole($this->t, 'Accountant', ['profit_loss.view']);
    }

    private function connect(): SocialConnection
    {
        return $this->inTenant($this->t['org'], function () {
            $connection = new SocialConnection;
            $connection->forceFill([
                'organization_id' => $this->t['org']->id,
                'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => (string) $this->t['org']->id,
                'provider' => SocialProvider::Airtable->value,
                'remote_id' => self::BASE,
                'display_name' => 'Profit And Loss',
                'handle' => self::BASE,
            ]);
            $connection->connect(['refresh_token' => 'pat_x', 'api_token' => 'pat_x', 'base_id' => self::BASE]);
            $connection->save();

            return $connection;
        });
    }

    /** @param  list<array<string, mixed>>  $records */
    private function fakeAirtable(array $records): void
    {
        Http::fake([
            'api.airtable.com/v0/meta/bases/*' => Http::response(['tables' => [['id' => 'tblNJ', 'name' => 'NJ']]]),
            'api.airtable.com/*' => Http::response(['records' => array_map(fn (array $f) => ['fields' => $f], $records)]),
        ]);
    }
}
