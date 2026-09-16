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

    /**
     * THE one that protects a live page: deleting field 2 of three must not let
     * the next field take the name of field 3, which is pasted into a template
     * somewhere and would silently start showing different words.
     */
    public function test_a_new_field_never_takes_a_name_already_in_use(): void
    {
        $this->assertSame(
            'about_us_field_4',
            PageFields::nextKey('/about-us', ['about_us_field_1', 'about_us_field_3']),
        );

        // The site root is "home" rather than an empty prefix.
        $this->assertSame('home_field_1', PageFields::nextKey('/', []));
    }

    /** A built-in always wins, or the catalogue would carry the key twice. */
    public function test_a_built_in_name_can_never_be_claimed(): void
    {
        $this->assertFalse(PageFields::isCustomKey('phone'));
        $this->assertFalse(PageFields::isCustomKey('field_1'));
        $this->assertFalse(PageFields::isCustomKey('home_field_1_x'));
        $this->assertTrue(PageFields::isCustomKey('home_field_1'));
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
}
