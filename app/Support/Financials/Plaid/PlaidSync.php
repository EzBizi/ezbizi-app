<?php

namespace App\Support\Financials\Plaid;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SocialConnection;
use Illuminate\Support\Carbon;

/**
 * Plaid → bank_accounts and bank_transactions, for one bank login.
 *
 * /transactions/sync from the cursor stored on the connection
 * (setup.plaid.cursor): pages of added, modified and removed until has_more
 * is false, then the new cursor is stored. Writes are upserts keyed by
 * Plaid's ids and removals are deletes, so a page applied twice changes
 * nothing; that is what makes Plaid's rule safe to follow: on
 * TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION, start again from the cursor
 * the run began with.
 *
 * Every page carries the accounts with their balances; those are stored too,
 * so a sync is also the balance refresh (no extra Balance call is billed).
 *
 * The first sync of a new bank can come back empty while Plaid is still
 * reading history (NOT_READY); Plaid's SYNC_UPDATES_AVAILABLE webhook brings
 * the next run (PlaidWebhookController), and the schedule is the backstop.
 */
final class PlaidSync
{
    public const PAGE = 500;

    private const RESTARTS = 3;

    public function __construct(private readonly PlaidClient $client, private readonly BankMatcher $matcher) {}

    /** @return array{added: int, modified: int, removed: int, accounts: int} */
    public function run(SocialConnection $c): array
    {
        $state = (array) (((array) ($c->setup ?? []))['plaid'] ?? []);
        $start = isset($state['cursor']) && is_string($state['cursor']) ? $state['cursor'] : null;

        for ($attempt = 0; ; $attempt++) {
            try {
                [$out, $cursor, $status] = $this->pages($c, $start);
                break;
            } catch (PlaidError $e) {
                if ($e->plaidCode !== 'TRANSACTIONS_SYNC_MUTATION_DURING_PAGINATION' || $attempt >= self::RESTARTS) {
                    throw $e;
                }
            }
        }

        $setup = (array) ($c->setup ?? []);
        $setup['plaid'] = [
            'cursor' => $cursor,
            'synced_at' => now()->toIso8601String(),
            'update_status' => $status,
            'last' => $out,
        ];
        $c->forceFill(['setup' => $setup, 'last_reached_at' => now()])->save();

        return $out;
    }

    /** @return array{0: array{added: int, modified: int, removed: int, accounts: int}, 1: ?string, 2: ?string} */
    private function pages(SocialConnection $c, ?string $cursor): array
    {
        $out = ['added' => 0, 'modified' => 0, 'removed' => 0, 'accounts' => 0];
        $status = null;
        do {
            $page = $this->client->forItem($c, 'transactions/sync', array_filter([
                'cursor' => $cursor,
                'count' => self::PAGE,
            ], fn ($v) => $v !== null));

            $accounts = $this->accounts($c, (array) ($page['accounts'] ?? []));
            $out['accounts'] = max($out['accounts'], count($accounts));

            foreach (['added', 'modified'] as $kind) {
                foreach ((array) ($page[$kind] ?? []) as $t) {
                    $row = $this->upsert($c, $accounts, (array) $t);
                    if ($row !== null) {
                        $out[$kind]++;
                        $this->matcher->autoMatch($row, $c);   // money in, one recorded payment fits
                    }
                }
            }
            $removed = array_values(array_filter(array_map(fn ($r) => $r['transaction_id'] ?? null, (array) ($page['removed'] ?? [])), 'is_string'));
            if ($removed !== []) {
                $out['removed'] += BankTransaction::query()->where('social_connection_id', $c->getKey())
                    ->whereIn('plaid_transaction_id', $removed)->delete();
            }

            $cursor = is_string($page['next_cursor'] ?? null) ? $page['next_cursor'] : $cursor;
            $status = $page['transactions_update_status'] ?? $status;
        } while (($page['has_more'] ?? false) === true);

        return [$out, $cursor, is_string($status) ? $status : null];
    }

    /**
     * The accounts on a page, stored with their balances.
     *
     * @return array<string, string> Plaid account id => bank_accounts id
     */
    private function accounts(SocialConnection $c, array $rows): array
    {
        $ids = BankAccount::query()->where('social_connection_id', $c->getKey())->pluck('id', 'plaid_account_id')->all();
        foreach ($rows as $a) {
            $plaidId = $a['account_id'] ?? null;
            if (! is_string($plaidId) || $plaidId === '') {
                continue;
            }
            $b = (array) ($a['balances'] ?? []);
            $row = BankAccount::query()->updateOrCreate(
                ['social_connection_id' => $c->getKey(), 'plaid_account_id' => $plaidId],
                [
                    'name' => mb_substr((string) ($a['name'] ?? $a['official_name'] ?? 'Account'), 0, 191),
                    'official_name' => isset($a['official_name']) ? mb_substr((string) $a['official_name'], 0, 191) : null,
                    'mask' => isset($a['mask']) ? mb_substr((string) $a['mask'], 0, 8) : null,
                    'type' => isset($a['type']) ? mb_substr((string) $a['type'], 0, 24) : null,
                    'subtype' => isset($a['subtype']) ? mb_substr((string) $a['subtype'], 0, 48) : null,
                    'current_cents' => self::cents($b['current'] ?? null),
                    'available_cents' => self::cents($b['available'] ?? null),
                    'limit_cents' => self::cents($b['limit'] ?? null),
                    'currency' => isset($b['iso_currency_code']) ? (string) $b['iso_currency_code'] : null,
                    'balances_at' => isset($b['last_updated_datetime']) ? Carbon::parse($b['last_updated_datetime']) : now(),
                ],
            );
            $ids[$plaidId] = (string) $row->getKey();
        }

        return $ids;
    }

    /** @param  array<string, string>  $accounts */
    private function upsert(SocialConnection $c, array $accounts, array $t): ?BankTransaction
    {
        $id = $t['transaction_id'] ?? null;
        $account = $accounts[$t['account_id'] ?? ''] ?? null;
        if (! is_string($id) || $id === '' || $account === null || ! isset($t['date'], $t['amount'])) {
            return null;
        }
        $pfc = (array) ($t['personal_finance_category'] ?? []);

        return BankTransaction::query()->updateOrCreate(
            ['social_connection_id' => $c->getKey(), 'plaid_transaction_id' => $id],
            [
                'bank_account_id' => $account,
                'date' => (string) $t['date'],
                'authorized_date' => $t['authorized_date'] ?? null,
                'amount_cents' => (int) self::cents($t['amount']),
                'currency' => $t['iso_currency_code'] ?? null,
                'name' => mb_substr((string) ($t['name'] ?? $t['merchant_name'] ?? $t['original_description'] ?? ''), 0, 255),
                'merchant_name' => isset($t['merchant_name']) ? mb_substr((string) $t['merchant_name'], 0, 191) : null,
                'category' => isset($pfc['primary']) ? mb_substr((string) $pfc['primary'], 0, 64) : null,
                'category_detailed' => isset($pfc['detailed']) ? mb_substr((string) $pfc['detailed'], 0, 96) : null,
                'payment_channel' => isset($t['payment_channel']) ? mb_substr((string) $t['payment_channel'], 0, 16) : null,
                'pending' => (bool) ($t['pending'] ?? false),
            ],
        );
    }

    private static function cents(mixed $amount): ?int
    {
        return is_numeric($amount) ? (int) round(((float) $amount) * 100) : null;
    }
}
