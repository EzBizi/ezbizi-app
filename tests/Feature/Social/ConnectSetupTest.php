<?php

namespace Tests\Feature\Social;

use App\Models\NewsletterAudience;
use App\Models\SocialConnection;
use App\Support\Social\ConnectSetup;
use App\Support\Social\LinkedInOAuth;
use App\Support\Social\PinterestOAuth;
use App\Support\Social\Publishing\LinkedInPublisher;
use App\Support\Social\Publishing\SocialPublishFailed;
use App\Support\Social\SocialOAuthGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connect takes care of the whole process (Jose, 2026-11-12): it makes the
 * choices the account needs with a default, creates what is missing, and
 * runs a pass/fail check the card shows.
 */
class ConnectSetupTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const PIN_ALL = 'boards:read,boards:write,pins:read,pins:write,user_accounts:read';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config([
            'services.pinterest.client_id' => 'a-pinterest-app-id',
            'services.pinterest.client_secret' => 'a-pinterest-app-secret',
            'services.pinterest.redirect' => 'https://app.ezbizi.test'.PinterestOAuth::CALLBACK_PATH,
            'services.pinterest.sandbox_token' => null,
        ]);
    }

    private function connectPinterest(array $t, string $scope, array $boards): SocialConnection
    {
        Http::fake([
            'api.pinterest.com/v5/oauth/token' => Http::response([
                'access_token' => 'pina-x', 'refresh_token' => 'pinr-x', 'expires_in' => 2592000, 'scope' => $scope,
            ]),
            'api.pinterest.com/v5/user_account' => Http::response(['id' => '42', 'username' => 'a1', 'business_name' => 'A-1']),
            'api.pinterest.com/v5/boards*' => Http::sequence()
                ->push(['items' => $boards])
                ->push(['id' => 'new-board', 'name' => 'A1-Group'], 201),
        ]);

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/pinterest/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->get(PinterestOAuth::CALLBACK_PATH.'?code=c&state='.$q['state'])->assertOk();

        return $this->inTenant($t['org'], fn () => SocialConnection::query()->where('provider', 'pinterest')->firstOrFail());
    }

    public function test_pinterest_connect_chooses_the_first_board_and_passes_its_check(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connectPinterest($t, self::PIN_ALL, [['id' => 'b-1', 'name' => 'Chimneys'], ['id' => 'b-2', 'name' => 'Dryer vents']]);

        $this->assertSame('b-1', ConnectSetup::chosen($c, 'board'));
        $this->assertNotNull($c->last_checked_at, 'The check did not run right after connecting.');

        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/connections')->assertOk()->json('data'))
            ->firstWhere('provider', 'pinterest');
        $this->assertTrue($row['connect_check']['passed']);
        $this->assertTrue($row['connect_check']['can_post']);
        $this->assertSame('renews automatically', $row['connect_check']['token']);
        $this->assertSame('b-1', $row['choices'][0]['selected']);
        $this->assertCount(2, $row['choices'][0]['options']);

        $this->signedInAs($t['admin'])->patchJson("/api/v1/social/connections/{$c->id}/choice", ['key' => 'board', 'value' => 'b-2'])
            ->assertOk()->assertJsonPath('data.choices.0.selected', 'b-2');
        $this->signedInAs($t['admin'])->patchJson("/api/v1/social/connections/{$c->id}/choice", ['key' => 'board', 'value' => 'not-a-board'])
            ->assertStatus(422);
    }

    public function test_pinterest_with_no_board_gets_one_created(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connectPinterest($t, self::PIN_ALL, []);

        $this->assertSame('new-board', ConnectSetup::chosen($c, 'board'));
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v5/boards'));
    }

    public function test_a_missing_posting_scope_fails_the_check_on_the_card(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->connectPinterest($t, 'boards:read,pins:read,user_accounts:read', [['id' => 'b-1', 'name' => 'Chimneys']]);

        $check = ConnectSetup::check($c);
        $this->assertFalse($check['passed']);
        $this->assertSame(['pins:write'], $check['missing_scopes']);
        $this->assertStringStartsWith('Failed', $check['summary']);
    }

    public function test_scopes_parse_however_the_network_spells_them(): void
    {
        $this->assertSame(['a', 'b'], SocialOAuthGrant::parseScopes('a,b'));
        $this->assertSame(['a', 'b'], SocialOAuthGrant::parseScopes('a b'));
        $this->assertSame(['a', 'b'], SocialOAuthGrant::parseScopes(['a', 'b']));
        $this->assertSame([], SocialOAuthGrant::parseScopes(null));
    }

    public function test_x_without_media_write_fails_at_connect_not_at_the_first_image(): void
    {
        $c = new SocialConnection;
        $c->forceFill(['provider' => 'x', 'remote_id' => '1', 'status' => SocialConnection::STATUS_CONNECTED,
            'credentials' => ['refresh_token' => 'r', 'access_token' => 'a', 'scopes' => ['tweet.read', 'tweet.write', 'users.read']]]);

        $this->assertSame(['media.write'], ConnectSetup::check($c)['missing_scopes']);
    }

    public function test_mailchimp_connect_chooses_an_audience_and_creates_it_in_newsletter(): void
    {
        $t = $this->provisionTenant('A1-Group');
        Http::fake([
            '*/3.0' => Http::response(['account_name' => 'A-1', 'email' => 'mail@a1.test']),
            '*/3.0/lists*' => Http::response(['lists' => [['id' => 'l-1', 'name' => 'Customers'], ['id' => 'l-2', 'name' => 'Leads']]]),
            '*/3.0/ping' => Http::response(['health_status' => 'Everything\'s Chimpy!']),
        ]);

        $body = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/mailchimp/connect', ['api_key' => 'abc-us14'])
            ->assertCreated()->json('data');

        $this->assertSame('l-1', $body['choices'][0]['selected']);
        $this->assertTrue($body['connect_check']['passed']);
        $this->assertSame('does not expire', $body['connect_check']['token']);
        $this->inTenant($t['org'], fn () => $this->assertSame(1, NewsletterAudience::query()->where('mailchimp_list_id', 'l-1')->count()));
    }

    public function test_mailchimp_with_no_audience_gets_one_created(): void
    {
        $t = $this->provisionTenant('A1-Group');
        Http::fake([
            '*/3.0' => Http::response(['account_name' => 'A-1', 'email' => 'mail@a1.test', 'contact' => ['company' => 'A-1', 'addr1' => '1 Main', 'city' => 'Miami', 'state' => 'FL', 'zip' => '33101', 'country' => 'US']]),
            '*/3.0/lists*' => Http::sequence()->push(['lists' => []])->push(['id' => 'l-new', 'name' => 'A1-Group']),
            '*/3.0/ping' => Http::response([]),
        ]);

        $body = $this->signedInAs($t['admin'])->postJson('/api/v1/newsletter/mailchimp/connect', ['api_key' => 'abc-us14'])
            ->assertCreated()->json('data');

        $this->assertSame('l-new', $body['choices'][0]['selected']);
        $this->inTenant($t['org'], fn () => $this->assertSame(1, NewsletterAudience::query()->where('mailchimp_list_id', 'l-new')->count()));
    }

    public function test_an_expired_linkedin_token_marks_the_row_broken(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $c = $this->inTenant($t['org'], function () use ($t) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => 'linkedin', 'remote_id' => 'p1', 'display_name' => 'Jose']);
            $c->connect(['refresh_token' => '', 'access_token' => 'a', 'expires_at' => now()->subDay()->toIso8601String()]);
            $c->save();

            return $c;
        });

        $this->inTenant($t['org'], function () use ($c) {
            try {
                (new LinkedInPublisher)->token($c);
                $this->fail('An expired token was used.');
            } catch (SocialPublishFailed) {
            }

            $this->assertSame(SocialConnection::STATUS_BROKEN, $c->fresh()->status);
        });
    }

    public function test_linkedin_connect_offers_the_company_pages_too(): void
    {
        Http::fake([
            'api.linkedin.com/v2/userinfo' => Http::response(['sub' => 'p1', 'name' => 'Jose']),
            'api.linkedin.com/v2/organizationAcls*' => Http::response(['elements' => [
                ['organization' => 'urn:li:organization:77', 'organization~' => ['localizedName' => 'A-1 Chimney']],
            ]]),
        ]);

        $accounts = app(LinkedInOAuth::class)->identify(new SocialOAuthGrant('t', scopes: ['w_member_social', 'w_organization_social']));

        $this->assertSame(['p1', 'urn:li:organization:77'], array_map(fn ($a) => $a->remoteId, $accounts));
    }
}
