<?php

namespace Tests\Feature\Web;

use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebSite;
use App\Support\Modules\ModuleRegistry;
use App\Support\Web\SeoFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Web Management — the sites a company owns and what its plugin may read.
 *
 * This module left the coming-soon set on 2026-08-28, the way Fleet, Inventory,
 * Phone and Services did before it, so the module-shape assertions that used to
 * live in ComingSoonModulesTest live here now — including the registry ORDER
 * check, which is about the whole sidebar and was never about being unbuilt.
 *
 * The tests worth having are the ones about the two things that can silently go
 * wrong on somebody's live website: a token that reads the wrong site's values,
 * and a host that does not match the form WordPress reports.
 */
class WebManagementTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── 1. the module, its permissions, and its place in the rail ────

    public function test_the_module_offers_the_tab_and_the_edit_permission(): void
    {
        $module = app(ModuleRegistry::class)->get('web_management');

        $this->assertNotNull($module);
        $this->assertSame('Web Management', $module->label);
        $this->assertSame(
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
            $module->permissionKeys(),
        );
        /*
         * This module used to be automation-exempt on the grounds that nothing
         * here happens on its own. The chat and form widgets ended that: a
         * visitor starting a conversation is an event, and rules can act on it.
         * The subject is the SITE, so a rule can name which website.
         */
        $this->assertNull($module->automationExemptBecause);
        /*
         * `website.went_quiet` joined them when plugin 1.9.0 made a refused
         * site take its chat and form down at once: it stops collecting
         * enquiries and says so only in a WordPress notice nobody reads. Who
         * hears about that is a question about the business, so it is a trigger
         * rather than a notification with a recipient I chose.
         */
        $this->assertSame(
            ['website.chat_started', 'website.form_submitted', 'website.went_quiet'],
            array_map(fn ($trigger) => $trigger->key, $module->triggers),
        );
    }

    public function test_the_tab_sits_after_marketing_and_before_phone(): void
    {
        $keys = array_keys(app(ModuleRegistry::class)->all());

        $this->assertLessThan(
            array_search('web_management', $keys, true),
            array_search('marketing', $keys, true),
        );
        $this->assertLessThan(
            array_search('phone', $keys, true),
            array_search('web_management', $keys, true),
        );
    }

    // ── 2. hosts arrive in every shape a person can type ────────────

    /** @return list<array{0: string, 1: string}> */
    public static function hosts(): array
    {
        return [
            'plain' => ['a-1chimney.com', 'a-1chimney.com'],
            'scheme' => ['https://a-1chimney.com', 'a-1chimney.com'],
            'scheme and path' => ['https://a-1chimney.com/contact', 'a-1chimney.com'],
            'www' => ['www.a-1chimney.com', 'a-1chimney.com'],
            'shouting' => ['HTTPS://NJ.A-1Chimney.COM/', 'nj.a-1chimney.com'],
            'port' => ['a-1chimney.com:8443', 'a-1chimney.com'],
            'trailing dot' => ['a-1chimney.com.', 'a-1chimney.com'],
            'padded' => ['  a-1chimney.com  ', 'a-1chimney.com'],
        ];
    }

    #[DataProvider('hosts')]
    public function test_a_host_is_stored_in_one_form_however_it_was_typed(
        string $typed,
        string $stored,
    ): void {
        $this->assertSame($stored, WebSite::normalizeHost($typed));
    }

    public function test_a_subdomain_is_a_separate_site_from_its_parent(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $parent = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->assertNotSame($parent['data']['id'], $branch['data']['id']);
        $this->assertNotSame($parent['token'], $branch['token']);
    }

    public function test_the_same_host_cannot_be_registered_twice(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            // The same site, typed the way the second person would type it.
            'host' => 'https://WWW.a-1chimney.com/',
        ])->assertStatus(422)->assertJsonValidationErrors('host');
    }

    public function test_a_sentence_is_not_a_website_address(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            'host' => 'our website',
        ])->assertStatus(422)->assertJsonValidationErrors('host');
    }

    // ── 3. the token is shown twice and stored never ────────────────

    public function test_the_token_comes_back_once_and_is_never_readable_again(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $created = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $token = $created['token'];
        $id = $created['data']['id'];

        $this->assertNotEmpty($token);

        $shown = $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$id}")
            ->assertOk()->json('data');

        $this->assertArrayNotHasKey('token', $shown);
        $this->assertArrayNotHasKey('token_hash', $shown);

        $stored = $this->inTenant($tenant['org'], fn () => WebSite::find($id));

        $this->assertNotSame($token, $stored->token_hash);
        $this->assertSame(hash('sha256', $token), $stored->token_hash);
    }

    public function test_rotating_the_token_retires_the_old_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $created = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $old = $created['token'];

        $new = $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$created['data']['id']}/token")
            ->assertOk()->json('token');

        $this->assertNotSame($old, $new);
        $this->pluginRead($new, 'a-1chimney.com')->assertOk();
        $this->pluginRead($old, 'a-1chimney.com')->assertUnauthorized();
    }

    // ── 4. what the plugin gets, and what it is refused ─────────────

    public function test_the_plugin_reads_the_values_set_for_its_own_site(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $parent = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->setValues($editor, $parent['data']['id'], ['phone' => '555-0100']);
        $this->setValues($editor, $branch['data']['id'], ['phone' => '555-0200']);

        $this->assertSame(
            '555-0100',
            $this->pluginRead($parent['token'], 'a-1chimney.com')->assertOk()->json('values.phone'),
        );
        $this->assertSame(
            '555-0200',
            $this->pluginRead($branch['token'], 'nj.a-1chimney.com')->assertOk()->json('values.phone'),
        );
    }

    public function test_a_token_carried_to_another_host_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->pluginRead($site['token'], 'someone-elses-site.com')
            ->assertUnauthorized()
            ->assertJsonFragment(['message' => 'This token belongs to a-1chimney.com, not someone-elses-site.com. Register someone-elses-site.com in EzBizi to give it its own token.']);
    }

    public function test_a_plugin_that_reports_www_is_still_the_same_site(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->pluginRead($site['token'], 'https://www.a-1chimney.com')->assertOk();
    }

    public function test_no_token_and_a_wrong_token_are_both_refused(): void
    {
        $this->getJson('/api/v1/web/plugin/values')->assertUnauthorized();
        $this->pluginRead('ezb_nonsense', 'a-1chimney.com')->assertUnauthorized();
    }

    public function test_a_successful_read_records_the_connection(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $id = $site['data']['id'];

        $this->assertNull(
            $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$id}")->json('data.connected_at'),
        );

        $this->getJson(
            '/api/v1/web/plugin/values?host=a-1chimney.com&plugin_version=1.0.0',
            ['Authorization' => 'Bearer '.$site['token']],
        )->assertOk();

        $after = $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$id}")->json('data');

        $this->assertNotNull($after['connected_at']);
        $this->assertSame('1.0.0', $after['plugin_version']);

        // Leads' Website panel (2026-10-29): null until a plugin reports its booking widget,
        // then what it said; an older plugin that sends nothing leaves it as it was.
        $booking = fn () => $this->inTenant($tenant['org'], fn () => \App\Models\WebSite::query()->findOrFail($id)->has_booking);
        $this->assertNull($booking());
        $this->getJson('/api/v1/web/plugin/values?host=a-1chimney.com&plugin_version=1.26.23&has_booking=1',
            ['Authorization' => 'Bearer '.$site['token']])->assertOk();
        $this->assertTrue($booking());
        $this->getJson('/api/v1/web/plugin/values?host=a-1chimney.com&plugin_version=1.0.0',
            ['Authorization' => 'Bearer '.$site['token']])->assertOk();
        $this->assertTrue($booking());
    }

    // ── 5. values: blank clears, unknown keys are refused ───────────

    public function test_blanking_a_value_removes_it_rather_than_publishing_an_empty_string(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->setValues($editor, $site['data']['id'], ['phone' => '555-0100']);
        $this->setValues($editor, $site['data']['id'], ['phone' => '']);

        $values = $this->pluginRead($site['token'], 'a-1chimney.com')->assertOk()->json('values');

        $this->assertArrayNotHasKey('phone', $values);
    }

    public function test_a_key_this_build_cannot_render_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'values' => ['fax_machine' => '555-0199'],
            ])
            ->assertStatus(422);
    }

    // ── 6. the plugin download ──────────────────────────────────────

    public function test_the_plugin_downloads_as_a_zip_wordpress_can_install(): void
    {
        if (! class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip is not installed here.');
        }

        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

        $response = $this->signedInAs($viewer)->get('/api/v1/web/plugin')->assertOk();

        $path = tempnam(sys_get_temp_dir(), 'dl').'.zip';
        file_put_contents($path, $response->streamedContent());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'The download was not a readable zip.');

        /*
         * WordPress installs the FOLDER inside the zip, so the entry has to be
         * `ezbizi/ezbizi.php` and not a bare `ezbizi.php`. A flat zip installs
         * as a plugin named after whichever file came first, which is the kind
         * of thing nobody notices until a customer's site has it.
         */
        $this->assertNotFalse(
            $zip->locateName('ezbizi/ezbizi.php'),
            'The zip does not contain ezbizi/ezbizi.php.',
        );

        $this->assertStringContainsString(
            'Plugin Name:',
            (string) $zip->getFromName('ezbizi/ezbizi.php'),
            'The plugin file has no WordPress header, so WordPress will not list it.',
        );

        /*
         * readme.txt is not optional once this plugin is listed on
         * wordpress.org — the directory parses it for the version, the
         * description and, above all, the external-service disclosure that
         * says what leaves a customer's server. A zip that reaches somebody
         * without it is a different package from the one under review.
         */
        $readme = (string) $zip->getFromName('ezbizi/readme.txt');

        $this->assertNotSame('', $readme, 'The zip has no readme.txt.');
        /*
         * The three version numbers must AGREE, which is the invariant — not
         * that they equal any particular literal. `Stable tag` tells
         * wordpress.org which tag to serve, so a stable tag pointing at a
         * version the plugin header does not claim ships one version's code
         * under another version's name, and a `EZBIZI_VERSION` that disagrees
         * makes every site report the wrong version back to us.
         *
         * This used to assert `Stable tag: 1.0.0` and would have failed on the
         * first release after that for no reason but its own literal.
         */
        $plugin = (string) $zip->getFromName('ezbizi/ezbizi.php');

        /*
         * `[^
]+` rather than `(\S+)$`, because `$` in multiline mode
         * matches before `
` and NOT before `
`. The first version of
         * this test passed on an LF checkout and failed on a CRLF one holding
         * byte-identical content — which on Windows is decided by git's
         * autocrlf, so the same commit was green in a worktree and red in the
         * primary tree. Reported by another session as a red on main; it was
         * mine, introduced with these assertions in 50c30b0.
         */
        $this->assertSame(
            1,
            preg_match('/^Stable tag:[ \t]*([^\r\n]+)/m', $readme, $stable),
        );
        $this->assertSame(
            1,
            preg_match('/^ \* Version:[ \t]*([^\r\n]+)/m', $plugin, $header),
        );
        $this->assertSame(
            1,
            preg_match("/EZBIZI_VERSION = '([^']+)'/", $plugin, $constant),
        );

        $header[1] = trim($header[1]);
        $stable[1] = trim($stable[1]);

        $this->assertSame(
            $header[1],
            $stable[1],
            'The readme serves a tag the plugin header does not claim to be.',
        );
        $this->assertSame(
            $header[1],
            $constant[1],
            'EZBIZI_VERSION disagrees with the header, so installs report the '
            .'wrong version to the API.',
        );
        $this->assertStringContainsString('== External Services ==', $readme);

        /*
         * The disclosure has to name the token and the hostname specifically.
         * "Connects to an API" is the wording that gets a plugin rejected, and
         * it is also not true enough to be worth saying.
         */
        $this->assertStringContainsStringIgnoringCase('site token', $readme);
        $this->assertStringContainsStringIgnoringCase('hostname', $readme);

        // The reviewer wants the endpoint named, not "an API". A1 Tools was
        // approved with this line and it is the format being copied here.
        $this->assertStringContainsString(
            'https://app.ezbizi.com/api/v1/web/plugin/values',
            $readme,
        );

        /*
         * The version wordpress.org rejected was 6.8, and this line is the
         * only thing standing between a stale value and a second rejection.
         * `c0a0df8` put 6.8 back in a stale whole-file commit and the suite
         * stayed green, because it reverted the readme and this assertion
         * together — consistent with each other and wrong about the world.
         * Pinning the number here means the next such revert goes red.
         */
        $this->assertStringContainsString('Tested up to: 7.1', $readme);

        $this->assertNotFalse(
            $zip->locateName('ezbizi/uninstall.php'),
            'Deleting the plugin must take the stored token with it.',
        );

        $zip->close();
        @unlink($path);
    }

    public function test_a_role_without_the_tab_cannot_download_the_plugin(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $stranger = $this->userWithRole($tenant, 'Stranger', ['customers.view']);

        $this->signedInAs($stranger)->get('/api/v1/web/plugin')->assertForbidden();
    }

    /**
     * The count beside each website: General fields it publishes nothing for.
     *
     * A value inherited from the primary is published, so it is not missing;
     * the composed address and the website address fill themselves in.
     */
    public function test_each_site_says_how_many_general_fields_it_is_missing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $main = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');
        $this->signedInAs($editor)->postJson("/api/v1/web/sites/{$main['data']['id']}/primary")->assertOk();

        $general = count(array_filter(SeoFields::catalogue(), fn (array $f) => $f['section'] === SeoFields::GENERAL));
        $counted = $general - 2; // address and website_url fill themselves in

        $this->setValues($editor, $main['data']['id'], ['company_name' => 'A-1', 'phone' => '555-0100', 'facebook' => 'https://facebook.com/a1']);
        $this->setValues($editor, $branch['data']['id'], ['city' => 'Newark', 'phone' => '']);

        $missing = collect($this->signedInAs($editor)->getJson('/api/v1/web/sites')->assertOk()->json('data'))
            ->pluck('missing', 'host');

        // The social link does not count either way.
        $this->assertSame($counted - 2, $missing['a-1chimney.com']);
        // Inherits both, adds a city; the cleared phone does not hide the primary's.
        $this->assertSame($counted - 3, $missing['nj.a-1chimney.com']);
    }

    /** Audit 01a1ef57: the list says when details last changed, to compare with the site's last read. */
    public function test_each_site_says_when_its_details_were_last_saved(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $saved = $this->registerSite($editor, $tenant, 'a-1chimney.com')['data']['id'];
        $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->travelTo(now()->startOfMinute());
        $this->setValues($editor, $saved, ['phone' => '555-0100']);

        $at = collect($this->signedInAs($editor)->getJson('/api/v1/web/sites')->assertOk()->json('data'))
            ->pluck('values_saved_at', 'host');

        $this->assertSame(now()->utc()->toIso8601String(), $at['a-1chimney.com']);
        $this->assertNull($at['nj.a-1chimney.com']);
    }

    public function test_a_field_marked_not_needed_stops_counting_and_can_be_undone(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com')['data']['id'];
        $count = fn () => collect($this->signedInAs($editor)->getJson('/api/v1/web/sites')->json('data'))->first()['missing'];
        $before = $count();

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/values", ['not_needed' => ['city', 'postal_code']])
            ->assertOk()->assertJsonPath('data.not_needed', ['city', 'postal_code']);
        $this->assertSame($before - 2, $count());
        $this->signedInAs($editor)->getJson("/api/v1/web/sites/{$site}/values")->assertJsonPath('data.not_needed', ['city', 'postal_code']);

        // A save of values alone leaves the marks; an empty list clears them.
        $this->setValues($editor, $site, ['phone' => '555-0100']);
        $this->assertSame($before - 3, $count());
        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/values", ['not_needed' => []])->assertOk();
        $this->assertSame($before - 1, $count());

        $this->signedInAs($editor)->putJson("/api/v1/web/sites/{$site}/values", ['not_needed' => ['nonsense']])->assertStatus(422);
    }

    // ── 7. §4: permissions and tenants ──────────────────────────────

    public function test_a_viewer_can_read_sites_and_cannot_change_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->signedInAs($viewer)->getJson('/api/v1/web/sites')->assertOk()
            ->assertJsonCount(1, 'data');

        $this->signedInAs($viewer)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            'host' => 'tx.a-1chimney.com',
        ])->assertForbidden();

        $this->signedInAs($viewer)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'values' => ['phone' => '555-0100'],
            ])->assertForbidden();
    }

    public function test_a_role_without_the_tab_sees_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $stranger = $this->userWithRole($tenant, 'Stranger', ['customers.view']);

        $this->signedInAs($stranger)->getJson('/api/v1/web/sites')->assertForbidden();
    }

    public function test_one_organization_cannot_see_or_read_anothers_site(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $beta = $this->provisionTenant('Beta');

        $alphaEditor = $this->editorFor($alpha);
        $betaEditor = $this->editorFor($beta);

        $alphaSite = $this->registerSite($alphaEditor, $alpha, 'a-1chimney.com');

        $this->signedInAs($betaEditor)->getJson('/api/v1/web/sites')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->signedInAs($betaEditor)
            ->getJson("/api/v1/web/sites/{$alphaSite['data']['id']}")
            ->assertNotFound();
    }

    /**
     * The plugin's own behaviour, run against a stub WordPress.
     *
     * Everything else in this file tests EzBizi's end and never loads one line
     * of the plugin — so until this ran, the first person to execute ezbizi.php
     * would have been a wordpress.org reviewer. Shelling out rather than
     * inlining it: the harness defines two dozen global functions named
     * `get_option`, `esc_html` and so on, which must not exist in this process.
     *
     * See wordpress-plugin/tests/plugin-check.php for what it does and does not
     * cover, and why it is not a real WordPress install.
     */
    // ── the snippet has to be reachable, not merely shipped ─────────

    /**
     * The snippet is served by a ROUTE, and these tests exist because shipping
     * the file was not enough.
     *
     * `public/ezbizi.js` deployed perfectly and was unreachable on every
     * release: nginx roots app.ezbizi.com at the Flutter build and sends only
     * `/api` to Laravel, so Laravel's `public/` is not a web root here. The
     * docs handed customers a URL that answered 404 and nothing failed — no
     * test, no gate, no deploy check. It was found by somebody reading the
     * nginx config.
     */
    public function test_the_browser_snippet_is_actually_served(): void
    {
        $response = $this->get('/api/v1/web/ezbizi.js')->assertOk();

        $this->assertStringContainsString(
            'application/javascript',
            (string) $response->headers->get('Content-Type'),
            'A script tag will not execute a response served as something else.',
        );

        $body = $response->getContent();

        $this->assertStringContainsString('data-ezbizi', (string) $body);
        $this->assertStringContainsString('textContent', (string) $body);
    }

    /**
     * The URL the documentation gives must be the URL that exists.
     *
     * This is the assertion that would have caught the original defect, and it
     * is the general form of it: a promise made to a customer in prose is not
     * checked by anything unless something checks it. Reading the doc rather
     * than hardcoding the path means the two cannot drift apart silently —
     * change the route without the doc and this goes red.
     */
    public function test_the_documented_snippet_url_is_the_one_that_works(): void
    {
        $doc = file_get_contents(base_path('docs/WEB-MANAGEMENT-WITHOUT-WORDPRESS.md'));

        $this->assertIsString($doc);
        $this->assertSame(
            1,
            preg_match('#<script src="https://app\.ezbizi\.com(/[^"]+)"#', $doc, $found),
            'The doc no longer shows a script tag, so this guard cannot check it.',
        );

        $this->get($found[1])->assertOk();
    }

    /**
     * `public/` is not served on this host, so a file there is a file nobody
     * can fetch. Somebody tidying the snippet "back where static assets go"
     * would reintroduce the exact bug, and the move is invisible in review.
     */
    public function test_the_snippet_does_not_move_back_into_the_unserved_directory(): void
    {
        $this->assertFileExists(resource_path('snippet/ezbizi.js'));
        $this->assertFileDoesNotExist(
            public_path('ezbizi.js'),
            'public/ is not a web root on app.ezbizi.com — see SnippetController.',
        );
    }

    /**
     * A missing key and a wrong key are different questions, and answering both
     * with 404 cost a colleague a misdiagnosis: they read
     * `404 {"message":"That key is not valid."}` on a keyless request and
     * concluded the route was missing. Same status, opposite meaning.
     */
    public function test_a_request_with_no_key_is_told_so_rather_than_404(): void
    {
        $this->getJson('/api/v1/web/public/values')
            ->assertStatus(400)
            ->assertJsonFragment(['message' => 'No key was given. Add ?key=pk_... from Web Management.']);

        $this->getJson('/api/v1/web/public/values?key=pk_nonsense')
            ->assertStatus(404)
            ->assertJsonFragment(['message' => 'That key is not valid.']);
    }

    public function test_the_plugin_behaves_when_actually_executed(): void
    {
        $script = base_path('wordpress-plugin/tests/plugin-check.php');

        $this->assertFileExists($script);

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' 2>&1', $output, $status);

        $report = implode("\n", $output);

        $this->assertSame(0, $status, "The WordPress plugin failed its own checks:\n".$report);
        $this->assertStringContainsString('PASS', $report);
    }

    // ── 7. the public key, for sites with nowhere to keep a secret ──

    public function test_every_site_gets_a_public_key_that_is_not_the_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $created = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $key = $created['data']['public_key'];

        $this->assertStringStartsWith('pk_', $key);
        $this->assertNotSame($created['token'], $key);
        $this->assertStringStartsWith('ezb_', $created['token']);

        // Unlike the token, it comes back on an ordinary read — it is meant to
        // be pasted into a public page, so hiding it helps nobody.
        $this->assertSame(
            $key,
            $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$created['data']['id']}")
                ->assertOk()->json('data.public_key'),
        );
    }

    public function test_two_sites_never_share_a_public_key(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $one = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $two = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->assertNotSame($one['data']['public_key'], $two['data']['public_key']);
    }

    public function test_a_browser_reads_values_with_the_public_key_and_no_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $this->setValues($editor, $site['data']['id'], ['phone' => '555-0100']);

        $body = $this->getJson(
            '/api/v1/web/public/values?key='.$site['data']['public_key'],
        )->assertOk();

        $this->assertSame('555-0100', $body->json('values.phone'));
        $this->assertNotEmpty($body->json('fields'));
    }

    /**
     * The point of a separate endpoint rather than reusing the plugin's.
     *
     * The authenticated response names the host, the label and the company. A
     * key scraped from somebody's page source must not turn into a map of which
     * businesses use EzBizi and how their sites are grouped.
     */
    public function test_the_public_endpoint_never_names_the_company_or_the_host(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $this->setValues($editor, $site['data']['id'], ['phone' => '555-0100']);

        $body = $this->getJson('/api/v1/web/public/values?key='.$site['data']['public_key'])
            ->assertOk()->json();

        // `widgets` joined this list when the chat and form widgets arrived: a
        // site that is not WordPress needs the same look and the same questions
        // as one that is, and the config names neither a company nor a host.
        $this->assertSame(['fields', 'widgets', 'values', 'pages'], array_keys($body));
        $this->assertStringNotContainsString('a-1chimney.com', json_encode($body));
        $this->assertStringNotContainsString('Alpha Co', json_encode($body));
    }

    public function test_a_page_override_reaches_the_browser_too(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $this->setValues($editor, $site['data']['id'], ['phone' => '555-0100']);

        $this->signedInAs($editor)->putJson(
            "/api/v1/web/sites/{$site['data']['id']}/values",
            ['path' => '/contact', 'values' => ['phone' => '555-0999']],
        )->assertOk();

        $body = $this->getJson('/api/v1/web/public/values?key='.$site['data']['public_key'])
            ->assertOk();

        $this->assertSame('555-0100', $body->json('values.phone'));
        $this->assertSame('555-0999', $body->json('pages./contact.phone'));
    }

    /**
     * A key that never existed and one that was rotated away answer IDENTICALLY.
     *
     * That is the whole oracle guarantee: a caller holding a harvested key must
     * not be able to learn whether it was ever real, so "wrong" and "retired"
     * are one answer.
     *
     * This test used to assert that a MISSING key answered the same way too,
     * which was the rule drawn too wide. Nobody sending no key is probing
     * anything, and merging that case cost a colleague a misdiagnosis — see
     * test_a_request_with_no_key_is_told_so_rather_than_404. The oracle applies
     * between two keys, not between a key and its absence.
     */
    public function test_a_wrong_key_and_a_retired_key_answer_the_same_way(): void
    {
        $tenant = $this->provisionTenant('Oracle');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'rotated.example.com');

        $retired = $site['data']['public_key'];

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$site['data']['id']}/public-key")
            ->assertOk();

        $never = $this->getJson('/api/v1/web/public/values?key=pk_nonsense')->assertNotFound();
        $was = $this->getJson('/api/v1/web/public/values?key='.$retired)->assertNotFound();

        $this->assertSame($never->json('message'), $was->json('message'));
    }

    public function test_the_browser_response_is_cacheable_and_cross_origin(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->getJson('/api/v1/web/public/values?key='.$site['data']['public_key'])
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Cache-Control', 'max-age=300, public');
    }

    public function test_the_token_still_does_not_work_as_a_public_key(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        // The two credentials are not interchangeable in either direction.
        $this->getJson('/api/v1/web/public/values?key='.$site['token'])->assertNotFound();
        $this->pluginRead($site['data']['public_key'], 'a-1chimney.com')->assertUnauthorized();
    }

    // ── 8. an empty site answers with an OBJECT, never an array ─────

    /**
     * The first-run deadlock, and the test that would have caught it.
     *
     * `pluck()->all()` returns a PHP array. Populated, json_encode writes an
     * object; EMPTY, it writes `[]`. So this endpoint answered with two
     * different TYPES for `values` depending only on whether anybody had filled
     * the form in yet, and the Flutter panel — which casts it to a Map — threw
     * on the empty case. The exception escaped before the loading flag could be
     * cleared, so the panel span forever: you could not open it until the site
     * had values, and you could not add values without opening it.
     *
     * Every newly registered site hit this. ezbizi.com, the first real one, hit
     * it immediately. Asserting on the ENCODED JSON rather than the decoded
     * array is the whole point — PHP cannot tell the two apart after decoding,
     * which is exactly why it shipped.
     *
     * @return list<array{0: string}>
     */
    public static function valueEndpoints(): array
    {
        return [
            'the panel' => ['panel'],
            'the plugin' => ['plugin'],
            'the browser' => ['public'],
        ];
    }

    #[DataProvider('valueEndpoints')]
    public function test_a_site_with_no_values_still_answers_with_an_object(string $which): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $json = match ($which) {
            'panel' => $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$site['data']['id']}/values")
                ->assertOk()->content(),
            'plugin' => $this->pluginRead($site['token'], 'a-1chimney.com')
                ->assertOk()->content(),
            'public' => $this->getJson(
                '/api/v1/web/public/values?key='.$site['data']['public_key'],
            )->assertOk()->content(),
        };

        /*
         * `{` rather than `{}`, because the three endpoints now differ and
         * both answers are correct: the panel and the plugin derive
         * website_url from the host, so their map is never empty, while the
         * public one deliberately omits it and stays empty. What all three owe
         * is an OBJECT — which is the thing that broke the panel — so that is
         * what is asserted, and `"values":[]` is still refused below.
         */
        $this->assertStringContainsString('"values":{', $json,
            'An empty values map encoded as a JSON array, which the client cannot cast to a Map.');
        $this->assertStringContainsString('"pages":{}', $json,
            'An empty pages map encoded as a JSON array.');
        $this->assertStringNotContainsString('"values":[]', $json);
        $this->assertStringNotContainsString('"pages":[]', $json);
    }

    public function test_a_populated_site_still_answers_with_the_same_object_shape(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $this->setValues($editor, $site['data']['id'], ['phone' => '555-0100']);

        $json = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/values")
            ->assertOk()->content();

        /*
         * `website_url` is derived from the host on every read, so a populated
         * site carries it too. Asserted as a SUBSTRING of the object rather
         * than the whole map, because the point of this test is the {} vs []
         * encoding and pinning the exact contents would make it fail for every
         * field anybody ever adds.
         */
        // Populated was never broken; this holds the fix to not breaking it.
        $this->assertStringContainsString('"values":{"phone":"555-0100","website_url":"https:\\/\\/a-1chimney.com\\/"}', $json);
        $this->assertStringContainsString('"pages":{}', $json);
    }

    /**
     * `values` can no longer come back EMPTY — every site derives its own
     * website_url — so the assertion is that it is an OBJECT, which is the
     * thing that broke the panel. Kept rather than deleted: retiring it would
     * retire the guard against a map encoding as an array.
     */
    public function test_saving_the_first_value_returns_an_object_too(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        // The PUT returns the same payload as the GET, and its empty case is
        // reached by clearing the only value a site has.
        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$site['data']['id']}/values", [
                'values' => ['phone' => ''],
            ])
            ->assertOk()
            // An object, not an array. It is no longer EMPTY — website_url is
            // derived from the host on every read — and the encoding is what
            // this test has always been about.
            ->assertSee('"values":{"', escape: false);
    }

    /**
     * Every field says whether blank is a problem, and the answer is one of three.
     *
     * A blank `phone` and a blank `license` are opposite facts stored as the
     * same absent row, so without this the screen cannot draw an empty state
     * that is correct for both — it either lies about the missing phone or
     * nags forever about a licence that is never coming.
     */
    public function test_every_field_declares_whether_blank_is_a_problem(): void
    {
        $catalogue = SeoFields::catalogue();

        $this->assertNotEmpty($catalogue);

        foreach ($catalogue as $field) {
            $this->assertArrayHasKey(
                'requirement',
                $field,
                "{$field['key']} does not say whether blank is a problem.",
            );
            $this->assertContains(
                $field['requirement'],
                [SeoFields::REQUIRED, SeoFields::CONDITIONAL, SeoFields::OPTIONAL],
                "{$field['key']} declares a requirement nothing knows how to draw.",
            );
        }
    }

    /**
     * No field may be named after a shortcode the plugin already owns.
     *
     * The plugin registers `[ezbizi_city_list]` and its four siblings from its
     * own `widgets.php`, and THEN loops the catalogue this server sends,
     * registering `[ezbizi_<key>]` for every key in it. WordPress lets the
     * second registration win. So a field added here called `city_list` does
     * not collide loudly — it replaces a rendered list of towns with a plain
     * string on every site running the plugin, and the only symptom is
     * somebody's footer quietly going flat.
     *
     * This is the cheap half of that. The expensive half — ordering the two
     * loops, or namespacing one of them — buys nothing while the set of names
     * is five long and lives in this repo. The failure to catch is a field
     * added in innocence, and adding a field is a one-line change in a file
     * that says nothing about the plugin's own shortcodes.
     *
     * Found by the session that wrote those five widgets, which noted it in a
     * file header. A header is read by whoever is already in the file; this is
     * read by whoever is not.
     */
    public function test_no_field_is_named_after_a_shortcode_the_plugin_already_owns(): void
    {
        // Read off the plugin itself, so a shortcode it adds is covered the day
        // it lands. The catalogue loop (`'ezbizi_' . $key`) does not match.
        $owned = [];
        foreach (glob(base_path('wordpress-plugin/ezbizi/*.php')) as $file) {
            preg_match_all("/add_shortcode\(\s*'ezbizi_([a-z0-9_]+)'/", file_get_contents($file), $m);
            array_push($owned, ...$m[1]);
        }

        $taken = array_intersect(SeoFields::keys(), $owned);

        $this->assertSame(
            [],
            array_values($taken),
            'These field keys would each silently replace a rendered widget on '
            .'every site: '.implode(', ', $taken).'. Rename the field — the '
            .'shortcode name is already published and cannot move.',
        );

        // The positive control. Without it this passes just as happily against
        // an empty catalogue or a plugin that moved, which is the state a
        // refactor leaves behind.
        $this->assertNotEmpty(SeoFields::keys());
        $this->assertContains('city_list', $owned);
    }

    /**
     * The three that make a site unfinished when blank.
     *
     * Pinned deliberately: this is the assignment a screen's empty state is
     * built on, so moving a key between tiers should be a decision somebody
     * makes on purpose rather than a side effect of editing the catalogue.
     * The split is reasoning rather than usage data and is meant to change —
     * changing it should just cost this line too.
     */
    public function test_the_required_three_are_the_ones_a_site_cannot_publish_without(): void
    {
        $required = array_values(array_map(
            fn (array $f) => $f['key'],
            array_filter(
                SeoFields::catalogue(),
                fn (array $f) => $f['requirement'] === SeoFields::REQUIRED,
            ),
        ));

        $this->assertSame(
            ['company_name', 'phone', 'address_line1', 'city', 'state', 'postal_code', 'address'],
            $required,
        );
    }

    /**
     * Every field is REACHABLE by some screen.
     *
     * It used to say "belongs to a listed section", and the reason was right:
     * the site's screen draws one section at a time and filters on this
     * attribute, so a field in a section nothing draws is a field nobody can
     * edit — a phone number that silently stops being editable is exactly the
     * failure this module exists to prevent.
     *
     * Two sections are now deliberately NOT tabs. `page` is drawn by a page's
     * own screen and `template` by the Templates tab, each asking by name. They
     * were general until Jose opened General and found every page's text fields
     * piled into the site's own screen, which is the opposite failure: not
     * unreachable, but reachable from somewhere it does not belong.
     *
     * So the list below is the whole set of places a field can be drawn, and
     * adding to it is the deliberate act it should be — a new section with no
     * screen still fails here.
     */
    public function test_every_field_is_reachable_by_some_screen(): void
    {
        $tabs = array_column(SeoFields::sections(), 'key');

        $this->assertNotEmpty($tabs);

        $drawn = [
            ...$tabs,
            // A page's own screen asks for these by name.
            SeoFields::PAGE,
            // The Templates tab asks for these by name.
            SeoFields::TEMPLATE,
        ];

        foreach (SeoFields::catalogue() as $field) {
            $this->assertContains(
                $field['section'],
                $drawn,
                "{$field['key']} is in a section no screen draws.",
            );
        }

        /*
         * And the two that are not tabs must STAY not tabs: listing one would
         * put a page's fields back on the site's screen, which is the defect
         * this pair of rules exists to prevent in both directions.
         */
        $this->assertNotContains(SeoFields::PAGE, $tabs);
        $this->assertNotContains(SeoFields::TEMPLATE, $tabs);
    }

    /**
     * The sections travel with the sites, so the client never keeps its own copy.
     */
    public function test_the_site_list_carries_the_sections(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $body = $this->signedInAs($editor)->getJson('/api/v1/web/sites')->assertOk();

        $this->assertSame(
            ['general', 'social'],
            array_column($body->json('sections'), 'key'),
        );
    }

    /**
     * A social link is a value like any other, which is the whole point of
     * putting it in the catalogue instead of a table of its own: it inherits
     * from the primary site, and overriding one overrides only that one.
     */
    public function test_a_branch_site_inherits_social_links_and_can_override_one(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $primary = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'branch.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$primary['data']['id']}/primary")
            ->assertOk();

        $this->setValues($editor, $primary['data']['id'], [
            'facebook' => 'https://facebook.com/a1chimney',
            'yelp' => 'https://yelp.com/biz/a1chimney',
        ]);

        $this->setValues($editor, $branch['data']['id'], [
            'yelp' => 'https://yelp.com/biz/a1chimney-branch',
        ]);

        $read = $this->pluginRead($branch['token'], 'branch.a-1chimney.com')->assertOk();

        // Facebook falls through from the primary; Yelp is this site's own.
        $this->assertSame(
            'https://facebook.com/a1chimney',
            $read->json('values.facebook'),
        );
        $this->assertSame(
            'https://yelp.com/biz/a1chimney-branch',
            $read->json('values.yelp'),
        );
    }

    // ── 7. a primary site, and what the others inherit ──────────────

    /**
     * The chain, end to end: page override → site value → primary's value.
     *
     * Per KEY rather than per site, which is the whole point. A branch that
     * overrides only the phone still publishes the primary's company name.
     */
    public function test_a_branch_inherits_what_it_does_not_say_itself(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $main = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$main['data']['id']}/primary")
            ->assertOk();

        $this->setValues($editor, $main['data']['id'], [
            'company_name' => 'A-1 Chimney',
            'phone' => '555-0100',
        ]);

        // The branch answers for itself on ONE key only.
        $this->setValues($editor, $branch['data']['id'], ['phone' => '555-0200']);

        $read = $this->pluginRead($branch['token'], 'nj.a-1chimney.com')->assertOk();

        $this->assertSame('555-0200', $read->json('values.phone'));
        $this->assertSame(
            'A-1 Chimney',
            $read->json('values.company_name'),
            'The branch said nothing about its company name, so it publishes the primary one.',
        );
    }

    public function test_the_primary_publishes_only_its_own(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $main = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$main['data']['id']}/primary")->assertOk();

        $this->setValues($editor, $branch['data']['id'], ['phone' => '555-0200']);

        // Inheritance runs one way. The primary must not pick up the branch's.
        $this->assertNull(
            $this->pluginRead($main['token'], 'a-1chimney.com')->assertOk()->json('values.phone'),
        );
    }

    /**
     * Page overrides are NOT inherited, and this is the test that says so.
     *
     * A path on the primary is a statement about a page of THAT site. Handing
     * it to a branch because the URLs happen to match would be a coincidence
     * dressed up as a rule.
     */
    public function test_a_page_override_on_the_primary_stays_there(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $main = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$main['data']['id']}/primary")->assertOk();

        $this->signedInAs($editor)->putJson(
            "/api/v1/web/sites/{$main['data']['id']}/values",
            ['path' => '/contact', 'values' => ['phone' => '555-0999']],
        )->assertOk();

        $this->assertSame(
            [],
            (array) $this->pluginRead($branch['token'], 'nj.a-1chimney.com')
                ->assertOk()->json('pages'),
        );
    }

    /** Only one primary per company, and the database is what enforces it. */
    public function test_making_a_second_site_primary_moves_it(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $first = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $second = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        foreach ([$first, $second] as $site) {
            $this->signedInAs($editor)
                ->postJson("/api/v1/web/sites/{$site['data']['id']}/primary")->assertOk();
        }

        $sites = $this->signedInAs($editor)->getJson('/api/v1/web/sites')
            ->assertOk()->json('data');

        $primaries = collect($sites)->where('is_primary', true);

        $this->assertCount(1, $primaries, 'A company may have exactly one primary.');
        $this->assertSame('nj.a-1chimney.com', $primaries->first()['host']);
    }

    /** With no primary, nothing inherits and the module behaves as before. */
    public function test_without_a_primary_nothing_is_inherited(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $one = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $two = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->setValues($editor, $one['data']['id'], ['company_name' => 'A-1 Chimney']);

        $this->assertNull(
            $this->pluginRead($two['token'], 'nj.a-1chimney.com')
                ->assertOk()->json('values.company_name'),
        );
    }

    /**
     * The editor needs BOTH layers — its own answers and what it would fall
     * back to — or it cannot show that a blank box is inherited rather than
     * empty. That distinction is the whole reason the screen can be honest.
     */
    public function test_the_editor_is_told_what_it_would_inherit(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $main = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$main['data']['id']}/primary")->assertOk();

        $this->setValues($editor, $main['data']['id'], ['company_name' => 'A-1 Chimney']);

        $body = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$branch['data']['id']}/values")
            ->assertOk()->json('data');

        /*
         * Only the derived one. website_url comes from the branch's OWN host,
         * so it is correctly the branch's own answer rather than something
         * inherited — and it is the only thing in here, which is what "the
         * branch has set nothing" now looks like.
         */
        $this->assertSame(
            ['website_url' => 'https://nj.a-1chimney.com/'],
            (array) $body['values'],
            'The branch has set something of its own.',
        );
        $this->assertSame('A-1 Chimney', $body['inherited']['company_name']);
    }

    public function test_a_viewer_cannot_choose_the_primary(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view', 'web_management.seo.view', 'web_management.widgets.view']);

        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        /*
         * The sentence, for the reason spelled out on
         * `test_view_alone_cannot_change_the_widgets`: this route sits behind
         * `web_management.seo.view` as well as behind `edit`, both refuse with
         * 403, and a bare `assertForbidden()` would stay green if the cause
         * drifted from one to the other while the test's name went on claiming
         * view-versus-edit.
         */
        $this->signedInAs($viewer)
            ->postJson("/api/v1/web/sites/{$site['data']['id']}/primary")
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                fn ($said) => is_string($said) && str_contains($said, 'change a website'),
            );
    }

    // ── 8. the address is parts, and one line built from them ────────

    /**
     * The one-liner is DERIVED whenever parts exist, and left alone when they
     * do not. Both halves matter: the first is why correcting a ZIP updates the
     * footer, the second is why no site that pre-dates the split changes.
     */
    public function test_the_address_line_is_built_from_its_parts(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->setValues($editor, $site['data']['id'], [
            'address_line1' => '112 Bloomfield Ave',
            'address_line2' => 'Suite 4',
            'city' => 'Caldwell',
            'state' => 'NJ',
            'postal_code' => '07006',
        ]);

        $this->assertSame(
            '112 Bloomfield Ave, Suite 4, Caldwell, NJ 07006',
            $this->pluginRead($site['token'], 'a-1chimney.com')->assertOk()->json('values.address'),
        );
    }

    public function test_a_site_from_before_the_split_keeps_its_one_line_address(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        // Only the old single string, exactly as such a site has it stored.
        $this->setValues($editor, $site['data']['id'], ['address' => '5 Old Way, Somewhere, NJ 07000']);

        $this->assertSame(
            '5 Old Way, Somewhere, NJ 07000',
            $this->pluginRead($site['token'], 'a-1chimney.com')->assertOk()->json('values.address'),
        );
    }

    public function test_the_parts_win_over_a_stale_one_line_address(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->setValues($editor, $site['data']['id'], [
            'address' => '5 Old Way, Somewhere, NJ 07000',
            'address_line1' => '112 Bloomfield Ave',
            'city' => 'Caldwell',
            'state' => 'NJ',
            'postal_code' => '07006',
        ]);

        $this->assertSame(
            '112 Bloomfield Ave, Caldwell, NJ 07006',
            $this->pluginRead($site['token'], 'a-1chimney.com')->assertOk()->json('values.address'),
            'A one-liner left over from before the split must not outlive a corrected ZIP.',
        );
    }

    /** Inheritance composes too — a branch with only its own street gets the primary city. */
    public function test_address_parts_inherit_individually(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);

        $main = $this->registerSite($editor, $tenant, 'a-1chimney.com');
        $branch = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $this->signedInAs($editor)
            ->postJson("/api/v1/web/sites/{$main['data']['id']}/primary")->assertOk();

        $this->setValues($editor, $main['data']['id'], [
            'address_line1' => '112 Bloomfield Ave', 'city' => 'Caldwell',
            'state' => 'NJ', 'postal_code' => '07006',
        ]);
        $this->setValues($editor, $branch['data']['id'], ['address_line1' => '9 Branch Rd']);

        $this->assertSame(
            '9 Branch Rd, Caldwell, NJ 07006',
            $this->pluginRead($branch['token'], 'nj.a-1chimney.com')->assertOk()->json('values.address'),
        );
    }

    // ── 9. the WordPress account EzBizi publishes with ──────────────

    public function test_a_site_hands_over_an_account_with_its_own_token(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->serviceAccountPost($site['token'])->assertCreated()->assertJson(['linked' => true]);

        $stored = WebSite::query()->withoutGlobalScopes()->find($site['data']['id']);

        $this->assertSame('ezbizi-service', $stored->service_user_login);
        $this->assertSame('plain-text-secret', $stored->service_password);
        $this->assertNotNull($stored->service_linked_at);
    }

    /**
     * The credential is readable to us and unreadable to anybody with the disk.
     *
     * It cannot be hashed — we present it to WordPress rather than recognise it
     * — so encryption at rest is the whole protection, and a cast that silently
     * stopped applying would look identical through the model.
     */
    public function test_the_password_is_encrypted_in_the_column(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->serviceAccountPost($site['token'])->assertCreated();

        $raw = DB::table('web_sites')
            ->where('id', $site['data']['id'])
            ->value('service_password');

        $this->assertNotSame('plain-text-secret', $raw);
        $this->assertStringNotContainsString('plain-text-secret', (string) $raw);
    }

    public function test_a_bad_token_cannot_attach_an_account(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->serviceAccountPost('ezb_not_a_token')->assertUnauthorized();
    }

    /**
     * A token names one host, and the REST address has to be on it.
     *
     * Without this a site could hand us a credential pointed at somebody else's
     * WordPress, and every publish afterwards would be a request we make, from
     * our address, against a third party who never asked to hear from us.
     */
    public function test_the_rest_address_must_be_on_the_site_the_token_names(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->serviceAccountPost($site['token'], [
            'rest_url' => 'https://someone-else.example.com/wp-json/',
        ])->assertStatus(422);

        $stored = WebSite::query()->withoutGlobalScopes()->find($site['data']['id']);

        $this->assertNull($stored->service_password);
    }

    /**
     * The plugin learns from an ordinary read that the pairing is over, which is
     * the only way it can revoke its own application password — WordPress does
     * not let one application password delete another.
     */
    public function test_the_read_says_whether_the_pairing_still_stands(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->assertFalse(
            $this->pluginRead($site['token'], 'a-1chimney.com')->json('service_account.linked'),
        );

        $this->serviceAccountPost($site['token'])->assertCreated();

        $this->assertTrue(
            $this->pluginRead($site['token'], 'a-1chimney.com')->json('service_account.linked'),
        );

        $this->inTenant($tenant['org'], function () use ($site) {
            WebSite::findOrFail($site['data']['id'])->unlinkServiceAccount();
        });

        $this->assertFalse(
            $this->pluginRead($site['token'], 'a-1chimney.com')->json('service_account.linked'),
        );
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** @param array{org: Organization, franchise: Franchise} $tenant */
    private function editorFor(array $tenant): User
    {
        return $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit', 'web_management.seo.view', 'web_management.widgets.view'],
        );
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

    /** @param array<string, string|null> $values */
    private function setValues(User $editor, string $siteId, array $values): void
    {
        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$siteId}/values", ['values' => $values])
            ->assertOk();
    }

    /** @param array<string, string> $overrides */
    private function serviceAccountPost(string $token, array $overrides = []): TestResponse
    {
        return $this->postJson(
            '/api/v1/web/plugin/service-account',
            array_merge([
                'host' => 'a-1chimney.com',
                'user_login' => 'ezbizi-service',
                'password' => 'plain-text-secret',
                'rest_url' => 'https://a-1chimney.com/wp-json/',
                'capabilities' => 'read,publish_pages',
            ], $overrides),
            ['Authorization' => 'Bearer '.$token],
        );
    }

    /** Jose, 2026-11-20: the badge is the company's real total, every location, never a typed number. */
    public function test_the_plugin_gets_the_companys_real_review_total_and_average(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $connection = \App\Models\SocialConnection::create([
                'organization_id' => $tenant['org']->id,
                'scope_type' => \App\Models\SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $tenant['org']->id,
                'provider' => \App\Support\Social\SocialProvider::GoogleBusinessProfile->value,
                'remote_id' => 'accounts/1',
                'display_name' => 'A-1',
            ]);
            foreach ([[5, 'loc1'], [5, 'loc2'], [4, 'loc2'], [null, 'loc1']] as $i => [$rating, $loc]) {
                \App\Models\Review::create([
                    'organization_id' => $tenant['org']->id,
                    'company_id' => $tenant['company']->id,
                    'connection_id' => $connection->id,
                    'remote_name' => "accounts/1/locations/$loc/reviews/$i",
                    'location_name' => "accounts/1/locations/$loc",
                    'reviewer_name' => 'R'.$i,
                    'rating' => $rating,
                    'reviewed_at' => now()->subDays($i),
                ]);
            }
            // The same review synced through a second account (the manager account) is one review.
            \App\Models\Review::create([
                'organization_id' => $tenant['org']->id,
                'company_id' => $tenant['company']->id,
                'connection_id' => $connection->id,
                'remote_name' => 'accounts/2/locations/loc1/reviews/0',
                'location_name' => 'accounts/2/locations/loc1',
                'reviewer_name' => 'R0',
                'rating' => 5,
                'reviewed_at' => now(),
            ]);
        });

        $this->pluginRead($site['token'], 'a-1chimney.com')->assertOk()
            ->assertJsonPath('review_summary.count', 3)
            ->assertJsonPath('review_summary.average', 4.7);
    }

    private function pluginRead(string $token, string $host): TestResponse
    {
        return $this->getJson(
            '/api/v1/web/plugin/values?host='.urlencode($host),
            ['Authorization' => 'Bearer '.$token],
        );
    }

    /**
     * The four Jose asked for on 2026-09-16, with the shortcodes they produce.
     *
     * Pinned by KEY rather than by count, because the key is what a customer
     * pastes into a page: renaming one silently breaks every site already
     * publishing it, and a count would not notice.
     */
    public function test_the_newest_fields_are_offered_with_their_shortcodes(): void
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

        $fields = collect(
            $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$created['data']['id']}/values")
                ->assertOk()
                ->json('data.fields')
        )->keyBy('key');

        foreach ([
            'maps_url' => '[ezbizi_maps_url]',
            'country' => '[ezbizi_country]',
            'timezone' => '[ezbizi_timezone]',
            'tagline' => '[ezbizi_tagline]',
        ] as $key => $shortcode) {
            $this->assertTrue($fields->has($key), "The $key field is not offered.");
            $this->assertSame($shortcode, $fields[$key]['shortcode']);

            // None of them can be required: every site that exists today has
            // none of them set, and a new requirement would mark all of them
            // incomplete overnight.
            $this->assertNotSame('required', $fields[$key]['requirement']);
        }
    }

    /**
     * The three Jose asked for on 2026-09-17, and the one that fills itself.
     *
     * Pinned by KEY and shortcode: the key is what a customer pastes into a
     * page, so renaming one breaks every site already publishing it.
     */
    public function test_the_link_fields_are_offered_with_their_shortcodes(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $fields = collect(
            $this->signedInAs($editor)
                ->getJson("/api/v1/web/sites/{$site['data']['id']}/values")
                ->assertOk()
                ->json('data.fields')
        )->keyBy('key');

        foreach ([
            'city_link' => '[ezbizi_city_link]',
            'state_link' => '[ezbizi_state_link]',
            'website_url' => '[ezbizi_website_url]',
        ] as $key => $shortcode) {
            $this->assertTrue($fields->has($key), "The $key field is not offered.");
            $this->assertSame($shortcode, $fields[$key]['shortcode']);
            $this->assertNotSame('required', $fields[$key]['requirement']);
        }
    }

    /**
     * The website address fills itself in, from the host rather than a row.
     *
     * Nobody should type their own web address into their own website: it is
     * one fact kept in two places, and the typed one goes stale the day a
     * domain changes.
     */
    public function test_the_website_address_is_filled_in_from_the_host(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'nj.a-1chimney.com');

        $panel = $this->signedInAs($editor)
            ->getJson("/api/v1/web/sites/{$site['data']['id']}/values")
            ->assertOk();

        $this->assertSame('https://nj.a-1chimney.com/', $panel->json('data.values.website_url'));

        // And the website itself is told, or the shortcode renders nothing.
        $plugin = $this->pluginRead($site['token'], 'nj.a-1chimney.com')->assertOk();

        $this->assertSame('https://nj.a-1chimney.com/', $plugin->json('values.website_url'));

        // Nothing was stored for it: there is no row to go stale.
        $this->inTenant($tenant['org'], function () {
            $this->assertDatabaseMissing('web_values', ['key' => 'website_url']);
        });
    }

    /**
     * THE one I nearly broke. A key scraped from page source must not name the
     * business it belongs to, so the public endpoint does not derive the host
     * even though the other two do.
     */
    public function test_the_browser_endpoint_still_does_not_name_the_host(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $site = $this->registerSite($editor, $tenant, 'a-1chimney.com');

        $json = $this->getJson(
            '/api/v1/web/public/values?key='.$site['data']['public_key'],
        )->assertOk()->content();

        $this->assertStringNotContainsString('a-1chimney.com', $json);
        $this->assertStringNotContainsString('website_url":"http', $json);
    }
}
