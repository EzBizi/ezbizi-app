<?php

namespace Tests\Feature\Api;

use App\Jobs\CheckNewConnections;
use App\Jobs\RegisterThumbtackWebhook;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Connecting Thumbtack has to do a second thing, and for a while it did not.
 *
 * Their supply API cannot list negotiations. A connected business with no
 * registered webhook receives nothing, permanently, and nothing can go back for
 * what it missed — so registration is not a follow-up task, it is half of what
 * "connected" means.
 *
 * On 2026-09-08 twelve businesses connected against production, the screen said
 * Connected, and no webhook existed on any of them. `ThumbtackWebhooks::ensure()`
 * was written and tested; nothing ever called it. These tests are about the
 * call, not the registering — that is covered in ThumbtackWebhookTest.
 */
class ThumbtackConnectTest extends TestCase
{
    use GivesAuthenticatorCodes;
    use BuildsTenants;
    use RefreshDatabase;

    private const START = '/api/v1/social/thumbtack/start';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.thumbtack.client_id'     => 'a-client',
            'services.thumbtack.client_secret' => 'a-secret',
            'services.thumbtack.redirect'      => 'https://app.ezbizi.test'.ThumbtackOAuth::CALLBACK_PATH,
            'services.thumbtack.api_base'      => 'https://api.thumbtack.com/api',
        ]);
    }

    public function test_every_connected_business_gets_a_webhook_registration_queued(): void
    {
        Bus::fake();

        $t = $this->provisionTenant('A1-Group');

        // Two businesses on one login, which is the normal Thumbtack shape and
        // the reason identify() returns a list rather than one account.
        Http::fake([
            'auth.thumbtack.com/oauth2/token' => Http::response([
                'access_token'  => 'an-access-token',
                'refresh_token' => 'a-refresh-token',
                'expires_in'    => 3600,
            ]),
            'api.thumbtack.com/api/v4/businesses*' => Http::response(['data' => [
                ['businessID' => 'biz-1', 'name' => 'A-1 Chimney', 'imageURL' => 'https://x.test/1.png'],
                ['businessID' => 'biz-2', 'name' => 'A-1 Cleveland', 'imageURL' => 'https://x.test/2.png'],
            ]]),
        ]);

        $state = $this->beginSignIn($t);

        $this->get('/api/v1/social/thumbtack/callback?code=an-auth-code&state='.$state)
            ->assertOk();

        $this->inTenant($t['org'], function () {
            $this->assertSame(2, SocialConnection::query()
                ->where('provider', SocialProvider::Thumbtack->value)
                ->count());
        });

        // One per business. Not one for the login: the webhook is registered
        // against a businessID, so a single job would leave the second business
        // silent while the screen claimed both were connected.
        Bus::assertDispatchedTimes(RegisterThumbtackWebhook::class, 2);
    }

    /**
     * Jose, 2026-11-12: "doing a connection should take care of the whole
     * process". One branch is the branch; two are a person's choice, and the
     * card says so. Either way the health check runs now, not tomorrow.
     */
    public function test_connect_picks_the_only_branch_checks_health_now_and_says_what_is_left(): void
    {
        Bus::fake();
        $t = $this->provisionTenant('A1-Group');
        $this->fakeTwoBusinesses();

        $this->get('/api/v1/social/thumbtack/callback?code=an-auth-code&state='.$this->beginSignIn($t))->assertOk();

        $rows = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'thumbtack')->get());
        $this->assertSame([$t['franchise']->id, $t['franchise']->id], $rows->pluck('leads_franchise_id')->all());
        Bus::assertDispatched(CheckNewConnections::class, fn (CheckNewConnections $job) => collect($job->ids)->sort()->values()->all() === $rows->pluck('id')->map(fn ($id) => (string) $id)->sort()->values()->all());

        // A second branch: nothing to infer, so nothing is guessed.
        $this->extraFranchise($t, 'Cleveland');
        $this->inTenant($t['org'], fn () => SocialConnection::query()->update(['leads_franchise_id' => null]));
        $this->get('/api/v1/social/thumbtack/callback?code=an-auth-code&state='.$this->beginSignIn($t))->assertOk();

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::query()->whereNotNull('leads_franchise_id')->count()));
        $details = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections?include=data_sources')->assertOk()->json('data'))
            ->where('provider', 'thumbtack')->pluck('detail')->unique()->values()->all();
        $this->assertSame(['No branch: its leads arrive unassigned. Choose one in Leads › Accounts.'], $details);
    }

    /** The health job records what the daily command would, for each new row. */
    public function test_the_connect_health_check_stamps_the_row(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $row = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'thumbtack', 'remote_id' => 'biz-1', 'display_name' => 'A-1 Chimney',
            ]);
            $c->connect(['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->toIso8601String()]);
            $c->save();

            return $c;
        });
        Http::fake([
            'auth.thumbtack.com/oauth2/token' => Http::response(['access_token' => 'b', 'refresh_token' => 'r2', 'expires_in' => 3600]),
            'api.thumbtack.com/*' => Http::response(['data' => []]),
        ]);

        (new CheckNewConnections([(string) $row->id]))->handle(app(\App\Support\Tenancy\TenantContext::class), app(\App\Support\Social\Liveness\ConnectionLiveness::class));

        $this->assertNotNull($row->fresh()->last_checked_at);
        // Thumbtack listed no webhook for it, so the check registered one again (desk audit, 2026-11-12).
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/webhooks'));
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $row->fresh()->status);

        // A Google Ads account nobody has read for hours says so on its card, as on Leads.
        $ads = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $t['org']->id, 'scope_type' => 'company', 'scope_id' => $t['company']->id,
                'provider' => 'google_ads', 'remote_id' => '1234567890', 'display_name' => 'A1 Chimney - Austin',
                'leads_franchise_id' => $t['franchise']->id, 'created_at' => now()->subDays(3),
            ]);
            $c->connect(['refresh_token' => 'rt']);
            $c->forceFill(['last_reached_at' => now()->subDays(2)]);
            $c->save();

            return $c;
        });
        $card = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections?include=data_sources')->json('data'))->firstWhere('id', $ads->id);
        $this->assertStringStartsWith('Not fetching:', (string) $card['detail']);
    }

    private function fakeTwoBusinesses(): void
    {
        Http::fake([
            'auth.thumbtack.com/oauth2/token' => Http::response([
                'access_token' => 'an-access-token', 'refresh_token' => 'a-refresh-token', 'expires_in' => 3600,
            ]),
            'api.thumbtack.com/api/v4/businesses*' => Http::response(['data' => [
                ['businessID' => 'biz-1', 'name' => 'A-1 Chimney', 'imageURL' => 'https://x.test/1.png'],
                ['businessID' => 'biz-2', 'name' => 'A-1 Cleveland', 'imageURL' => 'https://x.test/2.png'],
            ]]),
        ]);
    }

    public function test_connecting_something_that_is_not_thumbtack_queues_nothing(): void
    {
        // The dispatch sits in the shared callback every provider goes through,
        // so this is the assertion that keeps it provider-specific.
        Bus::fake();

        config([
            'services.pinterest.client_id'     => 'a-pinterest-app-id',
            'services.pinterest.client_secret' => 'a-pinterest-app-secret',
            'services.pinterest.redirect'      => 'https://app.ezbizi.test/api/v1/social/pinterest/callback',
        ]);

        $t = $this->provisionTenant('A1-Group');

        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response([
                'access_token'  => 'a', 'refresh_token' => 'r', 'expires_in' => 2592000,
            ]),
            'api.pinterest.com/v5/user_account' => Http::response([
                'id' => 'pin-1', 'username' => 'a1chimney', 'business_name' => 'A-1 Chimney',
            ]),
        ]);

        $state = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/pinterest/start')
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($state, PHP_URL_QUERY), $q);

        $this->get('/api/v1/social/pinterest/callback?code=c&state='.$q['state'])->assertOk();

        Bus::assertNotDispatched(RegisterThumbtackWebhook::class);
    }

    /**
     * Disconnecting has to tell Thumbtack, and for a while it did not.
     *
     * Deleting our own row leaves the webhook still firing at an endpoint that
     * no longer knows it, the associate phone numbers still claiming a departed
     * company's handsets answer for that business, and the grant still listed
     * on the Pro's Thumbtack account.
     *
     * The Help Center article Thumbtack PUBLISHED for EzBizi states that
     * disconnecting does all three. ThumbtackAccount::tidyUp() was written to
     * do it, had its own passing tests, and nothing called it — which is what a
     * unit test on a method cannot tell you.
     */
    public function test_disconnecting_removes_the_numbers_webhook_and_grant_from_thumbtack(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->storedConnection($t);

        Http::fake([
            '*/associate-phone-numbers/*' => Http::response([]),
            '*/associate-phone-numbers'   => Http::response(['phoneNumbers' => [
                ['phoneNumberID' => 'pn-1', 'businessID' => 'biz-1',
                    'phoneNumber' => '+15552345678', 'createdTime' => 'then'],
            ]]),
            '*/webhooks/wh-1' => Http::response([]),
            '*/webhooks'      => Http::response(['data' => [
                ['webhookID' => 'wh-1', 'webhookURL' => 'https://app.ezbizi.test/hook'],
            ]]),
            '*/users/disconnect' => Http::response([]),
        ]);

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$connection->getKey(), $code)
            ->assertOk()
            ->assertJsonPath('data.problems', []);

        // All three, in the order that matters: revoking first would make the
        // other two impossible.
        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_contains($r->url(), '/associate-phone-numbers/pn-1'));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE'
            && str_ends_with($r->url(), '/webhooks/wh-1'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/users/disconnect'));
    }

    /**
     * One consent, twelve rows, one grant between them. Disconnecting the
     * whole provider tidies every business and revokes ONCE, on the last —
     * revoking on the first would make the other eleven's tidy-up impossible.
     */
    public function test_disconnecting_the_provider_lets_every_business_go_and_revokes_once(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->storedConnection($t);
        $this->storedConnection($t, 'biz-2');
        $this->storedConnection($t, 'biz-3');

        Http::fake([
            '*/associate-phone-numbers' => Http::response(['phoneNumbers' => []]),
            '*/webhooks/wh-1'           => Http::response([]),
            '*/webhooks'                => Http::response(['data' => [
                ['webhookID' => 'wh-1', 'webhookURL' => 'https://app.ezbizi.test/hook'],
            ]]),
            '*/users/disconnect' => Http::response([]),
        ]);

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/thumbtack/connections', $code)
            ->assertOk()
            ->assertJsonPath('data.disconnected', 3)
            ->assertJsonPath('data.problems', []);

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, SocialConnection::query()
                ->where('provider', SocialProvider::Thumbtack->value)
                ->count());
        });

        // Each business's webhook, and the grant once.
        Http::assertSentCount(3 + 3 + 3 + 1);
        $this->assertSame(1, Http::recorded(
            fn ($request) => str_ends_with($request->url(), '/users/disconnect'),
        )->count());
    }

    public function test_a_provider_nobody_can_reach_disconnects_nothing_and_says_so(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/thumbtack/connections')
            ->assertOk()
            ->assertJsonPath('data.disconnected', 0);

        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/not-a-provider/connections')
            ->assertNotFound();
    }

    /**
     * The far end being unreachable must not trap somebody in the connection.
     *
     * The account most likely to fail here — an expired token, a business
     * removed on Thumbtack's side — is exactly the one being disconnected.
     */
    public function test_a_thumbtack_that_will_not_answer_still_lets_the_account_go(): void
    {
        $t          = $this->provisionTenant('A1-Group');
        $connection = $this->storedConnection($t);

        Http::fake(['*' => Http::response(['message' => 'Token expired.'], 401)]);

        $code = $this->authenticatorCode($t['admin']);
        $response = $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/connections/'.$connection->getKey(), $code)
            ->assertOk();

        // Gone locally, and honest about what is left behind on Thumbtack for
        // a person to remove there.
        $this->assertNotEmpty($response->json('data.problems'));

        $this->inTenant($t['org'], function () {
            $this->assertSame(0, SocialConnection::query()
                ->where('provider', SocialProvider::Thumbtack->value)
                ->count());
        });
    }

    private function storedConnection(array $t, string $businessId = 'biz-1'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $businessId) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->getKey(),
                'scope_type'      => SocialConnection::SCOPE_COMPANY,
                'scope_id'        => $t['company']->getKey(),
                'provider'        => SocialProvider::Thumbtack->value,
                'remote_id'       => $businessId,
                'display_name'    => 'A-1 Chimney on Thumbtack',
            ]);
            $row->connect(['access_token' => 'a-token', 'refresh_token' => 'r-token']);
            $row->save();

            return $row;
        });
    }

    private function beginSignIn(array $t): string
    {
        $url = $this->signedInAs($t['admin'])
            ->postJson(self::START)
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }
}
