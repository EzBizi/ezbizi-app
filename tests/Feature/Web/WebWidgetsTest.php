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

    // ── the customer's own colour ───────────────────────────────────

    /**
     * The widget renders on the CUSTOMER's website as the customer's tool.
     *
     * Defaulting to EzBizi's palette made every customer's contact form look
     * like an EzBizi advert until somebody noticed — and the person who would
     * notice is the one least likely to open this screen. Jose's decision,
     * relayed by Marketing on 2026-09-08.
     */
    public function test_the_widget_defaults_to_the_customers_own_colour(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $this->signedInAs($this->brandSetter($tenant))
            ->putJson('/api/v1/branding/colour', ['colour' => '#7B2D8E'])
            ->assertOk();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertSame('#7b2d8e', $body['config']['style']['accent']);
    }

    /**
     * A brand colour arriving as a default drags the text ON the button with it.
     *
     * A fixed white would put white on yellow for anybody with a bright brand,
     * and the screen would then warn about a pair the customer never chose. So
     * the text is derived, and the pair is legible by construction.
     */
    public function test_a_bright_brand_gets_dark_text_and_a_dark_brand_gets_light(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        // Made once: a second user on a same-named role is a different fixture,
        // not the same person trying again.
        $admin = $this->brandSetter($tenant);

        foreach ([['#ffff00', '#000000'], ['#101820', '#ffffff']] as [$brand, $expected]) {
            $this->signedInAs($admin)
                ->putJson('/api/v1/branding/colour', ['colour' => $brand])
                ->assertOk();

            $body = $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
                ->assertOk()
                ->json();

            $this->assertSame($brand, $body['config']['style']['accent']);
            $this->assertSame(
                $expected,
                $body['config']['style']['on_accent'],
                "A $brand button needs $expected on it to be readable.",
            );
        }
    }

    /**
     * The property, tested at the boundary rather than either side of it.
     *
     * The pair above asserts both DIRECTIONS from a distance — yellow is
     * 17.6:1 and the dark one is nowhere near the crossover — and a boundary is
     * not covered by testing far from it on both sides. #00838F is an
     * unremarkable teal that sits close enough to matter: it read 4.18:1
     * against the near-black this used to return, and our own contrast warning
     * would have fired on a pair the customer never chose.
     *
     * Asserts the RATIO clears 4.5 rather than which hex comes back, so it
     * survives the constant changing again and fails only when the property
     * actually breaks. Boundary and colour both from the UI/UX session.
     */
    public function test_a_derived_pair_is_never_one_our_own_screen_would_warn_about(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $admin = $this->brandSetter($tenant);

        foreach (['#00838f', '#767676', '#8a8a8a', '#ffff00', '#101820'] as $brand) {
            $this->signedInAs($admin)
                ->putJson('/api/v1/branding/colour', ['colour' => $brand])
                ->assertOk();

            $style = $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
                ->assertOk()
                ->json('config.style');

            $ratio = $this->contrast($style['accent'], $style['on_accent']);

            $this->assertGreaterThanOrEqual(4.5, $ratio, sprintf(
                'A %s button got %s on it at %.2f:1 — under 4.5, so the Widgets '
                .'screen would warn about a pair nobody chose.',
                $brand,
                $style['on_accent'],
                $ratio,
            ));
        }
    }

    /**
     * A business that has told us no colour gets something legible, not nothing.
     *
     * #0b5cad survives as the fallback for the reason UI/UX measured when it was
     * the default: it is nobody's brand and reads 6.67:1 on white. A default
     * that inherits nothing is not neutral, it is unstyled.
     */
    public function test_a_business_with_no_stated_colour_gets_the_neutral(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertSame('#0b5cad', $body['config']['style']['accent']);
        $this->assertSame('#ffffff', $body['config']['style']['on_accent']);
    }

    /** A colour the customer typed themselves still wins over their brand. */
    public function test_a_colour_set_on_the_widget_beats_the_brand(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $this->signedInAs($this->brandSetter($tenant))
            ->putJson('/api/v1/branding/colour', ['colour' => '#7b2d8e'])
            ->assertOk();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['style' => ['accent' => '#654321']],
            ])->assertOk();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertSame('#654321', $body['config']['style']['accent']);
    }

    /** It reaches the plugin, which is the only place it does any work. */
    public function test_the_brand_colour_reaches_the_website(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $this->signedInAs($this->brandSetter($tenant))
            ->putJson('/api/v1/branding/colour', ['colour' => '#7b2d8e'])
            ->assertOk();

        $payload = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')
            ->assertOk()
            ->json();

        $this->assertSame('#7b2d8e', $payload['widgets']['style']['accent']);
    }

    /** Changing what strangers see on the public website is not a view right. */
    public function test_view_alone_cannot_change_the_brand_colour(): void
    {
        ['tenant' => $tenant] = $this->site();

        $viewer = $this->userWithRole($tenant, 'Web Viewer', ['web_management.view']);

        $this->signedInAs($viewer)
            ->putJson('/api/v1/branding/colour', ['colour' => '#7b2d8e'])
            ->assertForbidden();
    }

    /** Not a colour, not stored — it lands in a stylesheet on a stranger's site. */
    public function test_a_brand_colour_that_is_not_a_colour_is_refused(): void
    {
        ['tenant' => $tenant] = $this->site();

        $this->signedInAs($this->brandSetter($tenant))
            ->putJson('/api/v1/branding/colour', ['colour' => 'red;}body{display:none}'])
            ->assertStatus(422);
    }

    /**
     * The picker draws the same string the website renders.
     *
     * Its preview is a button in the brand colour with the automatic text on
     * it. If the client derived that itself there would be two definitions of
     * readable, and the day the threshold moved the preview and the customer's
     * own website would disagree — with the customer first to notice.
     */
    public function test_the_colour_endpoint_carries_the_text_colour_it_derives(): void
    {
        ['tenant' => $tenant] = $this->site();
        $admin = $this->brandSetter($tenant);

        $body = $this->signedInAs($admin)
            ->putJson('/api/v1/branding/colour', ['colour' => '#ffff00'])
            ->assertOk()
            ->json('data');

        $this->assertSame('#ffff00', $body['effective']);
        $this->assertSame('#000000', $body['on_effective'], 'A bright brand needs dark text.');
        $this->assertSame('#ffff00', $body['rendered']);
        $this->assertSame('#000000', $body['on_rendered']);
    }

    /**
     * The not-set state is every customer today, so the screen has to be able
     * to draw it: null says they have told us nothing, `rendered` says what a
     * visitor sees anyway.
     */
    public function test_a_business_with_no_colour_still_says_what_a_visitor_sees(): void
    {
        ['tenant' => $tenant] = $this->site();

        $body = $this->signedInAs($this->brandSetter($tenant))
            ->getJson('/api/v1/branding/colour')
            ->assertOk()
            ->json('data');

        $this->assertNull($body['effective'], 'Not set is a real answer, not a missing one.');
        $this->assertNull($body['on_effective']);
        $this->assertSame('#0b5cad', $body['rendered']);
        $this->assertSame('#ffffff', $body['on_rendered']);
    }

    /**
     * The fallback has one definition.
     *
     * It was written three times in WidgetDefaults before the picker needed it
     * too; a fourth copy in Dart is what this constant exists to prevent.
     */
    public function test_the_widget_and_the_picker_agree_on_the_fallback(): void
    {
        ['site' => $site, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $picker = $this->signedInAs($this->brandSetter($tenant))
            ->getJson('/api/v1/branding/colour')
            ->assertOk()
            ->json('data');

        $widget = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json('config.style');

        $this->assertSame($widget['accent'], $picker['rendered']);
        $this->assertSame($widget['on_accent'], $picker['on_rendered']);
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

    /** WCAG relative contrast, computed here so the test does not trust the code it tests. */
    private function contrast(string $a, string $b): float
    {
        $luminance = static function (string $hex): float {
            $hex = ltrim($hex, '#');
            $channel = static function (int $byte): float {
                $c = $byte / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel((int) hexdec(substr($hex, 0, 2)))
                + 0.7152 * $channel((int) hexdec(substr($hex, 2, 2)))
                + 0.0722 * $channel((int) hexdec(substr($hex, 4, 2)));
        };

        $x = $luminance($a);
        $y = $luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /**
     * Somebody who may change the organization.
     *
     * Deliberately NOT the Web Editor: the brand colour changes what strangers
     * see on the public website, so it takes `organizations.edit` and a web
     * editor's 403 is the gate working rather than a fixture problem.
     *
     * @param  array<string, mixed>  $tenant
     */
    private function brandSetter(array $tenant): User
    {
        return $this->userWithRole($tenant, 'Brand Admin', [
            'web_management.view', 'web_management.edit', 'organizations.edit',
        ]);
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
