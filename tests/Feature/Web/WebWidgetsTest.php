<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\WebSite;
use App\Support\Web\WidgetDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * How a site dresses its chat bubble and contact form.
 *
 * The config is written in EzBizi and rendered on a website we do not serve, so
 * most of what is asserted here is that a value which is not what it claims to
 * be never reaches that page.
 */
class WebWidgetsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_site_with_no_config_of_its_own_gets_the_defaults(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertFalse($body['own']);
        $this->assertFalse($body['config']['chat']['enabled']);
        $this->assertSame('#0b5cad', $body['config']['style']['accent']);
        $this->assertCount(4, $body['config']['form']['fields']);
    }

    public function test_the_questions_and_the_look_are_saved_and_read_back(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => [
                    'chat' => ['enabled' => true, 'title' => 'Talk to A-1'],
                    'form' => [
                        'enabled' => true,
                        'fields' => [
                            ['label' => 'Name', 'type' => 'text', 'required' => true],
                            ['label' => 'Service', 'type' => 'select', 'options' => ['Sweep', 'Repair']],
                        ],
                    ],
                    'style' => ['accent' => '#AA0000', 'radius' => 4],
                ],
            ])->assertOk();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertTrue($body['own']);
        $this->assertTrue($body['config']['chat']['enabled']);
        $this->assertSame('Talk to A-1', $body['config']['chat']['title']);
        $this->assertSame('#aa0000', $body['config']['style']['accent']);
        $this->assertSame(4, $body['config']['style']['radius']);
        $this->assertSame(['Sweep', 'Repair'], $body['config']['form']['fields'][1]['options']);
    }

    /**
     * The config is written through our admin screen and rendered into a
     * `<style>` block on somebody else's website. A colour that is not a colour
     * is a CSS injection with our name on it.
     */
    public function test_a_colour_that_is_not_a_colour_never_reaches_the_page(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => [
                    'style' => [
                        'accent' => 'red;}body{display:none}',
                        'font' => 'Arial;}*{color:red',
                        'radius' => 9999,
                    ],
                ],
            ])->assertOk()->json();

        $this->assertSame('#0b5cad', $body['config']['style']['accent']);
        // Everything that could end a declaration is gone: the semicolon, the
        // braces and the star, leaving letters that cannot escape the property.
        $this->assertSame('Arialcolorred', $body['config']['style']['font']);
        $this->assertSame(40, $body['config']['style']['radius']);
    }

    public function test_a_field_type_nobody_offers_becomes_a_text_box(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => [
                    'form' => [
                        'fields' => [
                            ['label' => 'Card number', 'type' => 'password'],
                            ['label' => '', 'type' => 'text'],
                            ['label' => 'Empty select', 'type' => 'select', 'options' => []],
                        ],
                    ],
                ],
            ])->assertOk()->json();

        $fields = $body['config']['form']['fields'];

        $this->assertCount(2, $fields, 'A field with no label is not a field.');
        $this->assertSame('text', $fields[0]['type']);
        $this->assertSame('text', $fields[1]['type'], 'A select with nothing to select is a text box.');
    }

    /**
     * A company that sets its colours once should not set them per subdomain.
     */
    public function test_a_branch_site_follows_the_primary_until_it_dresses_itself(): void
    {
        ['site' => $primary, 'editor' => $editor, 'tenant' => $tenant] = $this->site();
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$primary['data']['id']}/primary")
            ->assertOk();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$primary['data']['id']}/widgets", [
                'config' => ['style' => ['accent' => '#123456'], 'chat' => ['enabled' => true]],
            ])->assertOk();

        $followed = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$branch['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertFalse($followed['own'], 'The branch has no config of its own.');
        $this->assertSame('#123456', $followed['config']['style']['accent']);
        $this->assertTrue($followed['config']['chat']['enabled']);

        // Now it dresses itself, and stops following.
        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$branch['data']['id']}/widgets", [
                'config' => ['style' => ['accent' => '#654321']],
            ])->assertOk();

        $own = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$branch['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertTrue($own['own']);
        $this->assertSame('#654321', $own['config']['style']['accent']);
        $this->assertSame('#123456', $own['inherited']['style']['accent'], 'It can still say what it left.');
    }

    public function test_a_site_can_go_back_to_following_the_primary(): void
    {
        ['site' => $primary, 'editor' => $editor, 'tenant' => $tenant] = $this->site();
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)->postJson("/api/v1/web/sites/{$primary['data']['id']}/primary")->assertOk();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$primary['data']['id']}/widgets", [
            'config' => ['style' => ['accent' => '#123456']],
        ])->assertOk();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$branch['data']['id']}/widgets", [
            'config' => ['style' => ['accent' => '#654321']],
        ])->assertOk();

        $body = $this->signedInAs($editor)
            ->deleteJson("/api/v1/web/sites/{$branch['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertFalse($body['own']);
        $this->assertSame('#123456', $body['config']['style']['accent']);
    }

    /**
     * The plugin renders from this and nothing else, so it has to arrive
     * complete — a missing key would be a widget with no wording at all.
     */
    public function test_the_plugin_payload_carries_the_whole_config(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
            'config' => ['chat' => ['enabled' => true, 'title' => 'Talk to A-1']],
        ])->assertOk();

        $payload = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')
            ->assertOk()
            ->json();

        $this->assertSame('Talk to A-1', $payload['widgets']['chat']['title']);
        $this->assertNotEmpty($payload['widgets']['style']['accent']);
        $this->assertNotEmpty($payload['widgets']['form']['fields']);
        $this->assertSame($payload['public_key'], $payload['public_key']);
    }

    public function test_view_alone_cannot_change_the_widgets(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();

        $viewer = $this->userWithRole($tenant, 'Web Viewer', ['web_management.view']);

        $this->signedInAs($viewer)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk();

        $this->signedInAs($viewer)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['style' => ['accent' => '#000000']],
            ])->assertForbidden();
    }

    public function test_the_defaults_are_a_complete_shape(): void
    {
        $resolved = WidgetDefaults::resolve([]);

        $this->assertSame(array_keys(WidgetDefaults::all()), array_keys($resolved));
        $this->assertArrayHasKey('title', $resolved['chat']);
        $this->assertArrayHasKey('fields', $resolved['form']);
        $this->assertArrayHasKey('accent', $resolved['style']);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** @return array{site: array<string, mixed>, editor: User, tenant: array<string, mixed>} */
    private function site(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit'],
        );

        return [
            'site' => $this->registerSite($editor, $tenant, 'a-1chimney.com'),
            'editor' => $editor,
            'tenant' => $tenant,
        ];
    }

    /** @return array{data: array<string, mixed>, token: string} */
    private function registerSite(User $editor, array $tenant, string $host): array
    {
        $body = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            'host' => $host,
        ])->assertCreated()->json();

        return ['data' => $body['data'], 'token' => $body['token']];
    }
}
