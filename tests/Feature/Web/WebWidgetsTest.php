<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Models\WebSite;
use App\Models\WebWidget;
use App\Support\Web\WidgetDefaults;
use App\Support\Web\WidgetPresets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * A week, Monday first, the way the screen sends it.
     *
     * @return array<int, array<string, mixed>>
     */
    private function week(string $open = '08:00', string $close = '17:00'): array
    {
        return [
            ...array_fill(0, 5, ['closed' => false, 'open' => $open, 'close' => $close]),
            ['closed' => true],
            ['closed' => true],
        ];
    }

    /** Toula's chat options: only a web address, a colour and a path get through. */
    public function test_the_chat_options_keep_their_shape(): void
    {
        $chat = WidgetDefaults::resolve(['chat' => [
            'avatar_url' => 'javascript:alert(1)',
            'bubble_bg' => 'red;} body{display:none',
            'header_bg' => '#ABCDEF',
            'hide_on' => "/careers\r\n\r\n/jobs/*\n",
            'bubble_size' => 9999,
            'devices' => 'tv',
        ]])['chat'];

        $this->assertSame('', $chat['avatar_url']);
        $this->assertSame('', $chat['bubble_bg']);
        $this->assertSame('#abcdef', $chat['header_bg']);
        $this->assertSame("/careers\n/jobs/*", $chat['hide_on']);
        $this->assertSame(96, $chat['bubble_size']);
        $this->assertSame('all', $chat['devices']);
        $this->assertSame('https://x.test/a.jpg', WidgetDefaults::resolve(['chat' => ['avatar_url' => 'https://x.test/a.jpg']])['chat']['avatar_url']);
    }

    /**
     * ── When the chat bubble is on the page at all ──
     *
     * Jose, 2026-09-17: "it needs to be able to be set so it works only on
     * certain work hours, cause if no one is there to answer it's bad".
     *
     * The schedule is read in the BROWSER, because these sites serve from an
     * nginx page cache and a PHP decision bakes into the cached HTML. So what
     * this side has to get right is the shape that reaches the browser: seven
     * entries and a zone `Intl.DateTimeFormat` will accept, or nothing at all.
     */
    public function test_a_schedule_and_its_zone_reach_the_site(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['chat' => [
                    'hours' => $this->week(),
                    'timezone' => 'America/New_York',
                ]],
            ])->assertOk()->json();

        $hours = $body['config']['chat']['hours'];

        $this->assertCount(7, $hours);
        $this->assertSame('America/New_York', $body['config']['chat']['timezone']);
        $this->assertSame(['closed' => false, 'open' => '08:00', 'close' => '17:00'], $hours[0]);

        // A closed day carries times anyway, so the browser never reads a key
        // that is not there — it checks `closed` first and stops.
        $this->assertTrue($hours[5]['closed']);
    }

    /**
     * THE DEFAULT IS ALWAYS ON, and this is the assertion that keeps it.
     *
     * Every site on the estate has a chat today. A schedule defaulting to
     * office hours would take the bubble off a hundred websites at the next
     * plugin update, at whatever hour it landed, with nothing logged anywhere.
     * Narrowing is the direction nobody notices.
     */
    public function test_a_site_that_has_said_nothing_is_open_always(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $chat = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json('config.chat');

        $this->assertSame([], $chat['hours']);
        $this->assertSame('', $chat['timezone']);
    }

    /**
     * Half a gate is no gate, and the half must not travel.
     *
     * "Is it 08:00?" has no answer without saying where, so a schedule with no
     * zone cannot be read at all — and a zone with no schedule gates nothing.
     * Sending either half would leave the plugin deciding what to do with it,
     * which is a decision that belongs on this side, once.
     *
     */
    #[DataProvider('halfGates')]
    public function test_half_a_gate_is_sent_as_no_gate(array $chat): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $got = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", ['config' => ['chat' => $chat]])
            ->assertOk()
            ->json('config.chat');

        $this->assertSame([], $got['hours'], 'A schedule travelled without a usable zone.');
        $this->assertSame('', $got['timezone']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function halfGates(): array
    {
        $week = [
            ...array_fill(0, 5, ['closed' => false, 'open' => '08:00', 'close' => '17:00']),
            ['closed' => true],
            ['closed' => true],
        ];

        return [
            'no zone at all' => [['hours' => $week]],
            // Both look like a timezone to a person and neither is a name
            // Intl knows; an unknown one throws a RangeError on somebody's
            // website, which is why this is checked against PHP's own list
            // rather than against a pattern.
            'an abbreviation' => [['hours' => $week, 'timezone' => 'EST']],
            'a place' => [['hours' => $week, 'timezone' => 'Florida']],
            'a zone with no schedule' => [['timezone' => 'America/New_York']],
        ];
    }

    /**
     * A malformed schedule shows the bubble rather than hiding it.
     *
     * FAIL OPEN is the whole design, and the asymmetry is the argument: a
     * wrongly-shown chat is somebody replying late, and a wrongly-hidden one
     * is every enquiry that day going nowhere with nothing on any screen to
     * say so. So one bad day discards the week rather than being guessed at.
     *
     */
    #[DataProvider('malformedWeeks')]
    public function test_a_schedule_that_cannot_be_read_is_not_sent(mixed $hours): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $got = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['chat' => ['hours' => $hours, 'timezone' => 'America/New_York']],
            ])->assertOk()->json('config.chat');

        $this->assertSame([], $got['hours']);
    }

    /** @return array<string, array{mixed}> */
    public static function malformedWeeks(): array
    {
        $ok = ['closed' => false, 'open' => '08:00', 'close' => '17:00'];

        return [
            'six days' => [array_fill(0, 6, $ok)],
            'eight days' => [array_fill(0, 8, $ok)],
            // `8:00` rather than `08:00`. The regex in the browser wants two
            // digits and would read null, so it must never arrive.
            'an unpadded hour' => [[...array_fill(0, 6, $ok), ['closed' => false, 'open' => '8:00', 'close' => '17:00']]],
            'an hour past the clock' => [[...array_fill(0, 6, $ok), ['closed' => false, 'open' => '24:00', 'close' => '25:00']]],
            'a missing close' => [[...array_fill(0, 6, $ok), ['closed' => false, 'open' => '08:00']]],
            'not a list' => ['every day'],
        ];
    }

    /**
     * An overnight shift is a real shape, not a mistake to be corrected.
     *
     * 22:00–06:00 is two ranges around midnight, and the browser reads it as
     * such. Asserted here because the temptation on this side is to "fix" a
     * close time earlier than its open time, which would silently turn a
     * night-shift business's chat off for the eight hours it is actually
     * staffed.
     */
    public function test_a_shift_that_ends_after_midnight_survives(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $got = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['chat' => [
                    'hours' => array_fill(0, 7, ['closed' => false, 'open' => '22:00', 'close' => '06:00']),
                    'timezone' => 'America/New_York',
                ]],
            ])->assertOk()->json('config.chat');

        $this->assertSame('22:00', $got['hours'][0]['open']);
        $this->assertSame('06:00', $got['hours'][0]['close']);
    }

    public function test_a_site_with_no_config_of_its_own_gets_the_defaults(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertFalse($body['own']);

        // On, with nothing saved. There is no off any more: placing the widget
        // is what shows it (Jose, 2026-09-15).
        $this->assertTrue($body['config']['chat']['enabled']);
        $this->assertSame('#0b5cad', $body['config']['style']['accent']);
        $this->assertCount(4, $body['config']['form']['fields']);
    }

    public function test_the_questions_and_the_look_are_saved_and_read_back(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => [
                    'chat' => ['title' => 'Talk to A-1'],
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

        $response = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk();
        $body = $response->json();

        // {} not []: the screen casts it to a Map and stalled on a List (11-13).
        $this->assertStringContainsString('"inherited":{}', $response->getContent());
        $this->assertTrue($body['own']);
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
                'config' => ['style' => ['accent' => '#123456'], 'chat' => ['title' => 'Talk to A-1']],
            ])->assertOk();

        $followed = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$branch['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertFalse($followed['own'], 'The branch has no config of its own.');
        $this->assertSame('#123456', $followed['config']['style']['accent']);
        $this->assertSame('Talk to A-1', $followed['config']['chat']['title']);

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
            'config' => ['chat' => ['title' => 'Talk to A-1']],
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

        $viewer = $this->userWithRole($tenant, 'Web Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

        $this->signedInAs($viewer)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk();

        /*
         * THE SENTENCE, not just the status. This route is behind
         * `web_management.widgets.view` as well as behind `edit`, and both
         * refuse with 403 — so `assertForbidden()` alone would keep passing if
         * the cause silently moved from one to the other, while this test's
         * name still claimed to be about view-versus-edit.
         *
         * Email's, on their own module: four of their reader tests were green
         * on a 403 that had migrated from `email.edit` to a new tab gate.
         * Nothing in a run can show that. The sentences differ where the
         * statuses do not — `Refusal::permission('change a website')` against
         * the middleware's 'open this tab' — so the message is the observable
         * the other cause cannot produce.
         *
         * HOW IT WAS VERIFIED, and NOT the way Email verified theirs. Taking
         * `widgets.view` off this role does not demonstrate anything here: the
         * GET above is tab-gated too, so the positive control reds on ITS
         * status first and the refusal below is never reached. Their reader
         * could still GET, because their tab gate sits only on the settings
         * routes.
         *
         * What works instead is swapping the expected sentence for the
         * middleware's — `open this tab` — and watching it go red. That proves
         * both halves: the assertion really reads the message, and the message
         * really is the edit refusal rather than the tab's. Done, red as
         * required, restored.
         *
         * AND IT COVERS MORE, which Email established by running both against
         * their two files rather than reasoning about it: removing the key reds
         * only the refusals whose positive control still passes — one test per
         * file for them — while swapping the sentence reds EVERY refusal in the
         * file, because it never depends on the role reaching the route. So
         * prefer it even where both work.
         */
        $this->signedInAs($viewer)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['style' => ['accent' => '#000000']],
            ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn ($said) => is_string($said) && str_contains($said, 'change a website'),
            );
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

        $viewer = $this->userWithRole($tenant, 'Web Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

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
    /**
     * Jose, 2026-09-25: "company wide preset but allowing per widget
     * override". The override is typed on the customer's page, so the plugin
     * checks it against these rules — the ones `resolve()` applies here.
     */
    public function test_the_plugin_is_sent_the_rules_an_override_is_checked_by(): void
    {
        ['site' => $site] = $this->site();

        $schema = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host='.$site['data']['host'])
            ->assertOk()
            ->json('widgets.schema');

        // Every widget with a preset, every option of it.
        $this->assertSame(array_keys(WidgetPresets::schema()), array_keys($schema));
        foreach (WidgetPresets::defaults() as $widget => $defaults) {
            $this->assertSame(array_keys($defaults), array_keys($schema[$widget]), $widget);
        }

        $this->assertSame(['type' => 'range', 'min' => 16, 'max' => 64], $schema['social_links']['size']);
        $this->assertSame(['type' => 'bool'], $schema['social_links']['labels']);
        $this->assertSame(['circle', 'square', 'rounded'], $schema['social_links']['shape']['choices']);
        $this->assertSame(['type' => 'text', 'max' => 80], $schema['cta']['text']);
    }

    private function site(): array
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
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
            'web_management.view', 'web_management.edit',
            'web_management.seo.view', 'web_management.widgets.view', 'organizations.edit',
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

    /**
     * The FORM's switch is still ignored, and old plugins still render it.
     *
     * Two things are being held at once. The form has no switch — placing the
     * shortcode is the decision — so a stored `false` from before 2026-09-15
     * must not go on hiding anything. And plugin 1.4.0 and earlier render
     * NOTHING when `enabled` is absent or false, and they may be installed on
     * sites outside the fleet, so the key has to keep arriving, true. Drop it
     * and the form on any such site goes dark with no error anywhere.
     *
     * The chat is the exception now, and it has its own tests below.
     */
    public function test_a_stored_off_switch_on_the_form_is_ignored_and_the_key_still_says_on(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => [
                    'chat' => ['title' => 'Talk to A-1'],
                    'form' => ['enabled' => false],
                ],
            ])->assertOk();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertTrue($body['config']['form']['enabled']);
        $this->assertSame('Talk to A-1', $body['config']['chat']['title']);
    }

    /**
     * A site nobody has configured has a chat. This is THE test of the default.
     *
     * The switch that was removed on 2026-09-15 failed in exactly this case:
     * every site was built correctly and the bubble appeared nowhere. A default
     * of true is the whole fix, so it gets an assertion of its own rather than
     * riding along inside a bigger one.
     */
    public function test_a_site_that_has_said_nothing_has_its_chat_on(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertTrue($body['config']['chat']['enabled']);
        $this->assertTrue($body['defaults']['chat']['enabled']);
    }

    /**
     * Switching the chat off takes the whole block out of what a website reads,
     * and leaves it in what the screen reads.
     *
     * The absence is the mechanism, not a detail of it: plugins 1.5.0 through
     * 1.11.0 do not read `chat.enabled`, and on 2026-09-18 that was the entire
     * fleet. They DO draw nothing when the block is missing. So this assertion
     * is the feature — if `chat` ever comes back in the plugin payload with
     * `enabled: false`, the switch silently stops working on every live site
     * and nothing else in the suite would notice.
     */
    public function test_a_chat_switched_off_is_absent_from_the_website_and_present_on_the_screen(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['chat' => ['enabled' => false, 'title' => 'Talk to A-1']],
            ])->assertOk();

        // What the editing screen gets: the block, and the off switch in it.
        $screen = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
            ->assertOk()
            ->json();

        $this->assertFalse($screen['config']['chat']['enabled']);
        $this->assertSame('Talk to A-1', $screen['config']['chat']['title']);

        // What the website gets: no chat at all, and everything else intact.
        $payload = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host='.$site['data']['host'])
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('chat', $payload['widgets']);
        $this->assertArrayHasKey('form', $payload['widgets']);
        $this->assertArrayHasKey('style', $payload['widgets']);
    }

    /** On, it looks exactly the way it always did — including for 1.4.0. */
    public function test_a_chat_left_on_still_carries_the_key_the_oldest_plugins_read(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $payload = $this->withToken($site['token'])
            ->getJson('/api/v1/web/plugin/values?host='.$site['data']['host'])
            ->assertOk()
            ->json();

        $this->assertTrue($payload['widgets']['chat']['enabled']);
    }

    /**
     * A visitor cannot open a conversation on a site whose chat is off.
     *
     * Not covered by the absence above: a cached page, or a tab left open
     * since yesterday, still holds the widget and the public key. A switch
     * that leaves those able to file enquiries into a business that stopped
     * answering them has not disabled anything.
     *
     * 409 rather than 403 — nothing here is signed in, so a permission code
     * would be describing the wrong kind of refusal, and a test asserting 403
     * would pass for two different reasons.
     */
    public function test_a_visitor_cannot_start_a_chat_once_it_is_switched_off(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $key = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}")
            ->assertOk()
            ->json('data.public_key');

        // The positive control FIRST. Without it, the refusal below could be
        // arriving for any of the reasons this endpoint refuses.
        $this->postJson('/api/v1/web/chat', ['key' => $key, 'message' => 'Hello?'])
            ->assertCreated();

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['chat' => ['enabled' => false]],
            ])->assertOk();

        $this->postJson('/api/v1/web/chat', ['key' => $key, 'message' => 'Hello?'])
            ->assertStatus(409);
    }

    /**
     * ── Off EVERYWHERE means off everywhere ──────────────────────────
     *
     * Jose switched the live chat off on `a-1chimney.com` on 2026-09-23 and
     * went on receiving chats all day. Nothing was broken: the switch is per
     * SITE, A-1 Chimney has 47, and the other 46 followed a primary that was
     * still on. Measured after the fact, the chats were arriving from
     * a-1chimneysweep.com, ny.a-1chimney.com and chi.a-1chimney.com.
     *
     * THE ASSERTION THAT MATTERS is the site with its OWN config. Switching
     * the primary off would carry everything that follows it and silently miss
     * exactly those — which is the same bug one level up, and the reason this
     * writes a row per site rather than one row at the top.
     */
    public function test_switching_the_chat_off_for_a_company_reaches_every_site(): void
    {
        ['site' => $primary, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $follower = $this->registerSite($editor, $tenant, 'ny.a-1chimney.com');
        $ownConfig = $this->registerSite($editor, $tenant, 'chi.a-1chimney.com');

        // The one that makes this test worth writing: a site that answers for
        // itself, so nothing the primary says can reach it.
        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$ownConfig['data']['id']}/widgets", [
                'config' => ['chat' => ['title' => 'Chicago desk']],
            ])->assertOk();

        $body = $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$primary['data']['id']}/widgets/chat", [
                'enabled' => false,
                'scope' => 'company',
            ])->assertOk()->json();

        $this->assertSame(3, $body['changed']);

        foreach ([$primary, $follower, $ownConfig] as $site) {
            $chat = $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$site['data']['id']}/widgets")
                ->assertOk()
                ->json('config.chat');

            $this->assertFalse(
                $chat['enabled'],
                "{$site['data']['host']} still has the chat on.",
            );
        }

        // And the wording it already had is still there. A switch that wiped
        // the copy would make turning it back on a rewrite.
        $kept = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$ownConfig['data']['id']}/widgets")
            ->assertOk()
            ->json('config.chat.title');

        $this->assertSame('Chicago desk', $kept);
    }

    /** The narrow scope still exists, and still touches exactly one site. */
    public function test_switching_one_site_leaves_its_siblings_alone(): void
    {
        ['site' => $primary, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $sibling = $this->registerSite($editor, $tenant, 'ny.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$primary['data']['id']}/widgets/chat", [
                'enabled' => false,
                'scope' => 'site',
            ])->assertOk()->assertJsonPath('changed', 1);

        $this->assertTrue(
            $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$sibling['data']['id']}/widgets")
                ->assertOk()
                ->json('config.chat.enabled'),
        );
    }

    /** It goes back on the same way, or "off everywhere" is a one-way door. */
    public function test_the_company_switch_turns_it_back_on(): void
    {
        ['site' => $primary, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $follower = $this->registerSite($editor, $tenant, 'ny.a-1chimney.com');

        foreach ([false, true] as $wanted) {
            $this->signedInAs($editor)
                ->postJson("/api/v1/web/sites/{$primary['data']['id']}/widgets/chat", [
                    'enabled' => $wanted,
                    'scope' => 'company',
                ])->assertOk();

            $this->assertSame(
                $wanted,
                $this->signedInAs($editor)
                    ->getJson("/api/v1/web/sites/{$follower['data']['id']}/widgets")
                    ->assertOk()
                    ->json('config.chat.enabled'),
            );
        }
    }

    /**
     * A company-wide action is still a per-record permission.
     *
     * The scope decides how many sites are TOUCHED, never who may touch them —
     * a viewer reaching one endpoint and changing 47 websites would be the
     * worst version of this feature.
     */
    public function test_a_viewer_cannot_switch_the_chat_for_anything(): void
    {
        ['site' => $site, 'tenant' => $tenant] = $this->site();

        $viewer = $this->userWithRole($tenant, 'Web Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

        foreach (['site', 'company'] as $scope) {
            $this->signedInAs($viewer)
                ->postJson("/api/v1/web/sites/{$site['data']['id']}/widgets/chat", [
                    'enabled' => false,
                    'scope' => $scope,
                ])->assertForbidden();
        }
    }

    /**
     * The refresh stamp has to survive the next save.
     *
     * `WidgetDefaults::resolve()` keeps only the keys it knows, so a stamp
     * written inside the resolved shape would be dropped the next time
     * somebody edited anything — and the button would stop working with no
     * error, which is the failure nobody reports.
     */
    public function test_asking_the_sites_to_refresh_marks_every_one(): void
    {
        ['site' => $primary, 'editor' => $editor, 'tenant' => $tenant] = $this->site();

        $follower = $this->registerSite($editor, $tenant, 'ny.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$primary['data']['id']}/widgets/refresh", [
                'scope' => 'company',
            ])->assertOk()->assertJsonPath('asked', 2);

        foreach ([$primary, $follower] as $site) {
            // withoutGlobalScopes: the stamp lives OUTSIDE the resolved
            // shape, so no API response carries it and the row is the only
            // place it can be read. The tenant guard is right to stop an
            // unscoped read that has not said so.
            $row = WebWidget::withoutGlobalScopes()
                ->where('site_id', $site['data']['id'])
                ->first();

            $this->assertNotNull($row);
            $this->assertArrayHasKey(
                'refreshed_at',
                $row->config,
                "{$site['data']['host']} was not stamped, so its plugin sees no change.",
            );
        }
    }

    public function test_the_refresh_stamp_survives_an_ordinary_save(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$site['data']['id']}/widgets/refresh", ['scope' => 'site'])
            ->assertOk();

        $before = WebWidget::withoutGlobalScopes()->where('site_id', $site['data']['id'])->first()->config['refreshed_at'];

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['chat' => ['title' => 'Anything']],
            ])->assertOk();

        $after = WebWidget::withoutGlobalScopes()->where('site_id', $site['data']['id'])->first()->config;

        $this->assertArrayHasKey(
            'refreshed_at',
            $after,
            'An ordinary save dropped the stamp, so the refresh button stops working silently.',
        );
        $this->assertSame($before, $after['refreshed_at']);
    }

    /** Presets always carry a default, whatever is stored, so a typo renders. */
    public function test_every_preset_family_has_a_default_even_when_nothing_is_stored(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['presets' => ['social_links' => ['footer' => ['size' => 999]]]],
            ])->assertOk()->json();

        foreach (array_keys(WidgetPresets::schema()) as $widget) {
            $this->assertArrayHasKey('default', $body['config']['presets'][$widget]);
        }

        // Named presets survive, and a number outside the range is clamped
        // rather than refused: this renders on a customer's page, and a saved
        // config that fails to load would take the widget with it.
        $this->assertSame(64, $body['config']['presets']['social_links']['footer']['size']);
    }

    /**
     * ── The three that every A1 Tools page carries ──
     *
     * Jose walked me through a-1chimney.com, everdoors.com and crestln.com on
     * 2026-09-23. Contact Info, the CTA and the standalone phone are on every
     * page of all three, which is why they are the first of the fourteen.
     */
    public function test_the_contact_block_travels_with_its_arrangement(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['presets' => ['contact_info' => [
                    'default' => ['layout' => 'row', 'icons' => false],
                    'header' => ['layout' => 'compact'],
                ]]],
            ])->assertOk()->json('config.presets.contact_info');

        $this->assertSame('row', $body['default']['layout']);
        $this->assertFalse($body['default']['icons']);
        $this->assertSame('compact', $body['header']['layout']);

        // The keys nobody set still arrive, because the plugin reads the
        // bundle whole and a missing key there is a missing feature. `phone`
        // rather than the `show` CSV this asserted first: three flags replaced
        // it before either shipped, because a comma-separated list needs a
        // text box on the screen and a parser in the plugin to say what three
        // switches say for nothing.
        $this->assertArrayHasKey('phone', $body['header']);
        $this->assertTrue($body['header']['phone']);
    }

    /**
     * A bar and a popup cover part of somebody's page, so neither is default.
     *
     * The value of this assertion is the DEFAULT rather than the clamping: a
     * widget that started covering the page the moment it was pasted in would
     * be a surprise on a live site, and the surprise would arrive on all 110
     * at once.
     */
    public function test_the_call_to_action_sits_inline_until_somebody_says_otherwise(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $chosen = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['presets' => ['cta' => ['sticky' => ['placement' => 'bar']]]],
            ])->assertOk()->json('config.presets.cta');

        $this->assertSame('inline', $chosen['default']['placement']);
        $this->assertSame('bar', $chosen['sticky']['placement']);
    }

    /**
     * The review faces' wording is free text: it must survive a save, lose
     * any markup, and stop at its cap. A text key with no TEXT entry comes
     * back as the default every time, silently.
     */
    public function test_review_faces_wording_is_kept_cleaned_and_capped(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $faces = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['presets' => ['review_faces' => ['default' => [
                    'title' => 'Rate <b>us</b>',
                    'happy_label' => str_repeat('y', 50),
                    'ask_public' => false,
                    'tone' => 'neon',
                    'face_size' => 500,
                ]]]],
            ])->assertOk()->json('config.presets.review_faces.default');

        $this->assertSame('Rate us', $faces['title']);
        $this->assertSame(30, mb_strlen($faces['happy_label']));
        $this->assertFalse($faces['ask_public']);
        $this->assertSame('light', $faces['tone']);
        $this->assertSame(120, $faces['face_size']);
        // Untouched wording keeps its default, so a site that sets one line
        // does not blank the rest.
        $this->assertSame('Not so great', $faces['sad_label']);
    }

    public function test_an_arrangement_nobody_offers_becomes_the_default(): void
    {
        ['site' => $site, 'editor' => $editor] = $this->site();

        $body = $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/widgets", [
                'config' => ['presets' => [
                    'contact_info' => ['default' => ['layout' => 'carousel']],
                    'cta' => ['default' => ['placement' => 'takeover']],
                    'phone_box' => ['default' => ['size' => 'enormous']],
                ]],
            ])->assertOk()->json('config.presets');

        // Clamped to the default rather than refused. This renders on a
        // customer's page and a config that fails to load takes the widget
        // with it — the same rule the sizes follow.
        $this->assertSame('stack', $body['contact_info']['default']['layout']);
        $this->assertSame('inline', $body['cta']['default']['placement']);
        $this->assertSame('medium', $body['phone_box']['default']['size']);
    }
}
