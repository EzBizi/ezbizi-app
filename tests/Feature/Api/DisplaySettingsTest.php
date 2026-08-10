<?php

namespace Tests\Feature\Api;

use App\Models\Franchise;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The display settings reaching the client (plan §6).
 *
 * ── What went wrong, and what these hold in place ────────────────
 *
 * The owner changed Time format from 12h to 24h and nothing happened. It saved,
 * it audited, and no code read it — /me carried identity, scope, companies and
 * franchises, and not one resolved setting, so the client had nothing to format
 * from even in principle.
 *
 * Two things therefore have to stay true, and they are separate claims:
 *
 *   1. the values are ON /me at all, and
 *   2. they follow the CASCADE — resolved for wherever the caller is standing,
 *      not for the organization every time.
 *
 * The second is the one that would rot quietly. Resolving against
 * `$user->organization` is simpler, passes any test that only sets an
 * organization value, and gives a franchise with its own clock the wrong one.
 */
class DisplaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Everdoors',
            administrator: ['name' => 'Admin', 'email' => 'a@everdoors.test', 'password' => 'a-real-password'],
            companyName: 'A-1 Chimney', franchiseName: 'Connecticut',
        );

        $this->w = [
            'org'     => $r['organization'],
            'admin'   => $r['administrator'],
            'company' => $r['company'],
            'ct'      => $r['franchise'],
        ];

        app(TenantContext::class)->run($this->w['org'], function () {
            $this->w['texas'] = Franchise::create([
                'organization_id' => $this->w['org']->id,
                'company_id'      => $this->w['company']->id,
                'name'            => 'Texas',
                'timezone'        => 'America/Chicago',
                'currency'        => 'USD',
                'is_active'       => true,
            ]);

            $settings = app(SettingsResolver::class);

            // The organization keeps American dates and a 12-hour clock.
            $settings->set('date_format', $this->w['org'], 'M/D/YYYY');
            $settings->set('time_format', $this->w['org'], '12h');

            // Texas overrides only the clock. Leaving the date alone is
            // deliberate: it is what proves the two keys resolve
            // independently rather than travelling as one block.
            $settings->set('time_format', $this->w['texas'], '24h');
        });
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@everdoors.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /**
     * Park the admin in a view, the way the switcher does.
     *
     * forceFill rather than update, and inside the tenant: `preferences` is not
     * mass-assignable — a preference is user input and the attribute list is
     * what stops a signup body writing one — so update() drops it in silence.
     * And saving a User at all fires BelongsToTenant's write guard, which
     * refuses when no tenant is established. A test is a caller like any other.
     */
    private function standIn(?string $scope): void
    {
        app(TenantContext::class)->run($this->w['org'], function () use ($scope) {
            User::find($this->w['admin']->id)
                ->forceFill(['preferences' => $scope === null ? [] : ['navigation.scope' => $scope]])
                ->save();
        });
    }

    public function test_me_carries_the_resolved_display_settings(): void
    {
        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        // The whole point. Before this, the key was absent and a client that
        // wanted to honour the setting had nowhere to read it from.
        $this->assertSame(
            ['date_format' => 'M/D/YYYY', 'time_format' => '12h'],
            $me['display'],
        );
    }

    public function test_a_franchise_override_reaches_somebody_standing_in_it(): void
    {
        $this->standIn('franchise:'.$this->w['texas']->id);

        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        // Texas set its own clock, so Texas gets it...
        $this->assertSame('24h', $me['display']['time_format']);

        // ...and inherits the date it did not set, from the organization.
        // A payload that answered '24h' here and also changed the date would
        // mean the two were being sent as a set rather than resolved per key.
        $this->assertSame('M/D/YYYY', $me['display']['date_format']);

        // And the scope named beside them is the same place the values came
        // from. If these two ever disagree, the app is writing one franchise's
        // dates under another franchise's heading.
        $this->assertSame('franchise', $me['scope']['type']);
        $this->assertSame($this->w['texas']->id, $me['scope']['id']);
    }

    public function test_a_sibling_without_an_override_still_reads_the_organization(): void
    {
        $this->standIn('franchise:'.$this->w['ct']->id);

        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        // Connecticut set nothing, so it must not pick up Texas's clock. The
        // resolver walks UP the chain, never sideways, and this is the
        // assertion that would catch a resolver reaching for "any franchise
        // override in this organization".
        $this->assertSame('12h', $me['display']['time_format']);
    }

    public function test_the_same_person_reads_a_different_clock_after_switching(): void
    {
        // The owner's actual complaint, in the only form the server can answer
        // it: one account, two views, two answers. The scope switcher refetches
        // /me, so this is exactly the sequence the client performs.
        $this->standIn(null);
        $this->assertSame('12h', $this->as()->getJson('/api/v1/me')->json('data.display.time_format'));

        $this->app['auth']->forgetGuards();

        $this->standIn('franchise:'.$this->w['texas']->id);
        $this->assertSame('24h', $this->as()->getJson('/api/v1/me')->json('data.display.time_format'));
    }

    public function test_a_stored_scope_that_no_longer_holds_falls_back_with_its_settings(): void
    {
        // ViewScope quietly drops a view the user can no longer reach, and the
        // display settings have to fall back WITH it. Resolving them against
        // the stored preference instead of the resolved scope would report the
        // organization's view alongside a deleted franchise's clock.
        $this->standIn('franchise:'.\Illuminate\Support\Str::uuid()->toString());

        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame('organization', $me['scope']['type']);
        $this->assertSame('12h', $me['display']['time_format']);
    }

    public function test_only_the_keys_something_reads_are_carried(): void
    {
        $me = $this->as()->getJson('/api/v1/me')->assertOk()->json('data');

        // /me is on the path to the first frame and is refetched on every scope
        // change. The registry holds thirty settings; this block holds the ones
        // a screen formats from, and `week_starts_on` and `measurement_units`
        // are absent on purpose — nothing in the client reads them yet, and a
        // value that travels but changes nothing is the failure being fixed
        // here, not a head start on the next feature.
        $this->assertSame(['date_format', 'time_format'], array_keys($me['display']));
    }
}
