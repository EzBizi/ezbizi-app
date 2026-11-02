<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-02: every Google connection names the Google login it was signed in with. */
class GoogleConnectionEmailTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

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
}
