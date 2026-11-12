<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Support\Pkce;
use App\Support\Social\ConnectSetup;
use App\Support\Social\MondayOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\FakesMonday;
use Tests\TestCase;

/**
 * Connect Monday (desk, 2026-11-12): the "EzBizi" Monday app's OAuth, the
 * token on a connection row, and the card's pass/fail check — the connect
 * finishes its own setup.
 */
class MondayConnectTest extends TestCase
{
    use BuildsTenants;
    use FakesMonday;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.monday.client_id' => 'monday-client-id',
            'services.monday.client_secret' => 'monday-client-secret',
            'services.monday.redirect' => 'https://app.ezbizi.test'.MondayOAuth::CALLBACK_PATH,
        ]);
    }

    public function test_connect_monday_stores_the_token_and_the_card_shows_it_passed(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/monday/start')->assertOk()->json('data.authorization_url');
        $this->assertStringStartsWith('https://auth.monday.com/oauth2/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('monday-client-id', $query['client_id']);
        $this->assertSame('https://app.ezbizi.test/api/v1/social/monday/callback', $query['redirect_uri']);

        $this->fakeMonday(['auth.monday.com/oauth2/token' => Http::response(['access_token' => 'a-monday-token', 'token_type' => 'Bearer', 'scope' => 'me:read boards:read'])]);
        $this->get(MondayOAuth::CALLBACK_PATH.'?code=a-code&state='.$query['state'])
            ->assertOk()->assertSee('Connected', false)->assertDontSee('a-monday-token');

        $row = $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'monday')->sole());
        $this->assertSame(SocialConnection::STATUS_CONNECTED, $row->status);
        $this->assertSame('acc1', $row->remote_id);
        $this->assertSame('A-1 Group', $row->display_name);
        $this->assertSame('a-monday-token', $row->credentials['access_token']);
        $this->assertSame($t['admin']->id, $row->setup['monday']['as'], 'who connected is who the sync acts as');
        $this->assertSame('alive', $row->setup['liveness']['verdict'], 'the card\'s check ran and passed');
        // The app has Monday's "New OAuth flow" on: PKCE, S256, the verifier kept server-side with the state.
        $this->assertSame('S256', $query['code_challenge_method']);
        Http::assertSent(fn ($r) => $r->url() === 'https://auth.monday.com/oauth2/token'
            && $r['client_secret'] === 'monday-client-secret'
            && is_string($r['code_verifier'] ?? null)
            && Pkce::challenge($r['code_verifier']) === $query['code_challenge']);
    }

    public function test_a_token_monday_refuses_fails_the_check(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/monday/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->fakeMonday(['auth.monday.com/oauth2/token' => Http::response(['access_token' => 'a-monday-token'])]);
        $this->get(MondayOAuth::CALLBACK_PATH.'?code=a-code&state='.$query['state'])->assertOk();
        $this->monday['refuse'] = true;

        $result = $this->inTenant($t['org'], fn () => app(ConnectSetup::class)->runCheck(SocialConnection::query()->where('provider', 'monday')->sole()));
        $this->assertSame('refused', $result->verdict);
    }

    public function test_without_the_app_configured_monday_says_why_instead_of_starting(): void
    {
        config(['services.monday.client_id' => null, 'services.monday.client_secret' => null]);
        $t = $this->provisionTenant('A1-Group');

        $this->signedInAs($t['admin'])->postJson('/api/v1/social/monday/start')->assertStatus(501)
            ->assertJsonFragment(['message' => (new MondayOAuth)->notReadyBecause()]);
    }
}
