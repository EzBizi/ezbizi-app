<?php

namespace Tests\Feature\Api;

use App\Jobs\RegisterThumbtackWebhook;
use App\Models\SocialConnection;
use App\Support\Social\SocialProvider;
use App\Support\Social\ThumbtackOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
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

    private function beginSignIn(array $t): string
    {
        $url = $this->signedInAs($t['admin'])
            ->postJson(self::START)
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query['state'];
    }
}
