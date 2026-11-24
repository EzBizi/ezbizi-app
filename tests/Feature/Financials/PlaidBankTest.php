<?php

namespace Tests\Feature\Financials;

use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\SocialConnection;
use App\Support\Financials\Plaid\PlaidSync;
use App\Support\Social\PlaidOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Financials › Bank through Plaid (Jose, 2026-11-24): connect by Hosted Link
 * on the shared sign-in rows, the first sync on connect, the cursor, removals,
 * webhooks that only point at an item, the strict per-view rule for banks,
 * update mode, and disconnect telling Plaid.
 */
class PlaidBankTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    /** @var array<string, mixed> what the fake Plaid answers */
    private array $plaid = [];

    /** @var list<array{path: string, body: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'app.url' => 'https://app.ezbizi.test',
            'services.plaid.client_id' => 'plaid-client',
            'services.plaid.secret' => 'plaid-secret',
            'services.plaid.environment' => 'sandbox',
        ]);
        $this->plaid = [
            'session' => ['results' => ['item_add_results' => [['public_token' => 'public-sandbox-1', 'institution' => ['name' => 'First Platypus Bank']]]]],
            'pages' => [],   // cursor => page
            'item_error' => null,
            'removed' => false,
        ];
        Http::fake(['sandbox.plaid.com/*' => function (Request $r) {
            $path = trim((string) parse_url($r->url(), PHP_URL_PATH), '/');
            $this->sent[] = ['path' => $path, 'body' => $r->data()];
            $this->assertSame('plaid-client', $r['client_id']);
            $this->assertSame('plaid-secret', $r['secret']);

            return match ($path) {
                'link/token/create' => Http::response(['link_token' => 'link-sandbox-1', 'hosted_link_url' => 'https://hosted.plaid.com/link/abc', 'expiration' => now()->addHour()->toIso8601String()]),
                'link/token/get' => Http::response(['link_token' => $r['link_token'], 'link_sessions' => $this->plaid['session'] ? [$this->plaid['session']] : []]),
                'item/public_token/exchange' => Http::response(['access_token' => 'access-sandbox-1', 'item_id' => 'item-1']),
                'item/get' => $this->plaid['item_error']
                    ? Http::response(['item' => ['item_id' => 'item-1', 'error' => ['error_code' => $this->plaid['item_error']]]])
                    : Http::response(['item' => ['item_id' => 'item-1', 'error' => null]]),
                'transactions/sync' => Http::response($this->plaid['pages'][$r['cursor'] ?? ''] ?? ['added' => [], 'modified' => [], 'removed' => [], 'accounts' => [], 'next_cursor' => $r['cursor'] ?? 'c0', 'has_more' => false]),
                'item/remove' => tap(Http::response(['request_id' => 'r']), fn () => $this->plaid['removed'] = true),
                default => Http::response(['error_code' => 'UNEXPECTED'], 400),
            };
        }]);
    }

    public function test_connect_by_hosted_link_reads_the_bank_and_never_shows_the_token(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->plaid['pages'][''] = $this->page('c1', [$this->txn('t1', 'a1', 12.34, 'Home Depot')], hasMore: true);
        $this->plaid['pages']['c1'] = $this->page('c2', [$this->txn('t2', 'a1', -500, 'Customer payment')]);

        $url = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')
            ->postJson('/api/v1/social/plaid/start')->assertOk()->json('data.authorization_url');
        $this->assertSame('https://hosted.plaid.com/link/abc', $url);

        $create = collect($this->sent)->firstWhere('path', 'link/token/create')['body'];
        $this->assertSame(['transactions'], $create['products']);
        $this->assertSame(730, $create['transactions']['days_requested']);
        $this->assertSame('https://app.ezbizi.test/api/v1/integrations/plaid/webhook', $create['webhook']);
        parse_str((string) parse_url($create['hosted_link']['completion_redirect_uri'], PHP_URL_QUERY), $back);
        $this->assertStringStartsWith('https://app.ezbizi.test/api/v1/social/plaid/callback?', $create['hosted_link']['completion_redirect_uri']);

        $this->get(PlaidOAuth::CALLBACK_PATH.'?'.http_build_query($back))
            ->assertOk()->assertSee('Connected', false)->assertSee('First Platypus Bank', false)->assertDontSee('access-sandbox-1');

        $row = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'plaid')->sole());
        $this->assertSame('item-1', $row->remote_id);
        $this->assertSame('access-sandbox-1', $row->credentials['access_token']);
        $this->assertSame('alive', $row->setup['liveness']['verdict'], 'the card\'s check ran and passed');
        $this->assertSame('c2', $row->setup['plaid']['cursor'], 'the first sync ran on connect and paged to the end');

        $txns = $this->inTenant($t['org'], fn () => BankTransaction::query()->orderBy('plaid_transaction_id')->get());
        $this->assertSame([1234, -50000], $txns->pluck('amount_cents')->all(), 'Plaid\'s sign kept: positive is money out');
        $account = $this->inTenant($t['org'], fn () => BankAccount::query()->sole());
        $this->assertSame(['Checking', '0000', 110000], [$account->name, $account->mask, $account->current_cents]);

        // The tab: accounts with balances, transactions searchable; the token nowhere.
        $bank = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')->getJson('/api/v1/financials/bank')->assertOk();
        $this->assertStringNotContainsString('access-sandbox-1', $bank->getContent());
        $this->assertSame('First Platypus Bank', $bank->json('data.banks.0.name'));
        $this->assertSame(110000, $bank->json('data.banks.0.accounts.0.current_cents'));
        $found = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')
            ->getJson('/api/v1/financials/bank/transactions?search=depot')->assertOk();
        $this->assertSame(['Home Depot'], array_column($found->json('data'), 'name'));
        $this->assertSame(['Customer payment'], array_column($this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')
            ->getJson('/api/v1/financials/bank/transactions?direction=in')->json('data'), 'name'));
    }

    public function test_leaving_hosted_link_without_a_bank_connects_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->plaid['session'] = ['results' => ['item_add_results' => []], 'exit' => ['error' => null]];
        $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')->postJson('/api/v1/social/plaid/start')->assertOk();
        parse_str((string) parse_url(collect($this->sent)->firstWhere('path', 'link/token/create')['body']['hosted_link']['completion_redirect_uri'], PHP_URL_QUERY), $back);

        $this->get(PlaidOAuth::CALLBACK_PATH.'?'.http_build_query($back))->assertSee('That did not work', false);
        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'plaid')->count()));
        $this->assertNotContains('item/public_token/exchange', array_column($this->sent, 'path'));
    }

    public function test_a_later_sync_continues_from_the_cursor_and_applies_removals(): void
    {
        $t = $this->provisionTenant('Alpha');
        $c = $this->bank($t, 'organization', $t['org']->id, cursor: 'c2');
        $this->inTenant($t['org'], fn () => app(PlaidSync::class)->run($c->fresh()));   // nothing new at c2
        $this->plaid['pages'][''] = $this->page('c1', [$this->txn('t1', 'a1', 5, 'Old')]);
        $this->plaid['pages']['c2'] = $this->page('c3', [$this->txn('t3', 'a1', 7.5, 'Fuel')], modified: [$this->txn('t1', 'a1', 6, 'Old, settled')], removed: ['t9']);
        $this->inTenant($t['org'], function () use ($c) {
            $account = BankAccount::query()->create(['social_connection_id' => $c->id, 'plaid_account_id' => 'a1', 'name' => 'Checking']);
            foreach (['t1' => 500, 't9' => 900] as $id => $cents) {
                BankTransaction::query()->create(['social_connection_id' => $c->id, 'bank_account_id' => $account->id, 'plaid_transaction_id' => $id, 'date' => '2026-11-20', 'amount_cents' => $cents, 'name' => $id]);
            }
        });

        $out = $this->inTenant($t['org'], fn () => app(PlaidSync::class)->run($c->fresh()));

        $this->assertSame(['added' => 1, 'modified' => 1, 'removed' => 1, 'accounts' => 1], $out);
        $this->assertSame(['t1' => 600, 't3' => 750], $this->inTenant($t['org'], fn () => BankTransaction::query()->orderBy('plaid_transaction_id')->pluck('amount_cents', 'plaid_transaction_id')->all()));
        $this->assertSame('c3', $this->inTenant($t['org'], fn () => $c->fresh()->setup['plaid']['cursor']));
        $this->assertSame('c2', collect($this->sent)->where('path', 'transactions/sync')->last()['body']['cursor'], 'read from the stored cursor, not from the start');
    }

    public function test_a_webhook_only_points_at_an_item_and_what_happens_is_asked_of_plaid(): void
    {
        $t = $this->provisionTenant('Alpha');
        $c = $this->bank($t, 'organization', $t['org']->id);

        // A forged ERROR while Plaid says the item is healthy changes nothing.
        $this->postJson('/api/v1/integrations/plaid/webhook', ['webhook_type' => 'ITEM', 'webhook_code' => 'ERROR', 'item_id' => 'item-1', 'error' => ['error_code' => 'ITEM_LOGIN_REQUIRED']])->assertOk();
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $this->inTenant($t['org'], fn () => $c->fresh()->status));

        // When Plaid itself says the login is needed, the card says Reconnect.
        $this->plaid['item_error'] = 'ITEM_LOGIN_REQUIRED';
        $this->postJson('/api/v1/integrations/plaid/webhook', ['webhook_type' => 'ITEM', 'webhook_code' => 'ERROR', 'item_id' => 'item-1'])->assertOk();
        $this->assertSame(SocialConnection::STATUS_BROKEN, $this->inTenant($t['org'], fn () => $c->fresh()->status));

        // An item nobody here holds: 200, nothing asked.
        $before = count($this->sent);
        $this->postJson('/api/v1/integrations/plaid/webhook', ['webhook_type' => 'TRANSACTIONS', 'webhook_code' => 'SYNC_UPDATES_AVAILABLE', 'item_id' => 'nobody'])->assertOk();
        $this->assertCount($before, $this->sent);

        // Reconnect (update mode) for the same login; Plaid's return page heals it on Plaid's word.
        $url = $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')
            ->postJson("/api/v1/financials/bank/{$c->id}/reconnect")->assertOk()->json('data.authorization_url');
        $this->assertSame('https://hosted.plaid.com/link/abc', $url);
        $create = collect($this->sent)->where('path', 'link/token/create')->last()['body'];
        $this->assertSame('access-sandbox-1', $create['access_token']);
        $this->assertArrayNotHasKey('products', $create, 'update mode names no products');
        parse_str((string) parse_url($create['hosted_link']['completion_redirect_uri'], PHP_URL_QUERY), $back);
        $this->plaid['item_error'] = null;
        $this->get(PlaidOAuth::RELINKED_PATH.'?'.http_build_query($back))->assertOk();
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $this->inTenant($t['org'], fn () => $c->fresh()->status));
    }

    public function test_a_bank_is_seen_only_from_where_it_was_connected_or_above(): void
    {
        $t = $this->provisionTenant('Alpha');
        $miami = $this->extraFranchise($t, 'Miami');
        $org = $this->bank($t, 'organization', $t['org']->id, item: 'item-org', name: 'Org Bank');
        $this->bank($t, 'company', $t['company']->id, item: 'item-co', name: 'Company Bank');
        $this->bank($t, 'franchise', $miami->id, item: 'item-mia', name: 'Miami Bank');

        $names = fn (string $view) => array_column($this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', $view)
            ->getJson('/api/v1/financials/bank')->assertOk()->json('data.banks'), 'name');
        $this->assertSame(['Company Bank', 'Miami Bank', 'Org Bank'], $names('organization'));
        $this->assertSame(['Company Bank', 'Miami Bank'], $names('company:'.$t['company']->id), 'the organization\'s bank stays out of a company');
        $this->assertSame(['Miami Bank'], $names('franchise:'.$miami->id));
        $this->assertSame([], $names('franchise:'.$t['franchise']->id), 'a sibling branch sees no other branch\'s bank');

        // Nor can a branch act on a bank it does not see.
        $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'franchise:'.$miami->id)
            ->postJson("/api/v1/financials/bank/{$org->id}/refresh")->assertNotFound();
    }

    public function test_the_tab_and_the_changes_are_gated(): void
    {
        $t = $this->provisionTenant('Alpha');
        $c = $this->bank($t, 'organization', $t['org']->id);
        $this->signedInAs($this->userWithRole($t, 'Clerk', ['financials.view']))->getJson('/api/v1/financials/bank')->assertForbidden();
        $reader = $this->userWithRole($t, 'Reader', ['financials.view', 'financials.bank.view']);
        $this->signedInAs($reader)->getJson('/api/v1/financials/bank')->assertOk()->assertJsonPath('data.can_connect', false);
        $this->signedInAs($reader)->postJson("/api/v1/financials/bank/{$c->id}/refresh")->assertForbidden();
        $this->signedInAs($reader)->postJson('/api/v1/social/plaid/start')->assertForbidden();
    }

    public function test_disconnect_tells_plaid_and_removes_what_was_read(): void
    {
        $t = $this->provisionTenant('Alpha');
        $c = $this->bank($t, 'organization', $t['org']->id);
        $this->inTenant($t['org'], function () use ($c) {
            $a = BankAccount::query()->create(['social_connection_id' => $c->id, 'plaid_account_id' => 'a1', 'name' => 'Checking']);
            BankTransaction::query()->create(['social_connection_id' => $c->id, 'bank_account_id' => $a->id, 'plaid_transaction_id' => 't1', 'date' => '2026-11-20', 'amount_cents' => 1, 'name' => 'x']);
        });

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->withHeader('X-EzBizi-View', 'organization')
            ->deleteJson("/api/v1/social/connections/{$c->id}", $code)->assertOk()->assertJsonPath('data.problems', []);

        $this->assertTrue($this->plaid['removed'], '/item/remove was called');
        $this->assertSame([0, 0], $this->inTenant($t['org'], fn () => [BankAccount::query()->count(), BankTransaction::query()->count()]));
    }

    public function test_without_keys_plaid_says_why_instead_of_starting(): void
    {
        config(['services.plaid.client_id' => null]);
        $t = $this->provisionTenant('Alpha');
        $this->signedInAs($t['admin'])->postJson('/api/v1/social/plaid/start')->assertStatus(501)
            ->assertJsonFragment(['message' => app(PlaidOAuth::class)->notReadyBecause()]);
    }

    private function bank(array $t, string $scope, string $scopeId, ?string $cursor = null, string $item = 'item-1', string $name = 'First Platypus Bank'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $scope, $scopeId, $cursor, $item, $name) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => $scope, 'scope_id' => $scopeId,
                'provider' => 'plaid', 'remote_id' => $item, 'display_name' => $name,
                'setup' => $cursor ? ['plaid' => ['cursor' => $cursor]] : null]);
            $c->connect(['access_token' => 'access-sandbox-1']);
            $c->save();

            return $c;
        });
    }

    private function page(string $next, array $added, bool $hasMore = false, array $modified = [], array $removed = []): array
    {
        return [
            'added' => $added, 'modified' => $modified,
            'removed' => array_map(fn ($id) => ['transaction_id' => $id, 'account_id' => 'a1'], $removed),
            'accounts' => [['account_id' => 'a1', 'name' => 'Checking', 'mask' => '0000', 'type' => 'depository', 'subtype' => 'checking',
                'balances' => ['current' => 1100, 'available' => 1000, 'limit' => null, 'iso_currency_code' => 'USD']]],
            'next_cursor' => $next, 'has_more' => $hasMore, 'transactions_update_status' => 'HISTORICAL_UPDATE_COMPLETE',
        ];
    }

    private function txn(string $id, string $account, float $amount, string $name): array
    {
        return ['transaction_id' => $id, 'account_id' => $account, 'amount' => $amount, 'iso_currency_code' => 'USD',
            'date' => '2026-11-20', 'name' => $name, 'merchant_name' => $name, 'pending' => false,
            'personal_finance_category' => ['primary' => 'GENERAL_MERCHANDISE', 'detailed' => 'GENERAL_MERCHANDISE_OTHER']];
    }
}
