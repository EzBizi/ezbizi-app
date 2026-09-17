<?php

namespace Tests\Feature\Web;

use App\Models\WebSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The check the deploy desk runs after a cut that touches plugin tokens.
 *
 * Its whole job is to tell two situations apart, so both are asserted: an
 * estate being answered, and an estate that has stopped being answered. A check
 * that cannot go red is the one the desk already has.
 */
class WebEstateHealthTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function site(string $host, ?string $lastSeen): void
    {
        $tenant = $this->provisionTenant('Alpha '.$host);
        $editor = $this->userWithRole($tenant, 'Web Editor', [
            'web_management.view', 'web_management.edit',
            'web_management.seo.view', 'web_management.widgets.view',
        ]);

        $created = $this->signedInAs($editor)->postJson('/api/v1/web/sites', [
            'company_id' => $tenant['company']->getKey(),
            'host' => $host,
        ])->assertCreated()->json();

        $this->inTenant($tenant['org'], function () use ($created, $lastSeen) {
            WebSite::findOrFail($created['data']['id'])
                ->forceFill(['last_seen_at' => $lastSeen])
                ->save();
        });
    }

    public function test_an_estate_that_is_being_answered_passes(): void
    {
        $this->site('a-1chimney.com', now()->subMinutes(2)->toDateTimeString());
        $this->site('nj.a-1chimney.com', now()->subMinutes(9)->toDateTimeString());

        $this->artisan('ezbizi:web-estate')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }

    /** THE one. If this cannot go red the check is decoration. */
    public function test_an_estate_that_has_gone_quiet_fails(): void
    {
        // Seen yesterday, so they are live sites — and not since, which is what
        // a refusal looks like from this side.
        $this->site('a-1chimney.com', now()->subHours(3)->toDateTimeString());
        $this->site('nj.a-1chimney.com', now()->subHours(4)->toDateTimeString());

        $this->artisan('ezbizi:web-estate')
            ->expectsOutputToContain('ONLY 0 of 2')
            ->assertExitCode(1);
    }

    /**
     * A site nobody has installed the plugin on is not evidence about a deploy.
     * Counting it would leave the check permanently amber, which is the same as
     * having no check.
     */
    public function test_a_site_that_never_called_home_does_not_fail_it(): void
    {
        $this->site('a-1chimney.com', now()->subMinutes(2)->toDateTimeString());
        $this->site('never-installed.com', null);

        $this->artisan('ezbizi:web-estate')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }

    /** With nothing to compare against it says so rather than crying wolf. */
    public function test_an_estate_with_no_history_says_so(): void
    {
        $this->site('never-installed.com', null);

        $this->artisan('ezbizi:web-estate')
            ->expectsOutputToContain('Nothing to compare against')
            ->assertExitCode(0);
    }
}
