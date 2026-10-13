<?php

namespace Tests\Feature\Reports;

use App\Models\Franchise;
use App\Models\WorkizJob;
use App\Models\WorkizRecord;
use App\Models\WorkizTeamMember;
use App\Support\Reports\Texts\BranchName;
use App\Support\Reports\Texts\SameDayReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The Rodrigo bot's nightly WhatsApp report, from EzBizi's Workiz copy. */
class SameDayReportTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-05 21:00', 'America/New_York'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_branch_day_reads_like_rodrigos(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];

        $this->inTenant($t['org'], function () use ($f) {
            // Booked today: two LS Ads, one with no source. One booked yesterday.
            $this->job($f, ['job_source' => 'LS Ads', 'service_area' => 'Hartford ', 'booked_at' => '2026-10-05 09:00:00']);
            $this->job($f, ['job_source' => 'LS Ads', 'service_area' => 'Hartford', 'booked_at' => '2026-10-05 10:00:00']);
            $this->job($f, ['job_source' => null, 'service_area' => 'New Haven', 'booked_at' => '2026-10-05 11:00:00']);
            $this->job($f, ['job_source' => 'GMB', 'service_area' => 'Hartford', 'booked_at' => '2026-10-04 11:00:00']);

            // Closed today: Ann alone for 300, Ann and Bo share 200, Bo cancels one.
            $this->job($f, ['status' => 'Done', 'last_status_update' => '2026-10-05 12:00:00', 'booked_at' => '2026-09-01',
                'payload' => ['Team' => [['id' => 'USR-ann', 'Name' => 'Ann']], 'JobTotalPrice' => 300]]);
            $this->job($f, ['status' => 'Done', 'last_status_update' => '2026-10-05 13:00:00', 'booked_at' => '2026-09-01',
                'payload' => ['Team' => [['id' => 'USR-ann', 'Name' => 'Ann'], ['id' => 'USR-bo', 'Name' => 'Bo']], 'JobTotalPrice' => 200]]);
            $this->job($f, ['status' => 'Canceled', 'last_status_update' => '2026-10-05 14:00:00', 'booked_at' => '2026-09-01',
                'payload' => ['Team' => [['id' => 'USR-bo', 'Name' => 'Bo']], 'JobTotalPrice' => 900]]);

            // Paid today: Ann 250, the office 1,000; yesterday's payment is not today's.
            WorkizTeamMember::query()->create(['franchise_id' => $f->id, 'remote_id' => 'USR-ann', 'name' => 'Ann', 'payload' => [], 'synced_at' => now()]);
            $this->payment($f, 'USR-ann', 250, '2026-10-05 15:00:00');
            $this->payment($f, 'USR-office', 1000, '2026-10-05 16:00:00');
            $this->payment($f, 'USR-ann', 77, '2026-10-04 16:00:00');
        });

        $this->assertSame(implode("\n", [
            '📊 *Alpha Co · Same-Day Report*',
            '📅 Mon, Oct 5, 2026',
            '_Current through 9:00 PM EDT_',
            '',
            '🔧 *1. Jobs created per ad source*',
            '• *LS Ads:* 2',
            '• *No source:* 1',
            '*Total: 3 jobs*',
            '',
            '📍 *2. Jobs created by service area*',
            '• *Hartford:* 2',
            '• *New Haven:* 1',
            '*Total: 3 jobs*',
            '',
            '⭐ *3. Google Reviews (mapped metro areas)*',
            '⚠️ *Unavailable:* no Google Business Profile locations are linked to this branch',
            '',
            '👷 *4. Field performance*',
            '• *Ann:* ✅ 2 done · ❌ 0 canceled · 💰 $400.00 sales · 💵 $250.00 deposits',
            '• *Bo:* ✅ 1 done · ❌ 1 canceled · 💰 $100.00 sales · 💵 $0.00 deposits',
            "• *Not in Workiz's API (USR-office):* $1,000.00 in payments received · no completed, canceled, or sales activity today",
            '*Total: ✅ 3 done · ❌ 1 canceled · 💰 $500.00 sales · 💵 $1,250.00 deposits*',
            '',
            '_Ask me about any location, technician, source, or job behind these numbers._',
        ]), SameDayReport::forFranchise($f, '2026-10-05'));
    }

    public function test_a_stale_copy_says_unavailable_rather_than_zero(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];

        $this->inTenant($t['org'], fn () => $this->job($f, [
            'job_source' => 'LS Ads', 'booked_at' => '2026-10-05 09:00:00', 'synced_at' => now()->subHours(2),
        ]));

        $text = SameDayReport::forFranchise($f, '2026-10-05');

        $this->assertStringContainsString("*1. Jobs created per ad source*\n⚠️ *Unavailable:* Workiz sources did not refresh", $text);
        $this->assertStringContainsString('⚠️ *Unavailable:* Workiz technician report did not refresh', $text);
        $this->assertStringContainsString('⚠️ *Unavailable:* Workiz Payments report did not refresh', $text);
        $this->assertStringNotContainsString('LS Ads', $text);
    }

    public function test_the_summary_adds_the_branches_up(): void
    {
        $t = $this->provisionTenant('Alpha');
        $f = $t['franchise'];

        $this->inTenant($t['org'], function () use ($f) {
            $this->job($f, ['job_source' => 'LS Ads', 'booked_at' => '2026-10-05 09:00:00']);
            $this->job($f, ['status' => 'Done', 'last_status_update' => '2026-10-05 12:00:00', 'booked_at' => '2026-09-01',
                'payload' => ['Team' => [['id' => 'USR-ann', 'Name' => 'Ann']], 'JobTotalPrice' => 300]]);
            $this->payment($f, 'USR-ann', 250, '2026-10-05 15:00:00');
        });

        $this->assertSame(implode("\n", [
            '📊 *Alpha · Same-Day Company Summary*',
            '📅 Mon, Oct 5, 2026',
            '_Current through 9:00 PM EDT_',
            '_1 verified company reports_',
            '',
            '🔧 *Jobs created per ad source*',
            '• *LS Ads:* 1',
            '*Total: 1 job*',
            '',
            '🏢 *Company summary*',
            '',
            '*Alpha Co*',
            '🔧 *1* jobs · ✅ 1 done · ❌ 0 canceled',
            '💰 *$300.00* sales · 💵 $250.00 deposits',
            '⭐ — new reviews',
            '',
            '*All companies*',
            '🔧 *1* jobs · ✅ 1 done · ❌ 0 canceled',
            '💰 *$300.00* sales · 💵 $250.00 deposits',
            '⭐ — new reviews',
        ]), SameDayReport::summary($t['org'], '2026-10-05'));
    }

    /** Jose, 2026-10-09: "they all say main or texas, u cant tell which company they belong to". */
    public function test_a_branch_is_named_by_its_company(): void
    {
        $t = $this->provisionTenant('Alpha');

        $this->assertSame('Alpha Co', BranchName::of($t['franchise']));

        $second = $this->inTenant($t['org'], fn () => Franchise::create([
            'organization_id' => $t['org']->id, 'company_id' => $t['company']->id, 'name' => 'Texas', 'is_active' => true,
        ]));

        $this->assertSame('Alpha Co · Texas', BranchName::of($second));
        $this->assertSame('Alpha Co · Alpha F1', BranchName::of($t['franchise']));
    }

    private function job($franchise, array $attributes): void
    {
        WorkizJob::query()->create($attributes + [
            'franchise_id' => $franchise->id,
            'remote_id' => 'JOB-'.bin2hex(random_bytes(4)),
            'payload' => [],
            'synced_at' => now(),
        ]);
    }

    private function payment($franchise, string $by, float $amount, string $at): void
    {
        WorkizRecord::query()->create([
            'franchise_id' => $franchise->id, 'type' => 'payment', 'remote_id' => 'PAY-'.bin2hex(random_bytes(4)),
            'user_remote_id' => $by, 'amount' => $amount, 'starts_at' => $at, 'payload' => [], 'synced_at' => now(),
        ]);
    }
}
