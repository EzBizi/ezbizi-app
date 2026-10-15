<?php

namespace Tests\Feature\Api;

use App\Models\SocialConnection;
use App\Support\Social\SocialOAuthDrivers;
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
        $this->assertSame('leads.manage', SocialProvider::GoogleAds->connectPermission());
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
        $runner = $this->userWithRole($t, 'Leads', ['leads.view', 'leads.manage']);

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
    /**
     * Disconnecting answers to the same module as connecting.
     *
     * This was the other half of the defect in this file's opening note, and it
     * outlived the fix. `connectPermission()` moved the two Google providers to
     * the modules that read them, but `SocialConnectionsController::destroy`
     * kept one blanket `marketing.edit` for every provider it can delete. So a
     * Leads manager could connect a Google Ads account and then not remove it,
     * while somebody with no access to Leads at all could.
     *
     * Jose hit exactly this: connected the wrong account by mistake and had to
     * go to Marketing to undo it, because the tab that owns the connection
     * cannot.
     */
    public function test_the_module_that_can_connect_an_account_can_also_disconnect_it(): void
    {
        $t    = $this->provisionTenant('A1-Group');
        $lead = $this->userWithRole($t, 'Lead desk', ['leads.view', 'leads.manage']);

        $row = $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'provider'        => SocialProvider::GoogleAds->value,
            'remote_id'       => '7061094046',
            'display_name'    => '706-109-4046',
        ]));

        $this->signedInAs($lead)
            ->deleteJson('/api/v1/social/connections/'.$row->getKey())
            ->assertOk();

        $this->assertNull(
            $this->inTenant($t['org'], fn () => SocialConnection::find($row->getKey())),
            'The grant must actually be gone. The compliance page tells Google '
            .'a customer may disconnect at any time and the grant is deleted.',
        );
    }

    public function test_a_reviews_manager_disconnects_the_business_profile_and_not_a_social_page(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $review = $this->userWithRole($t, 'Reviews desk', ['reviews.view', 'reviews.edit']);

        $make = fn (SocialProvider $p) => $this->inTenant($t['org'], fn () => SocialConnection::create([
            'organization_id' => $t['org']->id,
            'scope_type'      => SocialConnection::SCOPE_ORGANIZATION,
            'scope_id'        => $t['org']->id,
            'provider'        => $p->value,
            'remote_id'       => 'r-'.$p->value,
            'display_name'    => $p->value,
        ]));

        $profile = $make(SocialProvider::GoogleBusinessProfile);
        $page    = $make(SocialProvider::Pinterest);

        $this->signedInAs($review)
            ->deleteJson('/api/v1/social/connections/'.$profile->getKey())
            ->assertOk();

        // The same permission must NOT reach a publishing account. Widening the
        // gate per provider is only safe if it stays per provider.
        $this->signedInAs($review)
            ->deleteJson('/api/v1/social/connections/'.$page->getKey())
            ->assertForbidden();
    }

    public function test_every_oauth_provider_has_a_reachable_route(): void
    {
        $pattern = SocialProvider::oauthRoutePattern();

        foreach (SocialProvider::cases() as $provider) {
            // Same property as the driver guard below: a provider that does
            // not connect by OAuth has its own endpoint and must be absent from
            // this pattern, not present in it.
            if (! $provider->connectsByOauth()) {
                continue;
            }

            $this->assertMatchesRegularExpression(
                '/^(?:'.$pattern.')$/',
                $provider->routeKey(),
                "{$provider->value} has no route: /social/{$provider->routeKey()}/start would 404.",
            );
        }
    }

    /**
     * Every provider resolves to a driver, and the two agree on the route key.
     *
     * The OTHER half of the half-registered provider, and the half nothing was
     * checking. A case with no route 404s and the route test above catches it.
     * A case with no DRIVER passes that test — the route exists — and then
     * fails at resolve time, in a request, for a provider a test suite that
     * does not know it should exist will never ask about.
     *
     * The route-key agreement matters for the same reason: `byRouteKey()` is
     * populated from `$driver->routeKey()` while the ROUTE is generated from
     * `SocialProvider::routeKey()`. Nothing forces those to be the same string,
     * so a driver answering 'google_ads' while its enum case says 'google-ads'
     * registers a route nothing can resolve. Both sides read from the enum
     * today; this is what keeps it that way.
     *
     * Providers that do NOT connect by OAuth are exempt, and the exemption is
     * read off `connectsBy()` rather than named here. It said "Bluesky, and it
     * is the only one" until Mailchimp arrived on 2026-09-04 — at which point a
     * list of names would have had to be found and edited in three places, one
     * of them a route pattern that would have registered a `/start` path
     * resolving to no driver. A property cannot be forgotten in two of three.
     */
    public function test_no_provider_is_half_registered(): void
    {
        $drivers = app(SocialOAuthDrivers::class);

        foreach (SocialProvider::cases() as $provider) {
            if (! $provider->connectsByOauth()) {
                $this->assertNull(
                    $drivers->for($provider),
                    "{$provider->value} connects by {$provider->connectsBy()} and must not "
                    .'gain an OAuth driver.',
                );

                continue;
            }

            $driver = $drivers->for($provider);

            $this->assertNotNull(
                $driver,
                "{$provider->value} is in the enum with no driver registered. Its route "
                .'exists, so nothing 404s — it fails when somebody presses Connect.',
            );

            $this->assertSame(
                $provider->routeKey(),
                $driver->routeKey(),
                "{$provider->value} disagrees with its driver about its route key. The "
                .'route is generated from the enum and the registry is keyed from the '
                .'driver, so a mismatch registers a route nothing resolves.',
            );
        }
    }

    /**
     * Every data source answers to the module that reads it, not to Marketing.
     *
     * Written over `dataSources()` rather than naming Google Ads and Business
     * Profile, because Thumbtack arrived in these files without me and the next
     * one will too. A provider added as a data source and left on the default
     * `marketing.edit` arm is a permission bug that looks like a working
     * connection.
     */
    public function test_no_data_source_is_left_on_marketing(): void
    {
        foreach (SocialProvider::dataSources() as $provider) {
            $this->assertNotSame(
                'marketing.edit',
                $provider->connectPermission(),
                "{$provider->value} is a data source — EzBizi reads it, nobody posts as "
                .'it — so Marketing is the wrong authority for connecting it.',
            );

            $this->assertFalse(
                $provider->isPublishingNetwork(),
                "{$provider->value} is in dataSources() but still reports as a publishing "
                .'network, so it would appear in the Marketing catalogue.',
            );
        }
    }

    public function test_leads_edit_does_not_leak_into_connecting_a_social_page(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $runner = $this->userWithRole($t, 'Leads', ['leads.view', 'leads.edit', 'leads.manage']);

        $this->signedInAs($runner)
            ->postJson('/api/v1/social/tiktok/start')
            ->assertForbidden();
    }
}
