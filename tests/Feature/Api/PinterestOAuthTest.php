<?php

namespace Tests\Feature\Api;

use App\Models\AuditEvent;
use App\Models\SocialConnection;
use App\Models\SocialSignIn;
use App\Support\Social\PinterestOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connecting a Pinterest account — the first network that is actually available.
 *
 * Trial access is approved. Meta is still waiting on Tech Provider, so this is
 * the flow the Accounts tab can finish today. The shape is Email's Google
 * sign-in: authenticated start, public HTML callback, poll for status. Tokens
 * never leave this server in a response.
 */
class PinterestOAuthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const CALLBACK = '/api/v1/social/pinterest/callback';

    private const START = '/api/v1/social/pinterest/start';

    private const STATUS = '/api/v1/social/pinterest/status';

    private const REFRESH = 'pinr-a-1-chimney-refresh-token';

    private const ACCESS = 'pina-an-access-token';

    private const USERNAME = 'a1chimney';

    private const REMOTE_ID = '123456789';

    protected function setUp(): void
    {
        parent::setUp();

        // Connect now calls the provider to check it (ConnectSetup): nothing unfaked may leave.
        Http::preventStrayRequests();

        config([
            'services.pinterest.client_id'     => 'a-pinterest-app-id',
            'services.pinterest.client_secret' => 'a-pinterest-app-secret',
            'services.pinterest.redirect'      => 'https://app.ezbizi.test'.PinterestOAuth::CALLBACK_PATH,
        ]);
    }

    /** @return array<string, string> */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    private function lookingAt(\App\Models\User $user, string $type, string $id): void
    {
        $this->inTenant($user->organization, function () use ($user, $type, $id) {
            $user->forceFill([
                'preferences' => array_merge($user->preferences ?? [], [
                    'navigation.scope' => $type.':'.$id,
                ]),
            ])->save();
        });
    }

    private function tokenBody(?string $refresh = self::REFRESH): array
    {
        $token = [
            'access_token'  => self::ACCESS,
            'token_type'    => 'bearer',
            'expires_in'    => 2592000,
            'scope'         => implode(' ', PinterestOAuth::SCOPES),
            'response_type' => 'authorization_code',
        ];

        return $refresh === null ? $token : [...$token, 'refresh_token' => $refresh];
    }

    private function fakePinterest(?string $refresh = self::REFRESH, int $profileStatus = 200): void
    {
        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response($this->tokenBody($refresh)),
            'api.pinterest.com/v5/user_account' => Http::response(
                $profileStatus === 200
                    ? [
                        'id'            => self::REMOTE_ID,
                        'username'      => self::USERNAME,
                        'account_type'  => 'BUSINESS',
                        'business_name' => 'A-1 Chimney',
                    ]
                    : [],
                $profileStatus,
            ),
        ]);
    }

    private function beginSignIn(array $t, ?\App\Models\User $as = null): string
    {
        $url = $this->signedInAs($as ?? $t['admin'])
            ->postJson(self::START)
            ->assertOk()->json('data.authorization_url');

        return $this->queryOf($url)['state'];
    }

    public function test_starting_a_sign_in_takes_marketing_edit(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $viewer  = $this->userWithRole($t, 'Viewer', ['marketing.view']);
        $manager = $this->userWithRole($t, 'Manager', ['marketing.view', 'marketing.edit']);

        $this->signedInAs($viewer)->postJson(self::START)->assertForbidden();

        $this->signedInAs($manager)->postJson(self::START)->assertOk();

        $this->assertSame(1, SocialSignIn::count(),
            'A refused request left a pending sign-in behind.');
    }

    public function test_it_refuses_to_start_where_ezbizi_has_no_pinterest_app(): void
    {
        config(['services.pinterest.client_id' => null, 'services.pinterest.client_secret' => null]);

        $t = $this->provisionTenant('A1-Group');

        $body = $this->signedInAs($t['admin'])
            ->postJson(self::START)
            ->assertStatus(501)->json();

        $this->assertStringContainsString('EzBizi', $body['message']);
        $this->assertSame(0, SocialSignIn::count());
    }

    public function test_the_redirect_uri_is_config_and_cannot_be_moved_by_a_host_header(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $expected = 'https://app.ezbizi.test'.PinterestOAuth::CALLBACK_PATH;

        $honest = $this->signedInAs($t['admin'])
            ->postJson(self::START)
            ->assertOk()->json('data.authorization_url');

        $this->assertSame($expected, $this->queryOf($honest)['redirect_uri']);

        $spoofed = $this->signedInAs($t['admin'])
            ->withHeaders(['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example'])
            ->postJson(self::START)
            ->assertOk()->json('data.authorization_url');

        $this->assertSame($expected, $this->queryOf($spoofed)['redirect_uri'],
            'The Host header moved the redirect URI, so an authorization code can be sent to '
            .'a host an attacker chose.');
    }

    public function test_pinterest_is_sent_the_pin_scopes_and_not_ads(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson(self::START)
            ->assertOk()->json('data.authorization_url');

        $query = $this->queryOf($url);

        $this->assertSame('https://www.pinterest.com/oauth/', strtok($url, '?'));
        $this->assertSame('a-pinterest-app-id', $query['client_id']);
        $this->assertSame('code', $query['response_type']);
        $this->assertArrayHasKey('state', $query);
        $this->assertSame(64, strlen($query['state']));

        $scopes = preg_split('/[,\s]+/', $query['scope'], -1, PREG_SPLIT_NO_EMPTY);
        $this->assertEqualsCanonicalizing(PinterestOAuth::SCOPES, $scopes);
        $this->assertNotContains('ads:read', $scopes);
        $this->assertNotContains('ads:write', $scopes);

        $row = SocialSignIn::sole();
        $this->assertSame(hash('sha256', $query['state']), $row->state_hash);
        $this->assertSame($t['admin']->id, $row->user_id);
        $this->assertSame('organization', $row->scope_type);
        $this->assertSame($t['org']->id, $row->scope_id);
    }

    public function test_a_redirect_uri_that_is_not_this_installations_callback_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');

        foreach ([
            'somewhere else entirely' => 'https://evil.example/collect',
            'the right host, the wrong path' => 'https://app.ezbizi.test/api/v1/oops',
            'insecure, and not localhost'    => 'http://app.ezbizi.test'.PinterestOAuth::CALLBACK_PATH,
            'not a URL at all'               => 'nonsense',
        ] as $why => $configured) {
            config(['services.pinterest.redirect' => $configured]);

            $this->signedInAs($t['admin'])
                ->postJson(self::START)
                ->assertStatus(501, "A redirect URI that is {$why} was accepted.");
        }

        $this->assertSame(0, SocialSignIn::count());
    }

    public function test_the_callback_path_pinterest_is_told_about_is_a_route_this_api_registers(): void
    {
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create(PinterestOAuth::CALLBACK_PATH, 'GET'));

        $this->assertSame('social.callback', $route->getName());
    }

    public function test_a_matching_sign_in_connects_the_account_at_the_current_view(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->lookingAt($t['admin'], 'company', $t['company']->id);
        $state = $this->beginSignIn($t, $t['admin']->fresh());

        $this->fakePinterest();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)
            ->assertOk()
            ->assertSee('Connected', false)
            ->assertDontSee(self::REFRESH)
            ->assertDontSee(self::ACCESS);

        $row = $this->inTenant($t['org'], fn () => SocialConnection::sole());

        $this->assertSame(SocialConnection::STATUS_CONNECTED, $row->status);
        $this->assertSame('pinterest', $row->provider);
        $this->assertSame('company', $row->scope_type);
        $this->assertSame($t['company']->id, $row->scope_id);
        $this->assertSame(self::REMOTE_ID, $row->remote_id);
        $this->assertSame('A-1 Chimney', $row->display_name);
        $this->assertSame(self::USERNAME, $row->handle);
        $this->assertSame(self::REFRESH, $row->refreshToken());

        $this->assertSame(0, SocialSignIn::count());

        $this->assertSame(1, AuditEvent::withoutGlobalScopes()
            ->where('action', 'social.connected')->count());

        $payload = json_encode(AuditEvent::withoutGlobalScopes()
            ->where('action', 'social.connected')->first()->changes);
        $this->assertStringNotContainsString(self::REFRESH, (string) $payload);
    }

    public function test_tokens_are_encrypted_at_rest_and_never_in_the_list(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $state = $this->beginSignIn($t);
        $this->fakePinterest();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $stored = DB::table('social_connections')->value('credentials');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString(self::REFRESH, $stored);
        $this->assertStringNotContainsString(self::ACCESS, $stored);

        $list = $this->signedInAs($t['admin'])->getJson('/api/v1/social/connections')
            ->assertOk()->json();

        $this->assertStringNotContainsString(self::REFRESH, json_encode($list));
        $this->assertStringNotContainsString(self::ACCESS, json_encode($list));
    }

    public function test_a_state_cannot_be_used_twice(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $state = $this->beginSignIn($t);

        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::sequence()
                ->push($this->tokenBody())
                ->push($this->tokenBody('pinr-a-replayed-attackers-refresh-token')),
            'api.pinterest.com/v5/user_account' => Http::response([
                'id'            => self::REMOTE_ID,
                'username'      => self::USERNAME,
                'account_type'  => 'BUSINESS',
                'business_name' => 'A-1 Chimney',
            ]),
        ]);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertSame(
            self::REFRESH,
            $this->inTenant($t['org'], fn () => SocialConnection::sole()->refreshToken()),
            'A replayed sign-in link replaced the stored credential.',
        );
    }

    public function test_an_unknown_or_expired_state_is_refused(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->fakePinterest();

        $this->get(self::CALLBACK.'?code=c&state=a-state-nobody-issued')->assertStatus(400);
        $this->get(self::CALLBACK.'?code=c')->assertStatus(400);

        $state = $this->beginSignIn($t);
        SocialSignIn::sole()->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(400);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::count()));
        $this->assertSame(0, SocialSignIn::count());
    }

    public function test_declining_consent_connects_nothing(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $state = $this->beginSignIn($t);
        $this->fakePinterest();

        $this->get(self::CALLBACK.'?error=access_denied&state='.$state)
            ->assertStatus(400)
            ->assertSee('did not work', false);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::count()));
        $this->assertSame(0, SocialSignIn::count());
    }

    public function test_status_for_this_flow_reports_connected_after_the_callback(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $begun = $this->signedInAs($t['admin'])->postJson(self::START)->assertOk()->json('data');
        $state = $this->queryOf($begun['authorization_url'])['state'];
        $flow  = $begun['flow'];

        $this->fakePinterest();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $outcome = $this->signedInAs($t['admin'])
            ->getJson(self::STATUS.'?flow='.$flow)
            ->assertOk()->json('data');

        $this->assertSame('connected', $outcome['state']);
        $this->assertStringContainsString('Pinterest', (string) $outcome['message']);
    }

    public function test_a_stranger_cannot_read_another_users_outcome(): void
    {
        $t       = $this->provisionTenant('A1-Group');
        $manager = $this->userWithRole($t, 'Manager', ['marketing.view', 'marketing.edit']);

        $begun = $this->signedInAs($t['admin'])->postJson(self::START)->assertOk()->json('data');
        $state = $this->queryOf($begun['authorization_url'])['state'];

        $this->fakePinterest();
        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertOk();

        $peeked = $this->signedInAs($manager)
            ->getJson(self::STATUS.'?flow='.$begun['flow'])
            ->assertOk()->json('data');

        $this->assertSame('waiting', $peeked['state'],
            'A flow id let somebody else read whether a sign-in finished.');
    }

    public function test_no_lasting_permission_is_refused_rather_than_stored(): void
    {
        $t     = $this->provisionTenant('A1-Group');
        $state = $this->beginSignIn($t);

        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response($this->tokenBody(null)),
            'api.pinterest.com/v5/user_account' => Http::response([
                'id'       => self::REMOTE_ID,
                'username' => self::USERNAME,
            ]),
        ]);

        $this->get(self::CALLBACK.'?code=an-auth-code&state='.$state)->assertStatus(502);

        $this->assertSame(0, $this->inTenant($t['org'], fn () => SocialConnection::count()));
    }
}
