<?php

namespace Tests\Feature\Api;

use App\Support\Social\SocialProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Connecting an account is gated by the module that USES it.
 *
 * This started as a real defect: both Google providers were gated on
 * `marketing.edit`, inherited from the nine networks they share a controller
 * with. That meant somebody holding every `leads.*` permission could not
 * connect the Google Ads account the Leads module runs on, while somebody with
 * Marketing and no access to Leads at all could. Nobody posts as a Google Ads
 * account; the person who can tell whether it is the right one is the person
 * who reads it.
 */
class GoogleConnectPermissionTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_nine_publishing_networks_still_answer_to_marketing(): void
    {
        foreach (SocialProvider::all() as $provider) {
            $this->assertSame(
                'marketing.edit',
                $provider->connectPermission(),
                "{$provider->value} is a place a tenant publishes and must stay with Marketing.",
            );
        }
    }

    public function test_each_google_account_answers_to_the_module_that_reads_it(): void
    {
        $this->assertSame('leads.edit', SocialProvider::GoogleAds->connectPermission());
        $this->assertSame(
            'reviews.edit',
            SocialProvider::GoogleBusinessProfile->connectPermission(),
        );
    }

    public function test_marketing_alone_cannot_connect_a_google_ads_account(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $poster = $this->userWithRole($t, 'Poster', ['marketing.view', 'marketing.edit']);

        $this->signedInAs($poster)
            ->postJson('/api/v1/social/google-ads/start')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn (string $m) => str_contains($m, 'connect a Google Ads account'),
            );
    }

    /**
     * The half that was actually broken.
     *
     * Holding the Leads module and being unable to connect its account was the
     * bug; this is what says it stays fixed. The 501 is the driver reporting
     * this installation has no Google Ads registration — which is a different
     * answer from 403, and the point is that it got past the permission.
     */
    public function test_the_leads_module_can_connect_its_own_account(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $runner = $this->userWithRole($t, 'Leads', ['leads.view', 'leads.edit']);

        $response = $this->signedInAs($runner)
            ->postJson('/api/v1/social/google-ads/start');

        // Asserting "not 403" alone was the mistake that hid this: the route
        // did not exist, every call 404'd, and the test passed anyway. 501 is
        // the driver saying this installation has no Google Ads registration,
        // which is a real answer from the other side of the permission check.
        $this->assertContains(
            $response->status(),
            [200, 501],
            'Somebody who runs Leads must reach the connect flow for the account Leads reads. '
            .'A 404 here means the route is missing, not that permission was refused.',
        );
    }

    public function test_the_reviews_module_can_connect_its_own_profile(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $runner = $this->userWithRole($t, 'Reviews', ['reviews.view', 'reviews.edit']);

        $response = $this->signedInAs($runner)
            ->postJson('/api/v1/social/business-profile/start');

        $this->assertContains($response->status(), [200, 501]);
    }

    /**
     * Every provider has a route, and the list cannot drift from the enum.
     *
     * The original defect was three hardcoded copies of eight network names in
     * routes/api.php. A provider added to the enum and forgotten there loads,
     * reports READY, resolves its config — and 404s on every request.
     */
    public function test_every_oauth_provider_has_a_reachable_route(): void
    {
        $pattern = SocialProvider::oauthRoutePattern();

        foreach (SocialProvider::cases() as $provider) {
            if ($provider === SocialProvider::Bluesky) {
                continue;   // handle + app password, its own route
            }

            $this->assertMatchesRegularExpression(
                '/^(?:'.$pattern.')$/',
                $provider->routeKey(),
                "{$provider->value} has no route: /social/{$provider->routeKey()}/start would 404.",
            );
        }
    }

    public function test_leads_edit_does_not_leak_into_connecting_a_social_page(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $runner = $this->userWithRole($t, 'Leads', ['leads.view', 'leads.edit']);

        $this->signedInAs($runner)
            ->postJson('/api/v1/social/tiktok/start')
            ->assertForbidden();
    }
}
