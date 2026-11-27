<?php

namespace App\Support\Financials\QuickBooks;

use App\Models\FinancialExpense;
use App\Models\FinancialMarketMap;
use App\Models\Franchise;
use App\Models\SocialConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * QuickBooks Online → financial_expenses (Shoval via Jose's board, 2026-11-20).
 *
 * Read: the expense LINES of Purchases (checks, card and cash expenses),
 * Bills and Journal Entries whose account is an expense account (Expense,
 * Other Expense, Cost of Goods Sold), with the line's Class, the
 * transaction's Location (QuickBooks' "Department"), and the vendor.
 * Item-based lines (inventory items on a bill) are not read: they name an
 * item, not an expense account.
 *
 * Idempotent: a transaction's lines are replaced whole, keyed by realm, type,
 * transaction id and line id; reading the same window twice changes nothing.
 * Incremental runs read what changed since the last run
 * (MetaData.LastUpdatedTime) and take deletions from Change Data Capture;
 * a full run (on connect, nightly) reads FULL_DAYS back by date and drops
 * lines whose transaction is gone.
 *
 * Every Class and Location seen becomes a FinancialMarketMap, matched to a
 * branch by name until a person chooses (MarketMatch).
 */
final class QuickBooksSync
{
    public const FULL_DAYS = 400;

    public const ENTITIES = ['Purchase', 'Bill', 'JournalEntry'];

    public const EXPENSE_TYPES = ['Expense', 'Other Expense', 'Cost of Goods Sold'];

    public function __construct(private readonly QuickBooksClient $client) {}

