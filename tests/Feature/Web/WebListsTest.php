<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The team and the locations: edited in the Widgets tab, drawn by the plugin.
 */
class WebListsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_the_team_is_saved_in_order_and_reaches_the_plugin(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $site = $this->site($editor, $tenant, 'a-1chimney.com');

        $saved = $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/team", [
            'members' => [
                ['name' => 'Ana Ruiz', 'role' => 'Technician', 'category' => 'Field', 'photo_url' => 'https://x.test/ana.jpg'],
                ['name' => 'Bo Lee', 'role' => 'Dispatcher', 'category' => 'Office'],
            ],
        ])->assertOk()->json('data');

        $this->assertSame(['Ana Ruiz', 'Bo Lee'], array_column($saved, 'name'));

        // A second save replaces, including the order.
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/team", [
            'members' => [['name' => 'Bo Lee'], ['name' => 'Cy Oh']],
        ])->assertOk();

        $team = $this->plugin($site)->assertOk()->json('team');
        $this->assertSame(['Bo Lee', 'Cy Oh'], array_column($team, 'name'));
    }

    public function test_the_team_belongs_to_the_company_so_every_site_shows_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $main = $this->site($editor, $tenant, 'a-1chimney.com');
        $branch = $this->site($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$branch['data']['id']}/team", [
            'members' => [['name' => 'Ana Ruiz']],
        ])->assertOk();

        $this->assertSame('Ana Ruiz', $this->plugin($main)->json('team.0.name'));
    }

    public function test_branches_are_the_sites_own_and_stores_are_the_companys(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $main = $this->site($editor, $tenant, 'a-1chimney.com');
        $nj = $this->site($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$nj['data']['id']}/locations", [
            'scope' => 'site',
            'locations' => [['name' => 'North Jersey', 'phone' => '973-555-0100', 'latitude' => 40.73, 'longitude' => -74.17]],
        ])->assertOk();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$main['data']['id']}/locations", [
            'scope' => 'company',
            'locations' => [['name' => 'Atlanta', 'state' => 'GA'], ['name' => 'Austin', 'state' => 'TX']],
        ])->assertOk();

        $atNj = $this->plugin($nj)->json('locations');
        $atMain = $this->plugin($main)->json('locations');

        $this->assertSame(['North Jersey'], array_column($atNj['site'], 'name'));
        $this->assertSame(['Atlanta', 'Austin'], array_column($atNj['company'], 'name'));
        // The main site answers for no branch of its own.
        $this->assertSame([], $atMain['site']);
        $this->assertSame(40.73, $atNj['site'][0]['latitude']);

        // Replacing the stores leaves the branches alone.
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$nj['data']['id']}/locations", [
            'scope' => 'company', 'locations' => [],
        ])->assertOk();

        $after = $this->plugin($nj)->json('locations');
        $this->assertSame([], $after['company']);
        $this->assertSame(['North Jersey'], array_column($after['site'], 'name'));
    }

    /** Jose, 2026-09-25: "yes all of it" — FAQs, services, before-and-after, reviews. */
    public function test_items_are_kept_by_kind_and_reach_the_plugin(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $site = $this->site($editor, $tenant, 'a-1chimney.com');

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/items/faq", [
            'items' => [['title' => 'Do you sweep in winter?', 'body' => 'Yes.']],
        ])->assertOk();
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/items/before_after", [
            'items' => [['title' => 'Crown', 'image_url' => 'https://x.test/b.jpg', 'image2_url' => 'https://x.test/a.jpg']],
        ])->assertOk();

        $items = $this->plugin($site)->assertOk()->json('items');
        $this->assertSame('Do you sweep in winter?', $items['faq'][0]['title']);
        $this->assertSame('https://x.test/a.jpg', $items['before_after'][0]['image2_url']);
        // Saving one kind leaves the others alone.
        $this->assertSame([], $items['service']);

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/items/faq", ['items' => []])->assertOk();
        $this->assertCount(1, $this->plugin($site)->json('items.before_after'));

        $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$site['data']['id']}/items/nonsense")->assertNotFound();
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/items/review", [
            'items' => [['title' => 'Pat', 'rating' => 9]],
        ])->assertUnprocessable();
    }

    public function test_a_link_that_is_not_a_web_address_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $site = $this->site($editor, $tenant, 'a-1chimney.com');

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/team", [
            'members' => [['name' => 'Ana', 'photo_url' => 'javascript:alert(1)']],
        ])->assertUnprocessable();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/locations", [
            'scope' => 'company', 'locations' => [['name' => 'X', 'website_url' => 'javascript:alert(1)']],
        ])->assertUnprocessable();
    }

    public function test_view_alone_can_read_the_lists_and_not_change_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editor($tenant);
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view', 'web_management.widgets.view']);
        $site = $this->site($editor, $tenant, 'a-1chimney.com');

        $this->signedInAs($viewer)->getJson("/api/v1/web/sites/{$site['data']['id']}/team")->assertOk();
        $this->signedInAs($viewer)->getJson("/api/v1/web/sites/{$site['data']['id']}/locations")->assertOk();
        $this->signedInAs($viewer)->putJson("/api/v1/web/sites/{$site['data']['id']}/team", ['members' => []])->assertForbidden();
        $this->signedInAs($viewer)->putJson("/api/v1/web/sites/{$site['data']['id']}/locations", ['scope' => 'site', 'locations' => []])->assertForbidden();
    }

    public function test_another_organization_cannot_reach_the_lists(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');
        $site = $this->site($this->editor($alpha), $alpha, 'a-1chimney.com');

        $this->signedInAs($this->editor($beta))
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/team", ['members' => [['name' => 'Intruder']]])
            ->assertNotFound();
    }

    private function editor(array $tenant): User
    {
        return $this->userWithRole($tenant, 'Web Editor', [
            'web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view',
        ]);
    }

    /** @return array{data: array<string, mixed>, token: string} */
    private function site(User $editor, array $tenant, string $host): array
    {
        $body = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            'host' => $host,
        ])->assertCreated()->json();

        return ['data' => $body['data'], 'token' => $body['token']];
    }

    private function plugin(array $site)
    {
        return $this->getJson(
            '/api/v1/web/plugin/values?host='.urlencode($site['data']['host']),
            ['Authorization' => 'Bearer '.$site['token']],
        );
    }
}
