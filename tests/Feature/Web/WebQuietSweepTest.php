<?php

namespace Tests\Feature\Web;

use App\Models\AutomationEvent;
use App\Models\WebSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Telling somebody that a website has stopped answering.
 *
 * The sweep's job is to say it ONCE and to say it only about sites where
 * silence means something. Both halves are load-bearing: an alert that repeats
 * every hour for a site dead a month is one people filter out, and an alert
 * about a website nobody has installed the plugin on yet is one they stop
 * trusting.
 */
class WebQuietSweepTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    /** @return array{site: WebSite, tenant: array<string, mixed>, token: string} */
    private function site(?string $lastSeen, string $host = 'a-1chimney.com'): array
    {
        $tenant = $this->provisionTenant('Alpha '.$host);
        $editor = $this->userWithRole($tenant, 'Web Editor', [
            'web_management.view', 'web_management.edit',
        ]);

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => $host,
        ])->assertCreated()->json();

        $site = $this->inTenant($tenant['org'], function () use ($created, $lastSeen) {
            $site = WebSite::findOrFail($created['data']['id']);
            $site->forceFill(['last_seen_at' => $lastSeen])->save();

            return $site;
        });

        return ['site' => $site, 'tenant' => $tenant, 'token' => $created['token']];
    }

    private function quietEvents(array $tenant): int
    {
        return $this->inTenant(
            $tenant['org'],
            fn () => AutomationEvent::query()->where('event_key', 'website.went_quiet')->count(),
        );
    }

    public function test_a_website_that_has_stopped_answering_raises_it(): void
    {
        ['tenant' => $tenant] = $this->site(now()->subDays(2)->toDateTimeString());

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);

        $this->assertSame(1, $this->quietEvents($tenant));
    }

    /** THE one. Said once, or the rule Jose writes on it becomes noise. */
    public function test_it_is_said_once_however_often_the_sweep_runs(): void
    {
        ['tenant' => $tenant] = $this->site(now()->subDays(2)->toDateTimeString());

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);
        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);
        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);

        $this->assertSame(1, $this->quietEvents($tenant));
    }

    /**
     * WP-Cron fires on page views, so a site with no visitors overnight has not
     * gone quiet in any sense worth telling somebody about.
     */
    public function test_a_website_that_answered_this_morning_is_left_alone(): void
    {
        ['tenant' => $tenant] = $this->site(now()->subHours(6)->toDateTimeString());

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);

        $this->assertSame(0, $this->quietEvents($tenant));
    }

    /**
     * A site registered before anybody installed the plugin has not gone quiet,
     * it has not started. Otherwise every new website announces itself as
     * broken the day after it is added.
     */
    public function test_a_website_that_never_answered_is_not_quiet(): void
    {
        ['tenant' => $tenant] = $this->site(null);

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);

        $this->assertSame(0, $this->quietEvents($tenant));
    }

    /**
     * And coming back arms it again, or a site that fell over twice is reported
     * once in its life.
     */
    public function test_a_website_that_comes_back_can_be_reported_again(): void
    {
        ['tenant' => $tenant, 'token' => $token] = $this->site(
            now()->subDays(2)->toDateTimeString(),
        );

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);
        $this->assertSame(1, $this->quietEvents($tenant));

        // The plugin calls home, which is what clears the marker.
        $this->withToken($token)
            ->getJson('/api/v1/web/plugin/values?host=a-1chimney.com')
            ->assertOk();

        $this->inTenant($tenant['org'], function () {
            $this->assertNull(WebSite::query()->firstOrFail()->went_quiet_at);
        });

        // Quiet again later.
        $this->inTenant($tenant['org'], function () {
            WebSite::query()->firstOrFail()
                ->forceFill(['last_seen_at' => now()->subDays(2)])
                ->save();
        });

        $this->artisan('ezbizi:web-quiet-sweep')->assertExitCode(0);

        $this->assertSame(2, $this->quietEvents($tenant));
    }
}