    /** @return array{lines: int, transactions: int, deleted: int} what this run wrote */
    public function run(SocialConnection $c, bool $full = false): array
    {
        $started = now();
        $realm = (string) $c->remote_id;
        $state = (array) (((array) ($c->setup ?? []))['quickbooks'] ?? []);
        $cursor = isset($state['synced_at']) ? Carbon::parse($state['synced_at']) : null;
        $full = $full || $cursor === null;
        $since = now()->subDays(self::FULL_DAYS)->toDateString();

        $accounts = [];
        foreach ($this->client->all($c, 'Account') as $a) {
            $accounts[(string) $a['Id']] = ['name' => (string) ($a['FullyQualifiedName'] ?? $a['Name'] ?? ''), 'type' => (string) ($a['AccountType'] ?? '')];
        }

        $this->maps($c, 'class', $this->client->all($c, 'Class'));
        $this->maps($c, 'department', $this->client->all($c, 'Department'));

        $where = $full
            ? "TxnDate >= '{$since}'"
            // Ten minutes of overlap: QuickBooks stamps a change before it is readable.
            : "MetaData.LastUpdatedTime >= '".$cursor->copy()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z')."'";

        $out = ['lines' => 0, 'transactions' => 0, 'deleted' => 0];
        $seen = [];
        foreach (self::ENTITIES as $entity) {
            foreach ($this->client->all($c, $entity, $where) as $txn) {
                $lines = self::lines($entity, $txn, $accounts);
                $out['lines'] += $this->replace($c, $realm, $entity, (string) $txn['Id'], $lines);
                $out['transactions']++;
                $seen[$entity][] = (string) $txn['Id'];
            }
        }

        if ($full) {
            // Gone from QuickBooks: in the window, not read back.
            foreach (self::ENTITIES as $entity) {
                $out['deleted'] += FinancialExpense::query()->where('realm_id', $realm)->where('txn_type', $entity)
                    ->where('txn_date', '>=', $since)->whereNotIn('txn_id', $seen[$entity] ?? [])->delete();
            }
        } elseif ($cursor->greaterThan(now()->subDays(29))) {
            $cdc = $this->client->read($c, 'cdc', [
                'entities' => implode(',', self::ENTITIES),
                'changedSince' => $cursor->copy()->subMinutes(10)->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);
            foreach ((array) ($cdc['CDCResponse'][0]['QueryResponse'] ?? []) as $block) {
                foreach (self::ENTITIES as $entity) {
                    foreach ((array) ($block[$entity] ?? []) as $txn) {
                        if (($txn['status'] ?? null) === 'Deleted') {
                            $out['deleted'] += FinancialExpense::query()->where('realm_id', $realm)->where('txn_type', $entity)
                                ->where('txn_id', (string) $txn['Id'])->delete();
                        }
                    }
                }
            }
        }

        $setup = (array) ($c->setup ?? []);
        $setup['quickbooks'] = ['synced_at' => $started->toIso8601String(), 'last' => $out + ['full' => $full]];
        $c->forceFill(['setup' => $setup, 'last_reached_at' => now()])->save();

        return $out;
    }

    /**
     * The expense lines of one transaction, as rows to store.
     *
     * @param  array<string, array{name: string, type: string}>  $accounts
     * @return list<array<string, mixed>>
     */
    public static function lines(string $entity, array $txn, array $accounts): array
    {
        $headVendor = $txn['VendorRef'] ?? (($txn['EntityRef']['type'] ?? 'Vendor') === 'Vendor' ? ($txn['EntityRef'] ?? null) : null);
        $headDept = $txn['DepartmentRef'] ?? null;
        // A Purchase marked Credit is a refund to the card: money back.
        $sign = $entity === 'Purchase' && ($txn['Credit'] ?? false) === true ? -1 : 1;
        $rows = [];

        foreach ((array) ($txn['Line'] ?? []) as $i => $line) {
            $type = (string) ($line['DetailType'] ?? '');
            if ($type === 'AccountBasedExpenseLineDetail') {
                $d = (array) $line['AccountBasedExpenseLineDetail'];
                $lineSign = $sign;
                $vendor = $headVendor;
                $dept = $headDept;
            } elseif ($type === 'JournalEntryLineDetail') {
                $d = (array) $line['JournalEntryLineDetail'];
                $lineSign = ($d['PostingType'] ?? 'Debit') === 'Credit' ? -1 : 1;
                $entityRef = $d['Entity']['EntityRef'] ?? null;
                $vendor = ($d['Entity']['Type'] ?? null) === 'Vendor' ? $entityRef : null;
                $dept = $d['DepartmentRef'] ?? $headDept;
            } else {
                continue;
            }

            $account = (string) ($d['AccountRef']['value'] ?? '');
            $known = $accounts[$account] ?? null;
            if ($known === null || ! in_array($known['type'], self::EXPENSE_TYPES, true)) {
                continue;
            }

            $rows[] = [
                'line_id' => (string) ($line['Id'] ?? 'i'.$i),
                'txn_date' => (string) $txn['TxnDate'],
                'amount_cents' => $lineSign * (int) round(((float) ($line['Amount'] ?? 0)) * 100),
                'account_id' => $account,
                'account_name' => mb_substr($known['name'] ?: (string) ($d['AccountRef']['name'] ?? ''), 0, 191),
                'account_type' => $known['type'],
                'vendor_id' => isset($vendor['value']) ? (string) $vendor['value'] : null,
                'vendor_name' => isset($vendor['name']) ? mb_substr((string) $vendor['name'], 0, 191) : null,
                'class_id' => isset($d['ClassRef']['value']) ? (string) $d['ClassRef']['value'] : null,
                'class_name' => isset($d['ClassRef']['name']) ? mb_substr((string) $d['ClassRef']['name'], 0, 191) : null,
                'department_id' => isset($dept['value']) ? (string) $dept['value'] : null,
                'department_name' => isset($dept['name']) ? mb_substr((string) $dept['name'], 0, 191) : null,
                'memo' => isset($line['Description']) ? mb_substr((string) $line['Description'], 0, 500) : null,
            ];
        }

        return $rows;
    }

    /** One transaction's lines, replaced whole. @return int lines written */
    private function replace(SocialConnection $c, string $realm, string $entity, string $txnId, array $lines): int
    {
        return DB::transaction(function () use ($c, $realm, $entity, $txnId, $lines) {
            FinancialExpense::query()->where('realm_id', $realm)->where('txn_type', $entity)->where('txn_id', $txnId)
                ->whereNotIn('line_id', array_column($lines, 'line_id'))->delete();
            foreach ($lines as $line) {
                FinancialExpense::query()->updateOrCreate(
                    ['realm_id' => $realm, 'txn_type' => $entity, 'txn_id' => $txnId, 'line_id' => $line['line_id']],
                    $line + ['social_connection_id' => $c->getKey(), 'source' => 'quickbooks'],
                );
            }

            return count($lines);
        });
    }

    /** Every Class or Location becomes a map row; new ones (and unchosen ones) match a branch by name. */
    private function maps(SocialConnection $c, string $dimension, array $rows): void
    {
        $franchises = Franchise::query()->get(['id', 'name']);
        foreach ($rows as $row) {
            $name = (string) ($row['FullyQualifiedName'] ?? $row['Name'] ?? '');
            $map = FinancialMarketMap::query()->firstOrNew([
                'realm_id' => (string) $c->remote_id, 'dimension' => $dimension, 'remote_id' => (string) $row['Id'],
            ]);
            $map->name = mb_substr($name, 0, 191);
            if (! $map->exists || $map->mapped_by === 'name') {
                $map->franchise_id = MarketMatch::franchiseFor($name, $franchises);
                $map->mapped_by = 'name';
            }
            $map->save();
        }
    }
}
