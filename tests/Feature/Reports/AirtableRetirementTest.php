<?php

namespace Tests\Feature\Reports;

use App\Models\AirtableRecord;
use App\Models\AuditEvent;
use App\Models\FinancialParityCheck;
use App\Models\FinancialRecord;
use App\Models\FinancialSource;
use App\Models\FinancialTable;
use App\Models\Notification;
use App\Support\Financials\AirtableParity;
use App\Support\Financials\SourceFigures;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Airtable retirement, base 1 (Disputes And Refunds) + daily parity (plan 2026-11-03). */
class AirtableRetirementTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const BASE = 'appDisputes';

    private array $t;

    protected function setUp(): void
    {
        parent::setUp();
        $this->t = $this->provisionTenant('Alpha');
        $this->inTenant($this->t['org'], function () {
            FinancialSource::create(['base_id' => self::BASE, 'table_id' => 'tblRefunds', 'role' => 'refunds', 'franchise_id' => $this->t['franchise']->id,
                'field_map' => ['amount' => 'Amount', 'date' => 'Date', 'technician' => 'Technician']]);
            $this->row('rec1', ['Amount' => 10.25, 'Date' => '2026-10-02', 'Technician' => 'Ann']);
            $this->row('rec2', ['Amount' => 5, 'Date' => '2026-10-20', 'Technician' => 'Bob']);
            $this->row('rec3', ['Amount' => 7.5, 'Date' => '2026-09-11', 'Technician' => 'Ann']);
        });
    }

    private function row(string $id, array $fields, string $table = 'tblRefunds'): void
    {
        AirtableRecord::query()->updateOrCreate(
            ['organization_id' => $this->t['org']->id, 'base_id' => self::BASE, 'table_id' => $table, 'record_id' => $id],
            ['table_name' => 'Refunds', 'fields' => $fields, 'fields_hash' => md5(json_encode($fields)), 'fetched_at' => now()],
        );
    }

    private function in(callable $fn): mixed
    {
        return $this->inTenant($this->t['org'], $fn);
    }

    private function backfill(bool $dry = false): void
    {
        $this->artisan('ezbizi:airtable-backfill', ['organization' => $this->t['org']->slug, 'base' => self::BASE, '--dry-run' => $dry])->assertSuccessful();
    }

    private function parity(?string $day = null): FinancialParityCheck
    {
        $this->artisan('ezbizi:airtable-parity', array_filter(['--day' => $day]))->assertSuccessful();

        return $this->in(fn () => FinancialParityCheck::query()->where('day', $day ?? now()->toDateString())->sole());
    }

    /** Set one EzBizi cell (by Airtable column) of the record that came from $recordId. */
    private function setEzbizi(string $recordId, string $column, mixed $value): void
    {
        $this->in(function () use ($recordId, $column, $value) {
            $table = FinancialTable::query()->where('source_table_id', 'tblRefunds')->sole();
            $field = $table->fields()->where('source_name', $column)->sole();
            $r = FinancialRecord::query()->where('source_record_id', $recordId)->sole();
            $r->update(['values' => array_replace($r->values, [$field->id => $value])]);
        });
    }

    public function test_backfill_dry_run_writes_nothing_and_a_rerun_changes_nothing(): void
    {
        $this->backfill(dry: true);
        $this->in(fn () => $this->assertSame(0, FinancialRecord::query()->count()));

        $this->backfill();
        $this->in(fn () => $this->assertSame(3, FinancialRecord::query()->count()));

        $this->artisan('ezbizi:airtable-backfill', ['organization' => $this->t['org']->slug, 'base' => self::BASE])
            ->expectsOutputToContain('"added":0,"changed":0,"removed":0')->assertSuccessful();
        $this->in(fn () => $this->assertSame(3, FinancialRecord::query()->count()));
        $this->in(fn () => $this->assertTrue(AuditEvent::query()->where('action', 'financials.backfill')->exists()));
    }

    public function test_backfill_leaves_a_cut_over_table_alone(): void
    {
        $this->backfill();
        $this->setEzbizi('rec1', 'Amount', 99);
        $this->in(fn () => FinancialTable::query()->update(['cut_over_at' => now()]));
        $this->backfill();
        $this->in(fn () => $this->assertContains(99, FinancialSource::query()->sole()->rows()->pluck('Amount')->all()));
    }

    public function test_parity_is_red_without_an_ezbizi_table_and_green_after_backfill(): void
    {
        $this->assertFalse($this->parity()->green);
        $this->backfill();
        $check = $this->parity();
        $this->assertTrue($check->green, json_encode($check->diffs));
        $this->assertSame([3, 3], [$check->airtable_rows, $check->ezbizi_rows]);
    }

    public function test_parity_red_on_a_count_mismatch(): void
    {
        $this->backfill();
        $this->in(fn () => $this->row('rec4', ['Amount' => 1, 'Date' => '2026-10-21', 'Technician' => 'Cy']));
        $check = $this->parity();
        $this->assertFalse($check->green);
        $diff = collect($check->diffs)->first();
        $this->assertSame(['rec4'], $diff['rows']['missing']);
    }

    public function test_parity_red_on_a_cent_mismatch_and_green_within_tolerance(): void
    {
        $this->backfill();
        $this->setEzbizi('rec1', 'Amount', 10.254); // 0.4 of a cent: rounding, within $0.01
        $this->assertTrue($this->parity('2026-11-01')->green);

        $this->setEzbizi('rec1', 'Amount', 10.27); // two cents
        $check = $this->parity('2026-11-02');
        $this->assertFalse($check->green);
        $diff = collect($check->diffs)->first();
        $this->assertEquals([['month' => '2026-10', 'line' => 'amount', 'airtable_cents' => 1525, 'ezbizi_cents' => 1527]], $diff['months']);
        $this->assertSame(['2026-10'], $diff['report']['refunds_differ_in']);
    }

    public function test_streak_counts_green_days_in_a_row_and_a_gap_or_red_day_breaks_it(): void
    {
        $this->in(function () {
            foreach (['2026-10-28' => true, '2026-10-29' => false, '2026-10-30' => true, '2026-10-31' => true, '2026-11-01' => true] as $day => $green) {
                FinancialParityCheck::create(['source_base_id' => self::BASE, 'source_table_id' => 'tblRefunds', 'day' => $day, 'green' => $green]);
            }
            // A second table red on 10-30 makes that whole day red.
            FinancialParityCheck::create(['source_base_id' => self::BASE, 'source_table_id' => 'tblDisputes', 'day' => '2026-10-30', 'green' => false]);

            $this->assertSame(2, AirtableParity::streak(self::BASE, '2026-11-01'));
            $this->assertSame(2, AirtableParity::streak(self::BASE, '2026-11-02')); // today not run yet: counts from yesterday
            $this->assertSame(0, AirtableParity::streak(self::BASE, '2026-11-03')); // a missed day
            $this->assertFalse(AirtableParity::ready(self::BASE));

            for ($d = 1; $d <= 14; $d++) {
                FinancialParityCheck::query()->updateOrCreate(['source_base_id' => self::BASE, 'source_table_id' => 'tblRefunds', 'day' => now()->subDays($d)->toDateString()], ['green' => true]);
                FinancialParityCheck::query()->where('source_table_id', 'tblDisputes')->delete();
            }
            $this->assertTrue(AirtableParity::ready(self::BASE));
        });
    }

    public function test_base_cutover_needs_the_streak_then_ezbizi_is_read_and_undo_goes_back(): void
    {
        $this->backfill();
        $args = ['organization' => $this->t['org']->slug, 'base' => self::BASE];
        $this->artisan('ezbizi:airtable-cutover', $args)->expectsOutputToContain('needed')->assertFailed();

        $this->artisan('ezbizi:airtable-cutover', $args + ['--force' => true])->assertSuccessful();
        $this->setEzbizi('rec2', 'Amount', 50);
        $this->artisan('ezbizi:financials-airtable-refresh')->assertSuccessful(); // must not overwrite EzBizi
        $this->in(function () {
            $this->assertTrue(FinancialTable::query()->sole()->isCutOver());
            $cents = collect(SourceFigures::refunds('2026-10-01', '2026-10-31')[0]['items'])->where('source', 'airtable')->sum('cents');
            $this->assertSame(1025 + 5000, $cents);
            $this->assertTrue(AuditEvent::query()->where('action', 'financials.cut_over')->exists());
        });

        // Drift after cut-over is red and tells whoever edits the books.
        $this->assertFalse($this->parity()->green);
        $this->in(fn () => $this->assertTrue(Notification::query()->where('title', 'like', 'Airtable and EzBizi disagree%')->exists()));

        $this->artisan('ezbizi:airtable-cutover', $args + ['--undo' => true])->expectsOutputToContain('airtable-rollback')->assertSuccessful();
        $this->in(function () {
            $this->assertFalse(FinancialTable::query()->sole()->isCutOver());
            $this->assertSame(500, collect(SourceFigures::refunds('2026-10-01', '2026-10-31')[0]['items'])->firstWhere('technician', 'Bob')['cents']);
            $this->assertTrue(AuditEvent::query()->where('action', 'financials.cut_over_undone')->exists());
        });
    }

    public function test_config_flag_switches_the_whole_base_and_api_refuses_a_per_table_undo(): void
    {
        $this->backfill();
        config(['airtable.cutover_bases' => [self::BASE]]);
        $table = $this->in(fn () => FinancialTable::query()->sole());
        $this->assertTrue($table->isCutOver());
        $this->assertFalse($table->isMirror());
        $this->in(fn () => $this->assertSame(0, FinancialTable::query()->stillMirrored()->count()));

        $this->signedInAs($this->t['admin'])->postJson("/api/v1/financials/tables/{$table->id}/cut-over", ['undo' => true])->assertStatus(422);

        config(['airtable.cutover_bases' => []]);
        $this->assertFalse($table->fresh()->isCutOver());
    }

    public function test_parity_is_per_tenant_and_the_api_shows_the_streak(): void
    {
        $beta = $this->provisionTenant('Beta');
        $this->backfill();
        $this->parity();

        $this->inTenant($beta['org'], fn () => $this->assertSame(0, FinancialParityCheck::query()->count()));
        $this->signedInAs($beta['admin'])->getJson('/api/v1/financials/parity')->assertOk()->assertExactJson(['data' => []]);

        $this->signedInAs($this->t['admin'])->getJson('/api/v1/financials/parity')->assertOk()
            ->assertJsonPath('data.0.base_id', self::BASE)->assertJsonPath('data.0.streak', 1)
            ->assertJsonPath('data.0.ready', false)->assertJsonPath('data.0.tables.0.green', true);
    }

    public function test_reading_from_a_store_resets_even_when_the_callback_throws(): void
    {
        $this->backfill();
        $this->setEzbizi('rec1', 'Amount', 42);
        $this->in(function () {
            $source = FinancialSource::query()->sole();
            $this->assertContains(42, FinancialSource::readingFrom('ezbizi', fn () => $source->rows()->pluck('Amount')->all()));

            try {
                FinancialSource::readingFrom('ezbizi', fn () => throw new \RuntimeException('boom'));
                $this->fail('the throw should come through');
            } catch (\RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }
            $this->assertNull(FinancialSource::forcedStore());
            $this->assertNotContains(42, $source->rows()->pluck('Amount')->all()); // live store again (Airtable, not cut over)
        });
    }

    /** Prod 11-04: an empty Airtable table (never copied, never mirrored) read red as "run the backfill". */
    public function test_an_empty_airtable_table_with_no_mirror_is_green_until_a_row_appears(): void
    {
        $this->backfill();
        $this->in(fn () => FinancialSource::create(['base_id' => self::BASE, 'table_id' => 'tblEmpty', 'role' => 'check_returns',
            'franchise_id' => $this->t['franchise']->id, 'field_map' => ['amount' => 'Amount', 'date' => 'Date']]));

        $this->artisan('ezbizi:airtable-parity')->assertSuccessful();
        $checks = $this->in(fn () => FinancialParityCheck::query()->get()->keyBy('source_table_id'));
        $this->assertTrue($checks['tblEmpty']->green, json_encode($checks['tblEmpty']->diffs));
        $this->assertTrue($checks['tblRefunds']->green);

        $this->in(fn () => $this->row('recE', ['Amount' => 3, 'Date' => '2026-10-05'], 'tblEmpty'));
        $this->artisan('ezbizi:airtable-parity')->assertSuccessful();
        $this->in(fn () => $this->assertArrayHasKey('missing_table', FinancialParityCheck::query()->where('source_table_id', 'tblEmpty')->sole()->diffs));
    }
}
