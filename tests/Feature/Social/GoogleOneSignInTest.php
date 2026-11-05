<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Jose, 2026-11-05: one Google sign-in brings Business Profile, Ads and
 * YouTube, chosen before it starts; one disconnect removes them (design 183).
 */
class GoogleOneSignInTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    private function configure(): void
    {
        Bus::fake();
        config([
            'services.google_ads.client_id' => 'ads-id',
            'services.google_ads.client_secret' => 'ads-secret',
            'services.google_ads.developer_token' => 'dev',
            'services.google_ads.redirect' => 'https://app.ezbizi.test/api/v1/social/google-ads/callback',
        ]);
    }

    public function test_the_catalogue_lists_the_products_and_a_choice_asks_only_for_its_scopes(): void
    {
        $this->configure();
        $t = $this->provisionTenant('A1-Group');

        $catalogue = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/providers?include=data_sources')->assertOk()->json('data'));
        $google = $catalogue->firstWhere('value', 'google_ads');
        $this->assertSame(['business_profile', 'google_ads', 'youtube', 'gmail'], array_column($google['products'], 'key'));
        $this->assertSame([false, false, false, false], array_column($google['products'], 'connected'));
        $this->assertSame('google_ads', $catalogue->firstWhere('value', 'youtube')['signed_in_with']);

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/google-ads/start', ['products' => ['youtube', 'gmail']])
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $asked = explode(' ', $q['scope']);
        $this->assertContains('https://www.googleapis.com/auth/youtube.force-ssl', $asked);
        $this->assertNotContains('https://www.googleapis.com/auth/adwords', $asked);
        $this->assertEmpty(preg_grep('/gmail/', $asked), 'never a Gmail scope');
        $this->assertSame('true', $q['include_granted_scopes']);

        // Gmail alone is not a sign-in: it is Workspace, in Email.
        $this->signedInAs($t['admin'])->postJson('/api/v1/social/google-ads/start', ['products' => ['gmail']])->assertStatus(422);
    }

    public function test_one_sign_in_connects_what_was_ticked_and_disconnects_one_product_or_all(): void
    {
        $this->configure();
        $t = $this->provisionTenant('A1-Group');
        $email = 'h.'.rtrim(strtr(base64_encode(json_encode(['email' => 'info@everdoors.com'])), '+/', '-_'), '=').'.s';
        // Ads unticked on the consent screen: Business Profile and YouTube left on.
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'id_token' => $email,
                'scope' => 'https://www.googleapis.com/auth/business.manage https://www.googleapis.com/auth/youtube.upload openid email']),
            'mybusinessaccountmanagement.googleapis.com/*' => Http::response(['accounts' => [['name' => 'accounts/1', 'accountName' => 'EverDoors']]]),
            'www.googleapis.com/youtube/*' => Http::response(['items' => [['id' => 'UC1', 'snippet' => ['title' => 'EverDoors TV']]]]),
        ]);

        $url = $this->signedInAs($t['admin'])->postJson('/api/v1/social/google-ads/start')->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        auth()->forgetGuards();
        $this->get('/api/v1/social/google-ads/callback?code=c&state='.$q['state'])->assertOk();

        $rows = SocialConnection::withoutGlobalScopes()->orderBy('provider')->get();
        $this->assertSame(['google_business_profile', 'youtube'], $rows->pluck('provider')->all());
        $this->assertSame(['google_ads', 'google_ads'], $rows->map(fn ($c) => $c->credentials['oauth_service'] ?? null)->all());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'googleads.googleapis.com'));

        $products = collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/providers?include=data_sources')->json('data'))
            ->firstWhere('value', 'google_ads')['products'];
        $this->assertSame([true, false, true, false], array_column($products, 'connected'));

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/providers/google_ads/connections',
            $code + ['account_email' => 'info@everdoors.com', 'product' => 'youtube'])
            ->assertOk()->assertJsonPath('data.disconnected', 1);
        $this->assertSame(['google_business_profile'], SocialConnection::withoutGlobalScopes()->pluck('provider')->all());

        $this->travel(31)->seconds();
        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson('/api/v1/social/providers/google_ads/connections',
            $code + ['account_email' => 'info@everdoors.com'])
            ->assertOk()->assertJsonPath('data.disconnected', 1);
        $this->assertSame(0, SocialConnection::withoutGlobalScopes()->count());
    }
}
