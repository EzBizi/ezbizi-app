<?php

namespace Tests\Feature\Web;

use App\Models\WebSite;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ['web_management.view', 'web_management.edit'],
            $module->permissionKeys(),
        );
        $this->assertNotEmpty(
            $module->automationExemptBecause,
            'Web Management declared no events and gave no reason.',
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
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view']);

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
        $this->assertStringContainsString('Stable tag: 1.0.0', $readme);
        $this->assertStringContainsString('== External services ==', $readme);

        /*
         * The disclosure has to name the token and the hostname specifically.
         * "Connects to an API" is the wording that gets a plugin rejected, and
         * it is also not true enough to be worth saying.
         */
        $this->assertStringContainsString('your site token', $readme);
        $this->assertStringContainsString('hostname', $readme);

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

    // ── 7. §4: permissions and tenants ──────────────────────────────

    public function test_a_viewer_can_read_sites_and_cannot_change_them(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $editor = $this->editorFor($tenant);
        $viewer = $this->userWithRole($tenant, 'Viewer', ['web_management.view']);

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

        $this->assertSame(['fields', 'values', 'pages'], array_keys($body));
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

    public function test_a_missing_key_and_a_wrong_key_answer_the_same_way(): void
    {
        $this->getJson('/api/v1/web/public/values')->assertNotFound();
        $this->getJson('/api/v1/web/public/values?key=pk_nonsense')->assertNotFound();

        $this->assertSame(
            $this->getJson('/api/v1/web/public/values')->json('message'),
            $this->getJson('/api/v1/web/public/values?key=pk_nonsense')->json('message'),
        );
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

    // ── helpers ─────────────────────────────────────────────────────

    /** @param array{org: \App\Models\Organization, franchise: \App\Models\Franchise} $tenant */
    private function editorFor(array $tenant): \App\Models\User
    {
        return $this->userWithRole(
            $tenant,
            'Web Editor',
            ['web_management.view', 'web_management.edit'],
        );
    }

    /** @return array{data: array<string, mixed>, token: string} */
    private function registerSite(\App\Models\User $editor, array $tenant, string $host): array
    {
        $body = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->id,
            'host' => $host,
        ])->assertCreated()->json();

        return ['data' => $body['data'], 'token' => $body['token']];
    }

    /** @param array<string, string|null> $values */
    private function setValues(\App\Models\User $editor, string $siteId, array $values): void
    {
        $this->signedInAs($editor)
            ->putJson("/api/v1/web/sites/{$siteId}/values", ['values' => $values])
            ->assertOk();
    }

    private function pluginRead(string $token, string $host): \Illuminate\Testing\TestResponse
    {
        return $this->getJson(
            '/api/v1/web/plugin/values?host='.urlencode($host),
            ['Authorization' => 'Bearer '.$token],
        );
    }
}
