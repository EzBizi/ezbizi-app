<?php

namespace Tests\Feature\Leads;

use App\Models\BusinessLocation;
use App\Models\SocialConnection;
use App\Models\WhatsAppNumber;
use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The WhatsApp button on every listing (desk, 2026-10-30): preview each listing's, then put the organization's on the chosen ones. */
class BusinessProfileWhatsAppLinkTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_preview_says_which_listings_lack_the_link_and_apply_writes_only_the_chosen(): void
    {
        $t = $this->provisionTenant();
        [$same, $missing, $other, $orphan] = $this->inTenant($t['org'], function () use ($t) {
            WhatsAppNumber::create(['organization_id' => $t['org']->id, 'phone_number_id' => '1', 'waba_id' => '2', 'display_number' => '+1 (937) 555-0100']);
            $c = new SocialConnection;
            $c->forceFill(['organization_id' => $t['org']->id, 'scope_type' => 'organization', 'scope_id' => $t['org']->id,
                'provider' => SocialProvider::GoogleBusinessProfile->value, 'remote_id' => 'accounts/P', 'display_name' => 'Co']);
            $c->connect(['refresh_token' => 'rt', 'client_id' => 'c', 'client_secret' => 's']);
            $c->save();
            $make = fn (string $n, ?string $conn) => BusinessLocation::create(['organization_id' => $t['org']->id, 'company_id' => $t['company']->id,
                'connection_id' => $conn, 'remote_name' => 'locations/'.$n, 'title' => $n]);

            return [$make('1', $c->id), $make('2', $c->id), $make('3', $c->id), $make('4', null)];
        });
        $links = ['1' => 'https://wa.me/19375550100', '3' => 'https://wa.me/15550000000'];
        Http::fake(fn (Request $r) => match (true) {
            str_contains($r->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            $r->method() === 'GET' && preg_match('#locations/(\d)/attributes#', $r->url(), $m) === 1 => Http::response(['attributes' => isset($links[$m[1]])
                ? [['name' => 'attributes/url_whatsapp', 'valueType' => 'URL', 'uriValues' => [['uri' => $links[$m[1]]]]]] : []]),
            $r->method() === 'PATCH' => Http::response([]),
            default => Http::response([], 404),
        });
        $this->signedInAs($t['admin']);

        $preview = $this->getJson('/api/v1/business-profile/whatsapp-links')->assertOk()->assertJsonPath('proposed', 'https://wa.me/19375550100');
        $this->assertSame([$same->id => 'same', $missing->id => 'missing', $other->id => 'different', $orphan->id => 'unreachable'],
            collect($preview->json('data'))->pluck('state', 'id')->all());

        $this->postJson('/api/v1/business-profile/whatsapp-links/apply', ['targets' => [$missing->id]])->assertConflict();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');

        config(['services.google_business_profile.write' => true]);
        $this->postJson('/api/v1/business-profile/whatsapp-links/apply', ['targets' => [$missing->id]])->assertOk()->assertJsonPath('data.0.ok', true);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), 'locations/2/attributes?attributeMask=attributes/url_whatsapp')
            && $r['attributes'][0]['uriValues'][0]['uri'] === 'https://wa.me/19375550100');
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'PATCH'), 'the other listings are left alone');

        // Several numbers: the sheet lists them and the chosen one is written, one listing at a time too (Jose, 2026-11-02).
        $this->inTenant($t['org'], fn () => WhatsAppNumber::create(['organization_id' => $t['org']->id, 'phone_number_id' => '3', 'waba_id' => '2', 'display_number' => '+1 (877) 555-0199']));
        $this->getJson('/api/v1/business-profile/whatsapp-links')->assertOk()->assertJsonPath('numbers.1.link', 'https://wa.me/18775550199');
        $this->postJson('/api/v1/business-profile/whatsapp-links/apply', ['targets' => [$other->id], 'link' => 'https://wa.me/18775550199'])
            ->assertOk()->assertJsonPath('data.0.ok', true);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_contains($r->url(), 'locations/3/attributes')
            && $r['attributes'][0]['uriValues'][0]['uri'] === 'https://wa.me/18775550199');
        $this->postJson('/api/v1/business-profile/whatsapp-links/apply', ['targets' => [$other->id], 'link' => 'https://evil.example/x'])->assertUnprocessable();
    }
}
