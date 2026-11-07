<?php

namespace Tests\Feature\Social;

use App\Models\SocialConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/**
 * Design 183 (Jose, 2026-11-05): "when for example i click Meta - Facebook it
 * asks me what connections from facebook i want to make, before proceeding".
 */
class MetaProductsTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    private function row(array $t, string $provider, string $remote, array $scopes = [], string $token = 'page-token'): SocialConnection
    {
        return $this->inTenant($t['org'], function () use ($t, $provider, $remote, $scopes, $token) {
            $row = new SocialConnection;
            $row->forceFill([
                'organization_id' => $t['org']->id,
                'scope_type' => 'organization',
                'scope_id' => $t['org']->id,
                'provider' => $provider,
                'remote_id' => $remote,
                'display_name' => $remote,
            ]);
            $row->connect(['access_token' => $token, 'scopes' => $scopes]);
            $row->save();

            return $row;
        });
    }

    private function products(array $t): array
    {
        return collect($this->signedInAs($t['admin'])->getJson('/api/v1/social/providers?include=data_sources')
            ->assertOk()->json('data'))->firstWhere('value', 'facebook_page')['products'];
    }

    public function test_facebook_lists_its_products_and_which_this_view_has(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $this->row($t, 'facebook_page', 'page-1', ['pages_show_list', 'pages_manage_posts']);

        $products = collect($this->products($t))->keyBy('key');

        $this->assertSame(['pages', 'messenger', 'instagram', 'ads', 'whatsapp'], $products->keys()->all());
        $this->assertTrue($products['pages']['connected']);
        // Unticked on Meta's screen: the Page is there, Messenger is not.
        $this->assertFalse($products['messenger']['connected']);
        $this->assertFalse($products['ads']['connected']);
        $this->assertSame('/social/whatsapp/start', $products['whatsapp']['start_path']);
    }

    public function test_the_sign_in_asks_only_for_the_chosen_products(): void
    {
        config(['services.meta.client_id' => 'app-id', 'services.meta.client_secret' => 'app-secret']);
        $t = $this->provisionTenant('A1-Group');

        $url = $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start', ['products' => ['ads']])
            ->assertOk()->json('data.authorization_url');

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $scopes = explode(',', $query['scope']);
        $this->assertContains('ads_management', $scopes);
        $this->assertNotContains('pages_manage_posts', $scopes);
        $this->assertNotContains('pages_messaging', $scopes);
        $this->assertSame('rerequest', $query['auth_type']);

        $this->signedInAs($t['admin'])
            ->postJson('/api/v1/social/facebook/start', ['products' => ['nonsense']])
            ->assertUnprocessable();
    }

    public function test_removing_ad_accounts_keeps_the_pages_and_takes_the_ads_permissions_back(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->provisionTenant('A1-Group');
        $page = $this->row($t, 'facebook_page', 'page-1');
        $ads = $this->row($t, 'facebook_ads', 'act_1', token: 'user-token');

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/facebook_page/connections?product=ads', $code)
            ->assertOk()->assertJsonPath('data.disconnected', 1);

        $this->assertTrue($this->inTenant($t['org'], fn () => SocialConnection::query()->whereKey($page->id)->exists()));
        $this->assertFalse($this->inTenant($t['org'], fn () => SocialConnection::query()->whereKey($ads->id)->exists()));
        foreach (['ads_read', 'ads_management', 'leads_retrieval'] as $scope) {
            Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/me/permissions/'.$scope)
                && $r['access_token'] === 'user-token');
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'pages_show_list'));
    }

    public function test_removing_messenger_keeps_the_page_and_says_messenger_is_gone(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
        $t = $this->provisionTenant('A1-Group');
        $page = $this->row($t, 'facebook_page', 'page-1');
        $this->row($t, 'facebook_ads', 'act_1', token: 'user-token');

        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])
            ->deleteJson('/api/v1/social/providers/facebook_page/connections?product=messenger', $code)
            ->assertOk()->assertJsonPath('data.product', 'messenger');

        $this->assertTrue($this->inTenant($t['org'], fn () => SocialConnection::query()->whereKey($page->id)->exists()));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/me/permissions/pages_messaging'));
        $products = collect($this->products($t))->keyBy('key');
        $this->assertFalse($products['messenger']['connected']);
        $this->assertTrue($products['pages']['connected']);
    }
}
