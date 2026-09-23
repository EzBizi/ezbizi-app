<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\WebSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Reading a customer's pages and posts.
 *
 * This is the one place EzBizi makes an outbound request to an address held in
 * a row, with our own network position — so most of what is asserted here is
 * about WHERE the request goes, not about what comes back.
 */
class WebContentTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{site: array<string, mixed>, editor: User, tenant: array<string, mixed>} */
    private function site(bool $paired = true): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Web Editor', [
            'web_management.view', 'web_management.edit',
            'web_management.seo.view', 'web_management.widgets.view',
        ]);

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => 'a-1chimney.com',
        ])->assertCreated()->json();

        if ($paired) {
            $this->postJson('/api/v1/web/plugin/service-account', [
                'host' => 'a-1chimney.com',
                'user_login' => 'ezbizi-service',
                'password' => 'plain-text-secret',
                'rest_url' => 'https://a-1chimney.com/wp-json/',
                'capabilities' => 'read,edit_posts',
            ], ['Authorization' => 'Bearer '.$created['token']])->assertCreated();
        }

        return ['site' => $created, 'editor' => $editor, 'tenant' => $tenant];
    }

    private function wordpressReturns(array $rows, array $headers = []): void
    {
        Http::fake([
            'a-1chimney.com/*' => Http::response($rows, 200, array_merge([
                'X-WP-Total' => (string) count($rows),
                'X-WP-TotalPages' => '1',
            ], $headers)),
        ]);
    }

    public function test_it_lists_the_pages_the_website_holds(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->wordpressReturns([
            [
                'id' => 12, 'slug' => 'services', 'status' => 'publish', 'type' => 'page',
                'link' => 'https://a-1chimney.com/services', 'modified' => '2026-09-10T11:00:00',
                'title' => ['rendered' => 'Mum &amp; Dad'],
            ],
        ]);

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertOk()
            ->json();

        $this->assertSame(1, $body['total']);
        $this->assertSame(12, $body['items'][0]['id']);
        $this->assertSame('/services', '/'.$body['items'][0]['slug']);

        // Decoded once here rather than in the client, where forgetting it
        // prints the entity to a person.
        $this->assertSame('Mum & Dad', $body['items'][0]['title']);
    }

    /** The one word that differs between the two tabs reaches WordPress. */
    public function test_posts_and_pages_ask_for_different_things(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $this->wordpressReturns([]);

        foreach (['page' => 'wp/v2/pages', 'post' => 'wp/v2/posts'] as $type => $path) {
            $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=$type")
                ->assertOk();

            Http::assertSent(fn ($request) => str_contains($request->url(), $path));
        }
    }

    /**
     * The request carries the account the SITE handed us, as Basic auth.
     *
     * Without this the endpoint would read only what is public, which is a
     * different list from the one in wp-admin — and wp-admin is the list
     * somebody is comparing it to.
     */
    public function test_it_uses_the_account_the_site_gave_us_and_asks_for_drafts(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $this->wordpressReturns([]);

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertOk();

        /*
         * SCOPED TO THE PAGES REQUEST, because listing pages now makes two:
         * the authors are asked for first, to find the accounts this site files
         * city pages under so they can be left out of this list (`PageKinds`).
         *
         * `Http::assertSent` runs its callback against every recorded request,
         * so an unscoped assertion about `draft` fails on the authors call —
         * which is what it did, correctly, when the second request appeared.
         */
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'wp/v2/pages')) {
                return false;
            }

            $this->assertSame(
                'Basic '.base64_encode('ezbizi-service:plain-text-secret'),
                $request->header('Authorization')[0] ?? '',
            );

            $this->assertStringContainsString('draft', urldecode($request->url()));

            // The rendered body of a post runs to megabytes and nothing here
            // draws it.
            $this->assertStringNotContainsString('content', urldecode($request->url()));

            return true;
        });

        // And the account is on the authors call too — it is the same
        // credential reaching the same site, and a public read would return a
        // different list of authors from the one wp-admin shows.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'wp/v2/users')
            && ($request->header('Authorization')[0] ?? '')
                === 'Basic '.base64_encode('ezbizi-service:plain-text-secret'));
    }

    /**
     * THE one that matters: a stored address pointing elsewhere must not become
     * an outbound request from our server.
     *
     * The write path checks this too. It is checked again because the check
     * that counts is the one standing between a stored string and a socket.
     */
    public function test_an_address_on_another_host_is_never_fetched(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        Http::fake();

        $this->inTenant($tenant['org'], function () use ($site) {
            WebSite::findOrFail($site['data']['id'])->forceFill([
                'service_rest_url' => 'https://attacker.example.com/wp-json/',
            ])->save();
        });

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertStatus(502);

        Http::assertNothingSent();
    }

    /** A site that never paired is told what to do, not shown an error. */
    public function test_an_unpaired_site_says_what_is_missing(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site(paired: false);

        Http::fake();

        $response = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertStatus(502);

        $this->assertStringContainsString('a-1chimney.com', $response->json('message'));
        Http::assertNothingSent();
    }

    /**
     * A refused account names the likely cause. 502, not 500: nothing here is
     * broken — a machine we do not run gave an answer we cannot use.
     */
    public function test_a_refused_account_says_so(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        Http::fake(['a-1chimney.com/*' => Http::response([], 401)]);

        $response = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertStatus(502);

        $this->assertStringContainsString('refused', $response->json('message'));
    }

    public function test_a_viewer_can_read_and_a_stranger_cannot(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();
        $this->wordpressReturns([]);

        $viewer = $this->userWithRole($tenant, 'Web Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

        $this->signedInAs($viewer)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertOk();

        $other = $this->provisionTenant('Beta');
        $stranger = $this->userWithRole($other, 'Web Editor', [
            'web_management.view', 'web_management.edit',
            'web_management.seo.view', 'web_management.widgets.view',
        ]);

        $this->signedInAs($stranger)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertNotFound();
    }

    public function test_a_type_nobody_offers_is_refused_before_any_request(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        Http::fake();

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=attachment")
            ->assertStatus(422);

        Http::assertNothingSent();
    }
}
