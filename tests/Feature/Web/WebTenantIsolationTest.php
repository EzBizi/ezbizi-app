<?php

namespace Tests\Feature\Web;

use App\Models\Lead;
use App\Models\User;
use App\Models\WebChat;
use App\Models\WebSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * What `TenantContext` does NOT do for Web Management.
 *
 * ── Why this file exists ──
 *
 * Every other query in this module is scoped for free: `BelongsToTenant` adds
 * the organization to the read, and code that forgets is caught by the write
 * guard. Six lookups in this module deliberately step outside that, because
 * they run before any tenant is known — the caller is a WordPress plugin, a
 * visitor's browser, or a stranger's `fetch`, none of which has signed in.
 * In each, a KEY is the identity:
 *
 *   PluginController      WebSite by token_hash      (the plugin's token)
 *   PublicValuesController WebSite by public_key     (the snippet's key)
 *   EnquiriesController   WebSite by public_key      (the widget's key)
 *   EnquiriesController   WebChat by id + token_hash (the visitor's token)
 *
 * An earlier draft of this comment said the isolation on those paths is
 * "guaranteed by nothing but the unguessability of a key". That understated
 * it, and the UI/UX session caught it while verifying this file. `TenantScope`
 * does not silently pass when no tenant is bound — it THROWS, naming
 * `runWithoutTenant()` and `withoutGlobalScopes()` as the ways to say you meant
 * it. So an unscoped read here cannot happen by accident: it has to be written
 * on purpose, and it leaves a greppable word behind when it is.
 *
 * What remains true, and is why this file exists: on those six lookups the key
 * IS the boundary, and after it the `run($organization)` has to name the right
 * organization. Neither of those is enforced by the scope, because by
 * construction the scope has been stood down. The cases below hold them.
 *
 * ── Why it is worth writing even though nothing is known to be wrong ──
 *
 * A scoping failure is invisible in a way an event gap is not. A missing event
 * surfaces as an automation that never fires and somebody eventually complains;
 * a row shown to the wrong organization surfaces as nothing at all, because the
 * person seeing it does not know they were not meant to. Suggested by the UI/UX
 * session on 2026-09-08, generalising the shape of a real defect in this module:
 * the delegated half works, so the whole thing reads as handled.
 */
class WebTenantIsolationTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /**
     * A visitor's token opens their conversation and no other organization's.
     *
     * The chat id is a UUID in the URL and the token is a bearer header, and
     * NEITHER is filtered by organization — the lookup runs outside any tenant
     * by necessity. If the pairing of the two were ever loosened, this is the
     * test that fails.
     */
    public function test_a_visitor_token_cannot_read_another_organizations_chat(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $mine = $this->startChat($alpha['site']->public_key, 'Alpha customer.');
        $theirs = $this->startChat($beta['site']->public_key, 'Beta customer.');

        // The id of a chat in another organization, and a real token for mine.
        $this->withToken($mine->json('token'))
            ->getJson('/api/v1/web/chat/'.$theirs->json('chat').'/messages')
            ->assertUnauthorized();

        // And the reverse, so a pass cannot come from one direction only.
        $this->withToken($theirs->json('token'))
            ->getJson('/api/v1/web/chat/'.$mine->json('chat').'/messages')
            ->assertUnauthorized();
    }

    /** Nor write into it. Reading is not the only thing a token could do. */
    public function test_a_visitor_token_cannot_post_into_another_organizations_chat(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $mine = $this->startChat($alpha['site']->public_key, 'Alpha customer.');
        $theirs = $this->startChat($beta['site']->public_key, 'Beta customer.');

        $this->withToken($mine->json('token'))
            ->postJson('/api/v1/web/chat/'.$theirs->json('chat').'/messages', [
                'message' => 'Should not land.',
            ])
            ->assertUnauthorized();

        $count = $this->inTenant(
            $beta['tenant']['org'],
            fn () => WebChat::query()->firstOrFail()->lead->conversations()->count(),
        );

        $this->assertSame(1, $count, 'Only the visitor’s own opening message.');
    }

    /**
     * A plugin token reads its own site's values and cannot name another's.
     *
     * The token resolves the site outside any tenant, so nothing but the token
     * itself decides which organization's business details come back.
     */
    public function test_a_plugin_token_cannot_read_another_organizations_values(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $this->setValue($alpha, 'phone', '555-0100');
        $this->setValue($beta, 'phone', '555-0999');

        $body = $this->withToken($alpha['token'])
            ->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')
            ->assertOk()
            ->json();

        $this->assertSame('555-0100', $body['values']['phone']);

        // Asking with Alpha's token for Beta's host is refused outright rather
        // than answered with either site's values.
        $this->withToken($alpha['token'])
            ->getJson('/api/v1/web/plugin/values?host=beta-example.com')
            ->assertUnauthorized();
    }

    /**
     * A public key returns its own site's published values and no other's.
     *
     * This one is scraped from page source by design, so the whole protection
     * is that a key names exactly one site.
     */
    public function test_a_public_key_returns_only_its_own_sites_values(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $this->setValue($alpha, 'phone', '555-0100');
        $this->setValue($beta, 'phone', '555-0999');

        $body = $this->getJson('/api/v1/web/public/values?key='.$beta['site']->public_key)
            ->assertOk()
            ->json();

        $this->assertSame('555-0999', $body['values']['phone']);
        $this->assertStringNotContainsString('555-0100', json_encode($body));
    }

    /**
     * An enquiry lands in the organization whose key was used, whatever else
     * the caller says.
     *
     * The body is a stranger's JSON. Nothing in it may influence placement.
     */
    public function test_the_key_decides_placement_and_the_caller_cannot(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $this->postJson('/api/v1/web/chat', [
            'key' => $beta['site']->public_key,
            'message' => 'For Beta.',
            // Ignored, and the point of the test.
            'company_id' => $alpha['tenant']['company']->getKey(),
            'organization_id' => $alpha['tenant']['org']->getKey(),
            'franchise_id' => $alpha['tenant']['franchise']->getKey(),
        ])->assertCreated();

        $this->assertSame(0, $this->inTenant($alpha['tenant']['org'], fn () => Lead::query()->count()));

        $lead = $this->inTenant($beta['tenant']['org'], fn () => Lead::query()->firstOrFail());

        $this->assertSame($beta['tenant']['company']->getKey(), $lead->company_id);
        $this->assertSame($beta['tenant']['org']->getKey(), $lead->organization_id);
    }

    /**
     * Widget settings follow the same rule as values.
     *
     * They reach the plugin through the token-authenticated payload, so a
     * misplaced tenant here would put one company's wording and colours on
     * another company's website.
     */
    public function test_widget_settings_come_from_the_token_owners_site(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $this->signedInAs($alpha['editor'])
            ->putJson("/api/v1/web/sites/{$alpha['site']->getKey()}/widgets", [
                'config' => ['chat' => ['enabled' => true, 'title' => 'Alpha only']],
            ])->assertOk();

        $body = $this->withToken($beta['token'])
            ->getJson('/api/v1/web/plugin/values?host=beta-example.com')
            ->assertOk()
            ->json();

        $this->assertNotSame('Alpha only', $body['widgets']['chat']['title']);
        $this->assertStringNotContainsString('Alpha only', json_encode($body));
    }

    /**
     * An editor in one organization cannot reach another's site by id.
     *
     * This path IS scoped by BelongsToTenant, unlike the four above — which is
     * exactly why it is worth one test: it proves the scoped path behaves
     * differently from the unscoped ones, so a future change that quietly
     * removed the global scope would fail here rather than nowhere.
     */
    public function test_an_editor_cannot_reach_another_organizations_site(): void
    {
        $alpha = $this->siteFor('Alpha', 'a-1chimney.com');
        $beta = $this->siteFor('Beta', 'beta-example.com');

        $this->signedInAs($alpha['editor'])
            ->getJson("/api/v1/web/sites/{$beta['site']->getKey()}/widgets")
            ->assertNotFound();

        $this->signedInAs($alpha['editor'])
            ->putJson("/api/v1/web/sites/{$beta['site']->getKey()}/widgets", [
                'config' => ['chat' => ['title' => 'Reached in.']],
            ])->assertNotFound();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @return array{site: WebSite, token: string, editor: User, tenant: array<string, mixed>}
     */
    private function siteFor(string $name, string $host): array
    {
        $tenant = $this->provisionTenant($name);
        $editor = $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit'],
        );

        $body = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => $host,
        ])->assertCreated()->json();

        $site = $this->inTenant(
            $tenant['org'],
            fn () => WebSite::findOrFail($body['data']['id']),
        );

        return ['site' => $site, 'token' => $body['token'], 'editor' => $editor, 'tenant' => $tenant];
    }

    /** @param array{site: WebSite, editor: User} $made */
    private function setValue(array $made, string $key, string $value): void
    {
        $this->signedInAs($made['editor'])
            ->putJson("/api/v1/web/sites/{$made['site']->getKey()}/values", [
                'values' => [$key => $value],
            ])->assertOk();
    }

    private function startChat(string $key, string $message): TestResponse
    {
        return $this->postJson('/api/v1/web/chat', [
            'key' => $key,
            'message' => $message,
        ])->assertCreated();
    }
}
