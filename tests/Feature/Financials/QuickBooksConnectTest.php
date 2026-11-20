<?php

namespace Tests\Feature\Financials;

use App\Models\SocialConnection;
use App\Support\Financials\QuickBooks\QuickBooksRefused;
use App\Support\Financials\QuickBooks\QuickBooksToken;
use App\Support\Social\QuickBooksOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\FakesQuickBooks;
use Tests\TestCase;

/**
 * Connect QuickBooks (Shoval, 2026-11-20): Intuit's OAuth with the realm on
 * the callback, the card's check, the first read on connect, and a refresh
 * token that rotates under a lock.
 */
class QuickBooksConnectTest extends TestCase
{
    use BuildsTenants;
    use FakesQuickBooks;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.quickbooks.client_id' => 'qbo-client-id',
            'services.quickbooks.client_secret' => 'qbo-client-secret',
            'services.quickbooks.environment' => 'sandbox',
            'services.quickbooks.redirect' => 'https://app.ezbizi.test'.QuickBooksOAuth::CALLBACK_PATH,
        ]);
    }

    public function test_connect_stores_the_realm_reads_the_books_and_the_card_passes(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeQuickBooks();
        $this->qboBooks();
        $this->qbo['Purchase'] = [$this->qboPurchase('9', now()->subDays(3)->toDateString(), [[120.50, '7', '100']])];

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/quickbooks/start')->assertOk()->json('data.authorization_url');
        $this->assertStringStartsWith(QuickBooksOAuth::AUTHORIZE_ENDPOINT.'?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('qbo-client-id', $query['client_id']);
        $this->assertSame('com.intuit.quickbooks.accounting', $query['scope']);
        $this->assertSame('https://app.ezbizi.test/api/v1/social/quickbooks/callback', $query['redirect_uri']);

        $this->get(QuickBooksOAuth::CALLBACK_PATH.'?code=a-code&realmId=4620816365&state='.$query['state'])
            ->assertOk()->assertSee('Connected', false)->assertDontSee('access-0');

        $row = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'quickbooks')->sole());
        $this->assertSame('4620816365', $row->remote_id);
        $this->assertSame('Alpha Books LLC', $row->display_name);
        $this->assertSame('refresh-0', $row->credentials['refresh_token']);
        $this->assertSame('alive', $row->setup['liveness']['verdict'], 'the card\'s check ran and passed');
        $this->assertNotNull($row->setup['quickbooks']['synced_at'] ?? null, 'the first read ran on connect');
        $this->assertSame(1, $this->inTenant($t['org'], fn () => \App\Models\FinancialExpense::query()->count()));

        // Intuit: Basic auth on the token call, the redirect URI echoed, the sandbox host for reads.
        Http::assertSent(fn ($r) => $r->url() === QuickBooksOAuth::TOKEN_ENDPOINT
            && $r->header('Authorization')[0] === 'Basic '.base64_encode('qbo-client-id:qbo-client-secret')
            && $r['grant_type'] === 'authorization_code' && $r['redirect_uri'] === 'https://app.ezbizi.test/api/v1/social/quickbooks/callback');
        Http::assertSent(fn ($r) => str_starts_with($r->url(), 'https://sandbox-quickbooks.api.intuit.com/v3/company/4620816365/companyinfo/4620816365'));
    }

    public function test_a_callback_without_a_realm_connects_nothing(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeQuickBooks();
        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/quickbooks/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->get(QuickBooksOAuth::CALLBACK_PATH.'?code=a-code&state='.$query['state'])->assertSee('That did not work', false);
        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'quickbooks')->count()));
    }

    public function test_an_expiring_token_is_renewed_once_and_the_rotated_refresh_token_is_kept(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeQuickBooks();
        $row = $this->connection($t, now()->addMinute());

        $tokens = app(QuickBooksToken::class);
        $this->assertSame('access-1', $this->inTenant($t['org'], fn () => $tokens->for($row)));
        $row = $this->inTenant($t['org'], fn () => $row->fresh());
        $this->assertSame('refresh-1', $row->credentials['refresh_token'], 'the rotated one replaces the spent one');
        $this->assertTrue(now()->addMinutes(55)->lessThan($row->credentials['expires_at']));

        // A second caller holding the old row: re-read inside the lock, nothing spent again.
        $stale = $this->inTenant($t['org'], fn () => SocialConnection::query()->find($row->id))->forceFill(['credentials' => ['access_token' => 'old', 'refresh_token' => 'refresh-0', 'expires_at' => now()->subMinute()->toIso8601String()]]);
        $this->assertSame('access-1', $this->inTenant($t['org'], fn () => $tokens->for($stale)));
        $this->assertSame(1, $this->qbo['refresh_count']);
        Http::assertSent(fn ($r) => $r->url() === QuickBooksOAuth::TOKEN_ENDPOINT && $r['refresh_token'] === 'refresh-0');
    }

    public function test_invalid_grant_marks_the_card_reconnect_and_does_not_retry(): void
    {
        $t = $this->provisionTenant('Alpha');
        $this->fakeQuickBooks();
        $this->qbo['refuse'] = true;
        $row = $this->connection($t, now()->subMinute());

        try {
            $this->inTenant($t['org'], fn () => app(QuickBooksToken::class)->for($row));
            $this->fail('A refused refresh token must stop the run.');
        } catch (QuickBooksRefused $e) {
            $this->assertSame(QuickBooksRefused::RECONNECT, $e->getMessage());
        }
        $row = $this->inTenant($t['org'], fn () => $row->fresh());
        $this->assertSame(SocialConnection::STATUS_BROKEN, $row->status);
        $this->assertSame(QuickBooksRefused::RECONNECT, $row->status_reason);
        Http::assertSentCount(1);
    }

    public function test_without_the_app_configured_quickbooks_says_why_instead_of_starting(): void
    {
        config(['services.quickbooks.client_id' => null, 'services.quickbooks.client_secret' => null]);
        $t = $this->provisionTenant('Alpha');

        $this->signedInAs($t['admin'])->postJson('/api/v1/social/quickbooks/start')->assertStatus(501)
            ->assertJsonFragment(['message' => (new QuickBooksOAuth)->notReadyBecause()]);
    }

    private function connection(array $t, \DateTimeInterface $expires): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $expires) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => 'quickbooks', 'remote_id' => '4620816365', 'display_name' => 'Alpha Books LLC',
            ]);
            $c->connect(['access_token' => 'access-0', 'refresh_token' => 'refresh-0', 'expires_at' => $expires->format(DATE_ATOM)]);
            $c->save();

            return $c;
        });
    }
}
