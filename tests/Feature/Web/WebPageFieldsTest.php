<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Support\Web\PageFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * A page's own screen: overrides of the site's values, and free text nobody
 * could have listed in advance.
 *
 * The thing under test throughout is the KEY. The plugin turns every key the
 * server sends into a shortcode on the customer's website, so a key is a
 * promise: once `[ezbizi_home_field_1]` is pasted into a live page it has to go
 * on meaning the same thing, and anything that can silently repoint it is the
 * defect worth hunting here.
 */
class WebPageFieldsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{site: array<string, mixed>, editor: User, tenant: array<string, mixed>} */
    private function site(bool $paired = false): array
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

    public function test_a_page_can_hold_text_the_catalogue_never_listed(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $id = $site['data']['id'];

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", [
            'path' => '/about-us',
            'values' => ['about_us_field_1' => 'We have swept chimneys since 1974.'],
        ])->assertOk();

        $fields = collect(
            $this->signedInAs($editor)->getJson("/api/v1/web/sites/$id/values")
                ->assertOk()->json('data.fields')
        )->keyBy('key');

        $this->assertTrue($fields->has('about_us_field_1'));
        $this->assertSame('[ezbizi_about_us_field_1]', $fields['about_us_field_1']['shortcode']);
        $this->assertSame('/about-us', $fields['about_us_field_1']['path']);
    }

    /**
     * The plugin registers a shortcode for every key in ITS list, so a field
     * missing from the plugin payload is a shortcode that renders nothing on a
     * page somebody has already published.
     */
    public function test_the_website_is_told_about_them_too(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $id = $site['data']['id'];

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", [
            'path' => '/about-us',
            'values' => ['about_us_field_1' => 'We have swept chimneys since 1974.'],
        ])->assertOk();

        $payload = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')
            ->assertOk()
            ->json();

        $keys = array_column($payload['fields'], 'key');

        $this->assertContains('about_us_field_1', $keys);
        $this->assertSame(
            'We have swept chimneys since 1974.',
            $payload['pages']['/about-us']['about_us_field_1'] ?? null,
        );
    }

    /**
     * A key that claims a page cannot be saved without one. `home_field_1` set
     * site-wide would publish on every page of the site under a name that says
     * it belongs to one.
     */
    public function test_an_invented_key_is_refused_site_wide(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'values' => ['home_field_1' => 'Anywhere.'],
            ])->assertStatus(422);
    }

    /** Anything not shaped like a page field is still refused, as before. */
    public function test_a_key_nobody_renders_is_still_refused(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'path' => '/about-us',
                'values' => ['favourite_colour' => 'Orange.'],
            ])->assertStatus(422);
    }

    /** Every one is a shortcode registered on every page load of their site. */
    public function test_a_page_cannot_hold_unlimited_fields(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $values = [];

        for ($i = 1; $i <= PageFields::MAX_PER_PAGE + 1; $i++) {
            $values['about_us_field_'.$i] = 'Paragraph '.$i;
        }

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'path' => '/about-us',
                'values' => $values,
            ])->assertStatus(422);
    }

    /** A built-in always wins, or the catalogue would carry the key twice. */
    public function test_a_built_in_name_can_never_be_claimed(): void
    {
        $this->assertFalse(PageFields::isCustomKey('phone'));
        $this->assertFalse(PageFields::isCustomKey('field_1'));
        $this->assertFalse(PageFields::isCustomKey('home_field_1_x'));
        $this->assertTrue(PageFields::isCustomKey('home_field_1'));
        $this->assertTrue(PageFields::isTemplateKey('template_2'));
        $this->assertFalse(PageFields::isTemplateKey('template_0'));
        $this->assertFalse(PageFields::isTemplateKey('template_2_x'));
    }

    /**
     * The two dropdowns on a page's screen come from the website, because a
     * list of authors typed in EzBizi drifts the first time somebody leaves.
     */
    public function test_the_authors_and_categories_come_from_the_website(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site(paired: true);

        Http::fake([
            'a-1chimney.com/wp-json/wp/v2/users*' => Http::response([
                ['id' => 3, 'name' => 'Pat O&#039;Brien', 'slug' => 'pat'],
            ], 200),
            'a-1chimney.com/wp-json/wp/v2/categories*' => Http::response([
                ['id' => 7, 'name' => 'Chimney repair', 'slug' => 'chimney-repair'],
            ], 200),
        ]);

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content/options")
            ->assertOk()
            ->json();

        // Decoded here, or a dropdown shows somebody an HTML entity.
        $this->assertSame("Pat O'Brien", $body['authors'][0]['name']);
        $this->assertSame('Chimney repair', $body['categories'][0]['name']);
    }

    /** A site that never paired is told what to do, not shown an error. */
    public function test_an_unpaired_site_cannot_offer_options(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        Http::fake();

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content/options")
            ->assertStatus(502);

        Http::assertNothingSent();
    }

    /**
     * A template is one piece of text nine pages share, written once.
     *
     * Stored site-wide, which is the whole difference from a page field: the
     * shortcode means the same thing everywhere, and that is the only reason
     * somebody would reach for one.
     */
    public function test_a_template_is_shared_by_the_whole_site(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $id = $site['data']['id'];

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", [
            'values' => ['template_1' => 'Serving the tri-state area since 1974.'],
        ])->assertOk();

        $fields = collect(
            $this->signedInAs($editor)->getJson("/api/v1/web/sites/$id/values")
                ->assertOk()->json('data.fields')
        )->keyBy('key');

        $this->assertTrue($fields->has('template_1'));
        $this->assertSame('[ezbizi_template_1]', $fields['template_1']['shortcode']);
        $this->assertSame('Template 1', $fields['template_1']['label']);

        // No path, and that absence is what tells a template from a page field.
        $this->assertNull($fields['template_1']['path']);
        $this->assertTrue($fields['template_1']['template']);
    }

    /** Audit 2026-11-27: removing the top template does not free its number. */
    public function test_a_removed_top_number_is_remembered(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $id = $site['data']['id'];

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", ['values' => ['template_3' => 'x']])->assertOk();
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", ['values' => ['template_3' => '']])->assertOk();

        $this->assertSame(3, $this->signedInAs($editor)->getJson("/api/v1/web/sites/$id/values")
            ->assertOk()->json('data.numbered_high.template'));
    }

    /** It has to reach the website, or the shortcode renders nothing. */
    public function test_the_website_is_told_about_templates(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'values' => ['template_1' => 'Serving the tri-state area since 1974.'],
            ])->assertOk();

        $payload = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')
            ->assertOk()
            ->json();

        $this->assertContains('template_1', array_column($payload['fields'], 'key'));

        // Site-wide, so it is in values rather than under a page.
        $this->assertSame(
            'Serving the tri-state area since 1974.',
            $payload['values']['template_1'] ?? null,
        );
    }

    /**
     * A template overridden on one page would be a shortcode meaning different
     * things in different places, which is exactly what somebody reaches for a
     * template to avoid — and they would find out by reading two pages side by
     * side rather than from any error.
     */
    public function test_a_template_cannot_be_overridden_on_a_page(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'path' => '/about-us',
                'values' => ['template_1' => 'Different here.'],
            ])->assertStatus(422);
    }

    /**
     * THE one that was missing, and the defect it would have caught reached
     * Jose's screen: a page's text fields were sectioned `general`, so the
     * SITE's General tab drew every text field of every page. He opened it and
     * found a hundred boxes belonging to pages he was not looking at.
     *
     * The General tab filters on section, so the section IS the fix — and
     * asserting the section is asserting the thing the screen actually reads.
     */
    public function test_a_pages_own_fields_never_appear_under_general(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();
        $id = $site['data']['id'];

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", [
            'path' => '/about-us',
            'values' => ['about_us_field_1' => 'Words about this page.'],
        ])->assertOk();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/$id/values", [
            'values' => ['template_1' => 'Shared words.'],
        ])->assertOk();

        $fields = collect(
            $this->signedInAs($editor)->getJson("/api/v1/web/sites/$id/values")
                ->assertOk()->json('data.fields')
        );

        $general = $fields->where('section', 'general')->pluck('key')->all();

        $this->assertNotContains('about_us_field_1', $general);
        $this->assertNotContains('template_1', $general);

        // Nor do the three that only mean something on a page.
        $this->assertNotContains('group', $general);
        $this->assertNotContains('author', $general);
        $this->assertNotContains('category', $general);

        /*
         * The control. Without it every assertion above would pass on a
         * catalogue that had stopped offering a `general` section at all.
         */
        $this->assertContains('phone', $general);
        $this->assertContains('city_link', $general);

        // And each is still offered, in its own section, or the page screen
        // and the Templates tab would have nothing to draw.
        $this->assertSame('page', $fields->firstWhere('key', 'about_us_field_1')['section']);
        $this->assertSame('page', $fields->firstWhere('key', 'group')['section']);
        $this->assertSame('template', $fields->firstWhere('key', 'template_1')['section']);
    }
}
