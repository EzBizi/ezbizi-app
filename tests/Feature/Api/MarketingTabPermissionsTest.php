<?php

namespace Tests\Feature\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Social Media Management and Newsletter panes, granted one at a time (Jose,
 * 2026-09-29: tabs grantable "like all the others").
 *
 * Every refusal here is paired with the SAME request succeeding for somebody who
 * holds the tab. A 403 is also what a broken route or a fixture with no reach
 * produces, so a suite of 403s alone would read a dead endpoint as a working
 * permission. And every refusal asserts the tab's SENTENCE, because the older
 * refusals on these routes (publish, draft, send) are 403s too — a test
 * asserting only the status stays green if the tab gate were removed and one of
 * those answered instead.
 *
 * Non-administrator roles throughout: an administrator holds every key, so a
 * fixture built on one cannot test a permission at all.
 */
class MarketingTabPermissionsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private const TAB_REFUSAL = 'open this tab';

    public function test_the_posts_tab_opens_the_post_list(): void
    {
        $t = $this->provisionTenant('Alpha');
        $marketer = $this->userWithRole($t, 'Marketer', ['marketing.view', 'marketing.posts.view']);

        $this->signedInAs($marketer)->getJson('/api/v1/social/posts')->assertOk();
    }

    public function test_marketing_without_the_posts_tab_cannot_read_the_post_list(): void
    {
        $t = $this->provisionTenant('Bravo');
        $accountsOnly = $this->userWithRole($t, 'Accounts only', ['marketing.view', 'marketing.accounts.view']);

        $this->signedInAs($accountsOnly)
            ->getJson('/api/v1/social/posts')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, self::TAB_REFUSAL));
    }

    public function test_the_newsletter_list_answers_to_the_dashboard_tab(): void
    {
        $t = $this->provisionTenant('Charlie');
        $viewer = $this->userWithRole($t, 'Viewer', ['newsletter.view', 'newsletter.dashboard.view']);

        $this->signedInAs($viewer)->getJson('/api/v1/newsletters')->assertOk();
    }

    public function test_the_newsletter_list_answers_to_the_composer_tab(): void
    {
        $t = $this->provisionTenant('Delta');
        $writer = $this->userWithRole($t, 'Writer', ['newsletter.view', 'newsletter.composer.view']);

        $this->signedInAs($writer)->getJson('/api/v1/newsletters')->assertOk();
    }

    public function test_newsletter_with_neither_list_tab_cannot_read_the_list(): void
    {
        $t = $this->provisionTenant('Echo');
        $settingsOnly = $this->userWithRole($t, 'Settings only', ['newsletter.view', 'newsletter.settings.view']);

        $this->signedInAs($settingsOnly)
            ->getJson('/api/v1/newsletters')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, self::TAB_REFUSAL));
    }

    /*
     * Dashboard can see the calendar and cannot write. The positive control is
     * the same person reading the list above; here the SAME person is refused a
     * draft — so the refusal is the composer gate and not a missing route.
     */
    public function test_the_dashboard_tab_alone_cannot_write_preview_or_send(): void
    {
        $t = $this->provisionTenant('Foxtrot');
        $viewer = $this->userWithRole($t, 'Viewer', [
            'newsletter.view', 'newsletter.dashboard.view', 'newsletter.create',
        ]);

        $this->signedInAs($viewer)->getJson('/api/v1/newsletters')->assertOk();

        $this->signedInAs($viewer)
            ->postJson('/api/v1/newsletters', ['subject' => 'Autumn'])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, self::TAB_REFUSAL));

        $this->signedInAs($viewer)
            ->getJson('/api/v1/newsletter/audiences')
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, self::TAB_REFUSAL));
    }

    public function test_the_composer_tab_can_draft(): void
    {
        $t = $this->provisionTenant('Golf');
        $writer = $this->userWithRole($t, 'Writer', [
            'newsletter.view', 'newsletter.composer.view', 'newsletter.create',
        ]);

        $this->signedInAs($writer)
            ->postJson('/api/v1/newsletters', ['subject' => 'Autumn'])
            ->assertCreated();
    }

    /*
     * The migration is what stops anybody losing a pane on the deploy: every
     * role that could open each module this morning gets every tab it drew.
     * Required from the FILE and run, because RefreshDatabase has already run
     * it before any role existed, which would make a test that relied on the
     * migrator prove nothing.
     */
    public function test_the_migration_grants_every_existing_reader_their_tabs(): void
    {
        $t = $this->provisionTenant('Hotel');
        $marketer = $this->userWithRole($t, 'Marketer', ['marketing.view']);
        $mailer = $this->userWithRole($t, 'Mailer', ['newsletter.view']);

        // Role reads are tenant-scoped.
        $holds = fn ($user, string $key) => app(TenantContext::class)->run(
            $t['org'],
            fn () => $user->fresh()->hasPermission($key),
        );

        $this->assertFalse($holds($marketer, 'marketing.posts.view'));

        $migration = require database_path('migrations/2026_09_29_310000_grant_existing_roles_the_marketing_and_newsletter_tabs.php');
        $migration->up();

        foreach (['marketing.posts.view', 'marketing.accounts.view'] as $key) {
            $this->assertTrue($holds($marketer, $key), "{$key} was not granted to Marketing's readers.");
        }

        foreach (['newsletter.dashboard.view', 'newsletter.composer.view', 'newsletter.settings.view'] as $key) {
            $this->assertTrue($holds($mailer, $key), "{$key} was not granted to Newsletter's readers.");
        }

        // And it grants nothing across: a Newsletter-only role does not gain
        // Social's panes by being in the same parent.
        $this->assertFalse($holds($mailer, 'marketing.posts.view'));
    }
}
