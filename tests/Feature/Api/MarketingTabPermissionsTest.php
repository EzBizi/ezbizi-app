<?php

namespace Tests\Feature\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /** Posts became Dashboard, Compose and Calendar (2026-10-12); each reads the list. */
    public function test_each_post_tab_opens_the_post_list(): void
    {
        $t = $this->provisionTenant('Alpha');

        foreach (['dashboard', 'compose', 'calendar'] as $tab) {
            $user = $this->userWithRole($t, ucfirst($tab), ['marketing.view', "marketing.{$tab}.view"]);
            $this->signedInAs($user)->getJson('/api/v1/social/posts')->assertOk();
        }
    }

    /** Writing is Compose's: the Calendar alone reads but cannot post. */
    public function test_only_compose_can_post(): void
    {
        $t = $this->provisionTenant('Alpha2');
        $calendar = $this->userWithRole($t, 'Calendar', ['marketing.view', 'marketing.edit', 'marketing.calendar.view']);

        $this->signedInAs($calendar)
            ->postJson('/api/v1/social/posts', ['body' => 'Autumn sweeps'])
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_contains($m, self::TAB_REFUSAL));
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
     * The split migration gives every role that held Posts all three new tabs.
     *
     * The test this replaces ran 2026_09_29_310000, which materialises
     * `marketing.posts.view` and so cannot run against today's registry once a
     * role exists. It already ran in production, and on a fresh database no
     * role exists when it runs, so it returns before that line.
     */
    public function test_the_split_gives_every_posts_holder_the_three_tabs(): void
    {
        $t = $this->provisionTenant('Hotel');
        $poster = $this->userWithRole($t, 'Poster', ['marketing.view']);
        $other = $this->userWithRole($t, 'Other', ['marketing.view']);

        // posts.view is no longer declared, so it is written the way it sits in
        // production: a row the registry has stopped describing.
        $postsId = (string) Str::uuid();
        DB::table('permissions')->insert([
            'id' => $postsId, 'key' => 'marketing.posts.view', 'module' => 'marketing', 'action' => 'posts.view', 'label' => 'Posts',
        ]);
        $roleId = app(TenantContext::class)->run($t['org'], fn () => $poster->fresh()->role_id);
        DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $postsId]);

        $holds = fn ($user, string $key) => app(TenantContext::class)->run(
            $t['org'], fn () => $user->fresh()->hasPermission($key),
        );

        (require database_path('migrations/2026_10_12_400000_split_marketing_posts_into_dashboard_compose_calendar.php'))->up();

        foreach (['marketing.dashboard.view', 'marketing.compose.view', 'marketing.calendar.view'] as $key) {
            $this->assertTrue($holds($poster, $key), "{$key} was not granted to a Posts holder.");
            $this->assertFalse($holds($other, $key), "{$key} was granted to a role without Posts.");
        }
    }
}
