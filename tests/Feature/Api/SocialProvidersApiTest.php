<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The social-network catalogue — names in code, readiness from this installation.
 *
 * A provider EzBizi has not registered with stays on the list and says why.
 * Hiding it looks like a lost permission. Connect is refused with the same
 * sentence the catalogue publishes, so the button and the endpoint cannot
 * disagree.
 */
class SocialProvidersApiTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/social/providers';

    public function test_the_catalogue_is_refused_without_the_marketing_tab(): void
    {
        $t      = $this->provisionTenant('A1-Group');
        $viewer = $this->userWithRole($t, 'Viewer', ['customers.view']);

        $message = $this->signedInAs($viewer)
            ->getJson(self::ENDPOINT)
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('open Marketing', $message);
        $this->assertStringContainsString('role', $message);
        $this->assertStringNotContainsString('marketing.view', $message);
    }

    public function test_a_role_granted_the_tab_reads_the_catalogue(): void
    {
        $t        = $this->provisionTenant('A1-Group');
        $marketer = $this->userWithRole($t, 'Marketer', ['marketing.view']);

        $this->signedInAs($marketer)->getJson(self::ENDPOINT)->assertOk();
    }

    public function test_integrations_gets_the_data_sources_and_the_composer_does_not(): void
    {
        $t = $this->provisionTenant('A1-Group');
        $marketer = $this->userWithRole($t, 'Marketer', ['marketing.view']);

        // Facebook Ads had no card in Integrations until it was connected,
        // which it could only be from there (2026-10-14).
        $all = $this->signedInAs($marketer)->getJson(self::ENDPOINT.'?include=data_sources')->assertOk()->json('data.*.value');
        $this->assertContains('facebook_ads', $all);

        $composer = $this->signedInAs($marketer)->getJson(self::ENDPOINT)->assertOk()->json('data.*.value');
        $this->assertNotContains('facebook_ads', $composer);
        $this->assertNotContains('google_ads', $composer);
    }

    public function test_pinterest_is_in_the_catalogue_and_says_why_when_ezbizi_has_no_app(): void
    {
        config(['services.pinterest.client_id' => null, 'services.pinterest.client_secret' => null]);

        $t = $this->provisionTenant('A1-Group');

        $row = $this->providerNamed($t, 'pinterest');

        $this->assertSame('Pinterest', $row['label']);
        $this->assertFalse($row['usable']);
        $this->assertIsString($row['unusable_because']);
        $this->assertStringContainsString('EzBizi', $row['unusable_because']);
        $this->assertStringContainsString('Pinterest', $row['unusable_because']);
        $this->assertStringNotContainsString('PINTEREST_CLIENT', $row['unusable_because']);
    }

    public function test_pinterest_is_usable_once_this_installation_has_an_app(): void
    {
        config([
            'services.pinterest.client_id'     => 'a-pinterest-app-id',
            'services.pinterest.client_secret' => 'a-pinterest-app-secret',
        ]);

        $t = $this->provisionTenant('A1-Group');

        $row = $this->providerNamed($t, 'pinterest');

        $this->assertTrue($row['usable']);
        $this->assertNull($row['unusable_because']);
    }

    public function test_oauth_networks_are_unusable_until_this_installation_has_an_app(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $byValue = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data'))->keyBy('value');

        foreach ([
            'facebook_page',
            'instagram_professional',
            'threads',
            'linkedin',
            'tiktok',
            'youtube',
            'x',
        ] as $key) {
            $this->assertTrue($byValue->has($key), "{$key} is missing from the catalogue.");
            $this->assertFalse($byValue[$key]['usable'], "{$key} is marked ready with nothing behind it.");
            $this->assertStringContainsString('EzBizi', $byValue[$key]['unusable_because']);
        }

        $this->assertSame('Facebook', $byValue['facebook_page']['label']);
        $this->assertSame('Instagram', $byValue['instagram_professional']['label']);
        $this->assertTrue($byValue['bluesky']['usable'], 'Bluesky has no console — Connect is an app password.');
        $this->assertNull($byValue['bluesky']['unusable_because']);
        $this->assertSame('password', $byValue['bluesky']['connects_by']);
    }

    public function test_facebook_instagram_and_threads_become_usable_with_their_meta_keys(): void
    {
        config([
            'services.meta.client_id'     => 'a-meta-app-id',
            'services.meta.client_secret' => 'a-meta-app-secret',
            // Threads is its own app inside the same Meta app, with its own
            // id and secret. Setting only the Facebook pair leaves Threads
            // not-ready, which is the point.
            'services.meta.instagram_id'     => 'an-instagram-app-id',
            'services.meta.instagram_secret' => 'an-instagram-app-secret',
            'services.meta.threads_id'     => 'a-threads-app-id',
            'services.meta.threads_secret' => 'a-threads-app-secret',
        ]);

        $t = $this->provisionTenant('A1-Group');
        $byValue = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data'))->keyBy('value');

        $this->assertTrue($byValue['facebook_page']['usable']);
        $this->assertTrue($byValue['instagram_professional']['usable']);
        $this->assertTrue($byValue['threads']['usable']);
        $this->assertFalse($byValue['linkedin']['usable']);
    }

    public function test_the_row_carries_exactly_the_fields_the_screen_reads(): void
    {
        $t = $this->provisionTenant('A1-Group');

        $row = $this->providerNamed($t, 'pinterest');

        $this->assertSame(
            ['value', 'label', 'usable', 'unusable_because', 'connects_by', 'start_path', 'permission'],
            array_keys($row),
        );
        $this->assertSame('sign_in', $row['connects_by']);
        $this->assertSame('/social/pinterest/start', $row['start_path']);

        /*
         * `permission` added 2026-09-23 for Settings > Integrations, which
         * draws a Connect control per provider on Jose's instruction that the
         * screen exists "to be able to connect stuff from here, not just list
         * it" — and cannot offer one that answers 403.
         *
         * Asserted as the VALUE rather than only as a key, because the point of
         * putting it on the wire is that the client stops keeping its own copy
         * of this map: a key present with the wrong answer is the failure that
         * would produce, and it is invisible to a field-list assertion.
         */
        $this->assertSame('marketing.edit', $row['permission']);
    }

    /** @return array<string, mixed> */
    private function providerNamed(array $t, string $value): array
    {
        $row = collect($this->signedInAs($t['admin'])->getJson(self::ENDPOINT)
            ->assertOk()->json('data'))->firstWhere('value', $value);

        $this->assertNotNull($row, "{$value} is missing from the catalogue.");

        return $row;
    }
}
