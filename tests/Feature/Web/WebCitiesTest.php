<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Support\Web\PageKinds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * City pages, separated out of Pages.
 *
 * Mia asked for this on 2026-09-18 with one condition attached — "toula has
 * already filled a lot of information on them already on ezbizi we dont want to
 * destroy her work" — so the first thing asserted here is that the feature
 * READS. Nothing in this file writes a value, and the endpoint it exercises
 * cannot: there is no route that would.
 *
 * The other thing being held is that Pages and Cities PARTITION. A page in both
 * lists is a page somebody edits twice and a page in neither is one they cannot
 * find, and both are found by customers rather than by us.
 */
class WebCitiesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_page_with_a_city_is_a_city_page_and_one_without_is_not(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        $cities = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertOk()
            ->json('cities');

        $paths = array_column($cities, 'path');

        $this->assertContains('/service-areas/toms-river-nj', $paths);
        $this->assertContains('/service-areas/fairburn-ga', $paths);
        $this->assertNotContains('/about-us', $paths);
    }

    /** Her own label is what groups them, and a blank one sorts last. */
    public function test_cities_carry_the_group_and_the_ungrouped_sort_last(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        $cities = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertOk()
            ->json('cities');

        $groups = array_column($cities, 'group');

        $this->assertSame('North Fulton', $groups[0]);
        $this->assertSame('South Jersey', $groups[1]);
        // The one with no group is last, so a pane can draw Ungrouped at the
        // bottom without sorting anything itself.
        $this->assertSame('', end($groups));
    }

    /**
     * How much of a city has been answered, so the pane can show the work.
     *
     * A city with a name and nothing else is the next job; a city with six
     * values is done. The number is what lets somebody see which is which
     * without opening 609 pages.
     */
    public function test_each_city_says_how_much_of_it_is_filled_in(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        $cities = collect($this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertOk()
            ->json('cities'))->keyBy('path');

        $this->assertSame(4, $cities['/service-areas/toms-river-nj']['filled']);
        $this->assertSame('Toms River', $cities['/service-areas/toms-river-nj']['city']);
        $this->assertSame('732-555-0100', $cities['/service-areas/toms-river-nj']['phone']);

        $this->assertSame(1, $cities['/service-areas/bare-nj']['filled']);
    }

    /**
     * THE ONE THAT MATTERS on the day somebody's WordPress is down.
     *
     * The values are ours. A pane that could not open because a third party was
     * slow would be unable to show somebody what EzBizi is publishing at
     * exactly the moment they wanted to check.
     */
    public function test_the_list_answers_without_the_website(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        Http::fake(['a-1chimney.com/*' => Http::response([], 500)]);

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertOk()
            ->assertJsonPath('scanned', false);

        Http::assertNothingSent();
    }

    /**
     * `?scan=1` finds the towns the website has pages for and EzBizi does not.
     *
     * This is the worklist half. The author account is the signal — the fleet
     * files city pages under `Single City` and `SEO CITY` — and it is the only
     * signal that can see a page nobody has filled in yet.
     */
    public function test_a_scan_finds_city_pages_that_ezbizi_holds_nothing_for(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        $this->wordpressHas([
            ['id' => 1, 'author' => 52, 'link' => 'https://a-1chimney.com/service-areas/toms-river-nj/', 'title' => 'Toms River, NJ'],
            ['id' => 2, 'author' => 52, 'link' => 'https://a-1chimney.com/service-areas/brick-nj/', 'title' => 'Brick, NJ'],
            ['id' => 3, 'author' => 1, 'link' => 'https://a-1chimney.com/about-us/', 'title' => 'About Us'],
        ]);

        $cities = collect($this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities?scan=1")
            ->assertOk()
            ->assertJsonPath('scanned', true)
            ->json('cities'))->keyBy('path');

        // Hers, with the website's own title folded in.
        $this->assertTrue($cities['/service-areas/toms-river-nj']['in_ezbizi']);
        $this->assertSame('Toms River, NJ', $cities['/service-areas/toms-river-nj']['title']);

        // The town on the website that EzBizi publishes nothing about. This row
        // is the point of the scan.
        $this->assertFalse($cities['/service-areas/brick-nj']['in_ezbizi']);
        $this->assertSame(0, $cities['/service-areas/brick-nj']['filled']);

        // The brand-authored page is not a city, however many pages are.
        $this->assertArrayNotHasKey('/about-us', $cities->all());
    }

    /**
     * A failed scan is a NOTE on a good answer, never an error.
     *
     * The values half has already succeeded by the time the website is asked.
     * Replacing a list of cities with a red box because somebody else's server
     * was slow throws away the answer to keep the footnote.
     */
    public function test_a_scan_that_fails_still_returns_her_cities(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        Http::fake(['a-1chimney.com/*' => Http::response([], 401)]);

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities?scan=1")
            ->assertOk()
            ->json();

        $this->assertNotEmpty($body['cities']);
        $this->assertStringContainsString('refused', $body['note']);
    }

    /**
     * Pages stops listing them, and the exclusion goes to WORDPRESS so that
     * the count and the contents agree.
     *
     * A list showing three rows under "page 1 of 13" is a pager describing a
     * list that is not on screen, which is what filtering after the fact would
     * have produced on a site with 609 city pages.
     */
    public function test_pages_excludes_city_pages_at_the_source(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        $this->wordpressHas([
            ['id' => 3, 'author' => 1, 'link' => 'https://a-1chimney.com/about-us/', 'title' => 'About Us'],
        ]);

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertOk();

        /*
         * BOTH city accounts, not just the one the fixture's pages use.
         * `Single City` (49) authors nothing in this test and is still excluded,
         * which is the correct behaviour: the list comes from who the site's
         * city authors ARE, not from who happens to have written a page in the
         * batch we are looking at.
         */
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'wp/v2/pages')) {
                return false;
            }

            parse_str((string) parse_url(urldecode($request->url()), PHP_URL_QUERY), $query);
            $excluded = explode(',', (string) ($query['author_exclude'] ?? ''));

            $this->assertContains('49', $excluded);
            $this->assertContains('52', $excluded);
            // The brand account and the service-page account stay in the list.
            $this->assertNotContains('1', $excluded);
            $this->assertNotContains('48', $excluded);

            return true;
        });
    }

    /**
     * The page she typed a city into but the brand account authored — the one
     * case the author signal cannot see.
     *
     * Rare on the fleet and cheap to cover, and without it the two lists would
     * overlap on exactly the page somebody had been working on.
     */
    public function test_a_city_page_the_author_signal_misses_is_still_kept_out_of_pages(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();

        $this->wordpressHas([
            ['id' => 9, 'author' => 1, 'link' => 'https://a-1chimney.com/service-areas/toms-river-nj/', 'title' => 'Toms River'],
            ['id' => 3, 'author' => 1, 'link' => 'https://a-1chimney.com/about-us/', 'title' => 'About Us'],
        ]);

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=page")
            ->assertOk()
            ->json();

        $this->assertSame(['/about-us'], array_map(
            fn (array $item) => PageKinds::pathOf($item['link']),
            $body['items'],
        ));

        // Said out loud, so a short list is explained rather than counted.
        $this->assertSame(1, $body['moved_to_cities']);
    }

    /** Posts are not pages and are not filtered as though they were. */
    public function test_posts_are_not_filtered_for_cities(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->siteWithCities();
        $this->wordpressHas([]);

        $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/content?type=post")
            ->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'wp/v2/posts')
            && ! str_contains(urldecode($request->url()), 'author_exclude'));

        // And the authors were never asked for, because nothing needed them.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'wp/v2/users'));
    }

    /** Somebody else's site is not readable, whatever they hold here. */
    public function test_a_stranger_cannot_read_another_organizations_cities(): void
    {
        ['site' => $site] = $this->siteWithCities();

        $other = $this->provisionTenant('Beta');
        $stranger = $this->userWithRole($other, 'Web Editor', [
            'web_management.view', 'web_management.edit',
            'web_management.seo.view', 'web_management.widgets.view',
        ]);

        $this->signedInAs($stranger)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertNotFound();
    }

    /** The SEO tab is the gate. No new permission was minted for the pane. */
    public function test_a_role_without_the_seo_tab_cannot_read_the_cities(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->siteWithCities();

        $widgetsOnly = $this->userWithRole($tenant, 'Widget Editor', [
            'web_management.view', 'web_management.edit', 'web_management.widgets.view',
        ]);

        $this->signedInAs($widgetsOnly)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertForbidden();

        // The positive control: the same fixture plus the tab gets through, so
        // the refusal above is about the tab and not about something else.
        $seo = $this->userWithRole($tenant, 'SEO Editor', [
            'web_management.view', 'web_management.edit', 'web_management.seo.view',
        ]);

        $this->signedInAs($seo)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/cities")
            ->assertOk();
    }

    /**
     * A site with her cities filled in, and the pages to go with them.
     *
     * @return array{site: array<string, mixed>, editor: User, tenant: array<string, mixed>}
     */
    private function siteWithCities(): array
    {
        Cache::flush();

        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole($tenant, 'Web Editor', [
            'web_management.view', 'web_management.edit',
            'web_management.seo.view', 'web_management.widgets.view',
        ]);

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => 'a-1chimney.com',
        ])->assertCreated()->json();

        $this->postJson('/api/v1/web/plugin/service-account', [
            'host' => 'a-1chimney.com',
            'user_login' => 'ezbizi-service',
            'password' => 'plain-text-secret',
            'rest_url' => 'https://a-1chimney.com/wp-json/',
        ], ['Authorization' => 'Bearer '.$created['token']])->assertCreated();

        /*
         * One request per page, because that is what the screen does: the
         * values endpoint takes a path and the values ON it. Written through
         * the API rather than with a factory so the fixture cannot describe a
         * row the product could not have produced.
         */
        $pages = [
            // Two cities she has worked on, in two regions.
            '/service-areas/toms-river-nj' => [
                'city' => 'Toms River',
                'state' => 'NJ',
                'phone' => '732-555-0100',
                'group' => 'South Jersey',
            ],
            '/service-areas/fairburn-ga' => ['city' => 'Fairburn', 'group' => 'North Fulton'],
            // Started, and not grouped yet.
            '/service-areas/bare-nj' => ['city' => 'Bare'],
            // Not a city page: a real page with a real override.
            '/about-us' => ['phone' => '973-555-0111'],
        ];

        foreach ($pages as $path => $values) {
            $this->signedInAs($editor)->putJson(
                "/api/v1/web/sites/{$created['data']['id']}/values",
                ['path' => $path, 'values' => $values],
            )->assertOk();
        }

        return ['site' => $created, 'editor' => $editor, 'tenant' => $tenant];
    }

    /**
     * What the website answers: an author list the fleet actually uses, and
     * whichever pages the test names.
     *
     * The author ids and names are the real ones read off atl.a-1chimney.com
     * and nj.a-1chimney.com on 2026-09-18 — `SEO CITY` is 52, `Single City` is
     * 49, and the brand account is 1 — so a change that breaks on the fleet's
     * naming breaks here.
     */
    private function wordpressHas(array $pages): void
    {
        Http::fake([
            'a-1chimney.com/wp-json/wp/v2/users*' => Http::response([
                ['id' => 1, 'name' => 'A1Chimney', 'slug' => 'a1chimney'],
                ['id' => 49, 'name' => 'Single City', 'slug' => 'single-city'],
                ['id' => 52, 'name' => 'SEO CITY', 'slug' => 'seo-city'],
                ['id' => 48, 'name' => 'Single Service', 'slug' => 'single-service'],
                ['id' => 50, 'name' => 'Single Location', 'slug' => 'single-location'],
            ], 200),
            'a-1chimney.com/wp-json/wp/v2/categories*' => Http::response([], 200),
            'a-1chimney.com/*' => Http::response(
                array_map(fn (array $p) => [
                    'id' => $p['id'],
                    'title' => ['rendered' => $p['title']],
                    'slug' => trim(parse_url($p['link'], PHP_URL_PATH), '/'),
                    'status' => 'publish',
                    'link' => $p['link'],
                    'modified' => '2026-09-01T00:00:00',
                    'author' => $p['author'],
                    'categories' => [],
                ], $pages),
                200,
                ['X-WP-Total' => (string) count($pages), 'X-WP-TotalPages' => '1'],
            ),
        ]);
    }
}
