<?php

namespace Tests\Feature\Financials;

use App\Models\FinancialExpense;
use App\Models\FinancialMarketMap;
use App\Models\SocialConnection;
use App\Support\Financials\QuickBooks\MarketMatch;
use App\Support\Financials\QuickBooks\QuickBooksSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\FakesQuickBooks;
use Tests\TestCase;

/** QuickBooks → financial_expenses: which lines, idempotent, deletions, and the market matched by name. */
class QuickBooksSyncTest extends TestCase
{
    use BuildsTenants;
    use FakesQuickBooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['services.quickbooks.client_id' => 'id', 'services.quickbooks.client_secret' => 'secret']);
    }

    public function test_expense_lines_are_read_once_however_often_the_window_is_read(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeQuickBooks();
        $this->qboBooks();
        $day = now()->subDays(5)->toDateString();
        $this->qbo['Purchase'] = [
            // Two expense lines and one to a bank account (a transfer, not an expense).
            $this->qboPurchase('1', $day, [[100.10, '7', '100'], [20, '8'], [999, '35']], ['DepartmentRef' => ['value' => '5', 'name' => 'Tampa']]),
            // A card refund: money back.
            $this->qboPurchase('2', $day, [[15, '8']], ['Credit' => true]),
        ];
        $this->qbo['Bill'] = [['Id' => '3', 'TxnDate' => $day, 'VendorRef' => ['value' => '60', 'name' => 'Google'],
            'Line' => [['Id' => '1', 'Amount' => 300, 'DetailType' => 'AccountBasedExpenseLineDetail', 'AccountBasedExpenseLineDetail' => ['AccountRef' => ['value' => '7'], 'ClassRef' => ['value' => '101', 'name' => 'Orlando']]],
                // An inventory item on a bill names no expense account: not read.
                ['Id' => '2', 'Amount' => 50, 'DetailType' => 'ItemBasedExpenseLineDetail', 'ItemBasedExpenseLineDetail' => ['ItemRef' => ['value' => '3']]]]]];
        $this->qbo['JournalEntry'] = [['Id' => '4', 'TxnDate' => $day, 'Line' => [
            ['Id' => '0', 'Amount' => 40, 'DetailType' => 'JournalEntryLineDetail', 'JournalEntryLineDetail' => ['PostingType' => 'Credit', 'AccountRef' => ['value' => '7'], 'ClassRef' => ['value' => '100']]],
            ['Id' => '1', 'Amount' => 40, 'DetailType' => 'JournalEntryLineDetail', 'JournalEntryLineDetail' => ['PostingType' => 'Debit', 'AccountRef' => ['value' => '35']]],
        ]]];
        $c = $this->connection($t);

        $first = $this->sync($t, $c, full: true);
        $snapshot = $this->rows($t);
        $second = $this->sync($t, $c, full: true);

        $this->assertSame(['lines' => 5, 'transactions' => 4, 'deleted' => 0], $first);
        $this->assertSame($first, $second);
        $this->assertSame($snapshot, $this->rows($t), 'a second read of the same window changes nothing');
        $this->assertSame([
            'Bill|3|1' => 30000, 'JournalEntry|4|0' => -4000, 'Purchase|1|1' => 10010, 'Purchase|1|2' => 2000, 'Purchase|2|1' => -1500,
        ], array_map(fn ($r) => $r['amount_cents'], $snapshot));
        $this->assertSame('Tampa', $snapshot['Purchase|1|1']['department_name'], 'the location is the transaction\'s, on every line');
        $this->assertSame('Shell', $snapshot['Purchase|1|1']['vendor_name']);
        $this->assertSame('Auto:Fuel', $snapshot['Purchase|1|2']['account_name'], 'the category is the account, as QuickBooks names it');
    }

    public function test_a_transaction_deleted_in_quickbooks_leaves_financials(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeQuickBooks();
        $this->qboBooks();
        $day = now()->subDays(5)->toDateString();
        $this->qbo['Purchase'] = [$this->qboPurchase('1', $day, [[10, '7']]), $this->qboPurchase('2', $day, [[20, '7']])];
        $c = $this->connection($t);
        $this->sync($t, $c, full: true);

        // Incremental: CDC says one was deleted.
        $this->qbo['Purchase'] = [];
        $this->qbo['cdc'] = [['Purchase' => [['Id' => '2', 'status' => 'Deleted']]]];
        $out = $this->sync($t, $c->fresh());
        $this->assertSame(1, $out['deleted']);
        $this->assertSame(['Purchase|1|1'], array_keys($this->rows($t)));
        $this->assertStringContainsString('MetaData.LastUpdatedTime >=', end($this->qboQueries));

        // Full: whatever is no longer there goes.
        $this->assertSame(1, $this->sync($t, $c->fresh(), full: true)['deleted']);
        $this->assertSame([], $this->rows($t));
    }

    public function test_classes_and_locations_match_a_branch_by_name_until_a_person_chooses(): void
    {
        $t = $this->provisionTenant('Alpha');
        $miami = $this->extraFranchise($t, 'Miami');
        $this->extraFranchise($t, 'Orlando North');
        $this->extraFranchise($t, 'Orlando South');
        $tampa = $this->extraFranchise($t, 'Tampa Bay');
        $this->fakeQuickBooks();
        $this->qboBooks();
        $c = $this->connection($t);
        $this->sync($t, $c, full: true);

        $maps = $this->inTenant($t['org'], fn () => FinancialMarketMap::query()->get()->keyBy(fn ($m) => $m->dimension.':'.$m->name));
        $this->assertSame($miami->id, $maps['class:Markets:Miami']->franchise_id, 'a sub-class matches by its own name');
        $this->assertNull($maps['class:Orlando']->franchise_id, 'two Orlando branches: nobody guesses');
        $this->assertSame($tampa->id, $maps['department:Tampa']->franchise_id, 'one branch containing the name');

        // A person says Miami is not a market; the next sync keeps that.
        $this->signedInAs($t['admin'])->patchJson('/api/v1/financials/markets/maps/'.$maps['class:Markets:Miami']->id, ['franchise_id' => null])
            ->assertOk()->assertJsonPath('data.mapped_by', 'none');
        $this->sync($t, $c->fresh(), full: true);
        $this->assertNull($this->inTenant($t['org'], fn () => $maps['class:Markets:Miami']->fresh()->franchise_id));
    }

    public function test_the_name_match_is_exact_first_and_refuses_two_candidates(): void
    {
        $f = collect([(object) ['id' => 'a', 'name' => 'Miami'], (object) ['id' => 'b', 'name' => 'Miami Beach'], (object) ['id' => 'c', 'name' => 'Fort Lauderdale']]);
        $this->assertSame('a', MarketMatch::franchiseFor('MIAMI', $f));
        $this->assertSame('c', MarketMatch::franchiseFor('Region:Fort-Lauderdale', $f));
        $this->assertSame('b', MarketMatch::franchiseFor('Beach', $f), 'the one branch containing it');
        $this->assertNull(MarketMatch::franchiseFor('Overhead', $f));
        $this->assertNull(MarketMatch::franchiseFor('', $f));
    }

    private function sync(array $t, SocialConnection $c, bool $full = false): array
    {
        return $this->inTenant($t['org'], fn () => app(QuickBooksSync::class)->run($c, $full));
    }

    /** @return array<string, array<string, mixed>> type|txn|line => row */
    private function rows(array $t): array
    {
        return $this->inTenant($t['org'], fn () => FinancialExpense::query()->orderBy('txn_type')->orderBy('txn_id')->orderBy('line_id')->get()
            ->mapWithKeys(fn ($e) => [$e->txn_type.'|'.$e->txn_id.'|'.$e->line_id => $e->only(['amount_cents', 'account_name', 'vendor_name', 'class_id', 'department_name', 'txn_date'])])
            ->map(fn ($r) => ['txn_date' => (string) $r['txn_date']] + $r)->all());
    }

    private function connection(array $t): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => 'quickbooks', 'remote_id' => '4620816365', 'display_name' => 'Alpha Books LLC',
            ]);
            $c->connect(['access_token' => 'live', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->toIso8601String()]);
            $c->save();

            return $c;
        });
    }
}
