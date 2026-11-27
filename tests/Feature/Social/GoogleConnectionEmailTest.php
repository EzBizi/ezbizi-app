<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/** Jose, 2026-11-02: every Google connection names the Google login it was signed in with. */
class GoogleConnectionEmailTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Connect now calls the provider to check it (ConnectSetup): nothing unfaked may leave.
        Http::preventStrayRequests();
    }

    private function idToken(string $email): string
    {
        return 'h.'.rtrim(strtr(base64_encode(json_encode(['email' => $email])), '+/', '-_'), '=').'.s';
    }

    public function test_connecting_records_the_login_and_integrations_lists_it(): void
    {
        Bus::fake();
        config([
            'services.business_profile.client_id' => 'gbp-id',
            'services.business_profile.client_secret' => 'gbp-secret',
            'services.business_profile.redirect' => 'https://app.ezbizi.test/api/v1/social/business-profile/callback',
        ]);
        $t = $this->provisionTenant('A1-Group');
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'id_token' => $this->idToken('Info@EverDoors.com')]),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/1', 'accountName' => 'EverDoors'], ['name' => 'accounts/2', 'accountName' => 'Roofirst']]]),
        ]);

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/business-profile/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertContains('email', explode(' ', $q['scope']));
        auth()->forgetGuards();
        $this->get('/api/v1/social/business-profile/callback?code=c&state='.$q['state'])->assertOk();

        $rows = $this->signedInAs($t['admin'])->getJson('/api/v1/social/connections?include=data_sources')->assertOk()->json('data');
        $this->assertSame(['info@everdoors.com', 'info@everdoors.com'], array_column($rows, 'account_email'));
    }

    public function test_the_backfill_asks_once_per_grant_and_leaves_an_unknown_login_null(): void
    {
        config(['services.business_profile.client_id' => 'gbp-id', 'services.business_profile.client_secret' => 's']);
        $t = $this->provisionTenant();
        $make = fn (string $remote, string $refresh) => $this->inTenant($t['org'], function () use ($t, $remote, $refresh) {
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
                'provider' => 'google_business_profile', 'remote_id' => $remote, 'display_name' => $remote]);
            $c->connect(['refresh_token' => $refresh]);
            $c->save();

            return $c;
        });
        $make('1', 'with-email');
        $make('2', 'with-email');
        $make('3', 'without');
        Http::fake(fn (Request $r) => str_contains($r->url(), 'tokeninfo')
            ? Http::response(str_contains($r->url(), 'at-with-email') ? ['email' => 'Chaim@x.com'] : ['scope' => 'business.manage'])
            : Http::response(['access_token' => 'at-'.$r['refresh_token']]));

        $this->artisan('ezbizi:google-connection-emails')->assertSuccessful();

        $this->assertSame(['chaim@x.com', 'chaim@x.com', null], SocialConnection::withoutGlobalScopes()->orderBy('remote_id')->pluck('account_email')->all());
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_contains($r->url(), 'tokeninfo')));
    }

    public function test_disconnect_all_takes_one_logins_rows_and_unknown_is_its_own_set(): void
    {
        $t = $this->provisionTenant();
        foreach ([['1', 'a@x.com'], ['2', 'a@x.com'], ['3', 'b@x.com'], ['4', null]] as [$remote, $email]) {
            $this->inTenant($t['org'], function () use ($t, $remote, $email) {
                $c = new SocialConnection;
                $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION, 'scope_id' => $t['org']->id,
                    'provider' => 'google_business_profile', 'remote_id' => $remote, 'display_name' => $remote, 'account_email' => $email]);
                $c->connect(['refresh_token' => 'r']);
                $c->save();
            });
        }
        Http::fake();
        $left = fn () => SocialConnection::withoutGlobalScopes()->orderBy('remote_id')->pluck('remote_id')->all();

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/providers/google_business_profile/connections',
            $code + ['account_email' => 'a@x.com'])->assertOk()->assertJsonPath('data.disconnected', 2);
        $this->assertSame(['3', '4'], $left());

        $this->travel(31)->seconds();
        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/providers/google_business_profile/connections',
            $code + ['account_email' => null])->assertOk()->assertJsonPath('data.disconnected', 1);
        $this->assertSame(['3'], $left());
    }

    /** 2026-11-03: info@a-1chimney.com reaches 35 accounts; only the first page of 20 was connected. */
    public function test_connecting_takes_every_page_of_accounts(): void
    {
        Bus::fake();
        config([
            'services.business_profile.client_id' => 'gbp-id',
            'services.business_profile.client_secret' => 'gbp-secret',
            'services.business_profile.redirect' => 'https://app.ezbizi.test/api/v1/social/business-profile/callback',
        ]);
        $t = $this->provisionTenant('A1-Group');
        Http::fake(function (Request $r) {
            if (str_contains($r->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'a', 'refresh_token' => 'r']);
            }

            return str_contains($r->url(), 'pageToken=p2')
                ? Http::response(['accounts' => [['name' => 'accounts/21', 'accountName' => 'A1 Chimney - Pittsburgh']]])
                : Http::response(['accounts' => array_map(fn ($i) => ['name' => "accounts/$i", 'accountName' => "A1 $i"], range(1, 20)), 'nextPageToken' => 'p2']);
        });

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/business-profile/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        auth()->forgetGuards();
        $this->get('/api/v1/social/business-profile/callback?code=c&state='.$q['state'])->assertOk();

        $this->assertSame(21, SocialConnection::withoutGlobalScopes()->where('provider', 'google_business_profile')->count());
        $this->assertTrue(SocialConnection::withoutGlobalScopes()->where('remote_id', '21')->exists());
    }
}
