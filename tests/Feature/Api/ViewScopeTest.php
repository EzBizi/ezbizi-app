<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What you see, depending on where you are standing (plan §3 roll-ups, §4).
 *
 * Built directly from the owner's worked example, because it is the clearest
 * statement of the rule and the one number in it that is easy to get wrong:
 *
 *   A-1 Chimney / Connecticut   10 customers
 *   A-1 Chimney / Ohio          20
 *   Everdoors   / Ohio          15
 *   Premier Garage / Connecticut 5      (Premier is a SUB COMPANY of Everdoors)
 *
 *   A1-Group   → 50    everything
 *   A-1 Chimney → 30
 *   Everdoors   → 20   its own 15 PLUS Premier's 5
 *   Everdoors/Ohio → 15
 *
 * Everdoors is 20, not 15. A parent that cannot see its subsidiary's book would
 * be a strange parent, and the sub-company step is the one the settings
 * resolver's first draft left out.
 */
class ViewScopeTest extends TestCase
{
    use RefreshDatabase;

    private array $w = [];

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'A1-Group',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'A-1 Chimney', franchiseName: 'Connecticut',
        );

        $this->w['org']     = $r['organization'];
        $this->w['admin']   = $r['administrator'];
        $this->w['chimney'] = $r['company'];
        $this->w['ct']      = $r['franchise'];

        app(TenantContext::class)->run($this->w['org'], function () {
            $this->w['oh'] = $this->franchise($this->w['chimney'], 'Ohio');

            $this->w['everdoors'] = Company::create([
                'organization_id' => $this->w['org']->id,
                'name' => 'Everdoors', 'is_active' => true,
            ]);
            $this->w['everOh'] = $this->franchise($this->w['everdoors'], 'Ohio');

            $this->w['premier'] = Company::create([
                'organization_id'   => $this->w['org']->id,
                'parent_company_id' => $this->w['everdoors']->id,
                'name' => 'Premier Garage', 'is_active' => true,
            ]);
            $this->w['premierCt'] = $this->franchise($this->w['premier'], 'Connecticut');

            $this->customers($this->w['ct'], 10);
            $this->customers($this->w['oh'], 20);
            $this->customers($this->w['everOh'], 15);
            $this->customers($this->w['premierCt'], 5);
        });
    }

    private function franchise(Company $company, string $name): Franchise
    {
        return Franchise::create([
            'organization_id' => $this->w['org']->id,
            'company_id' => $company->id,
            'name' => $name, 'timezone' => 'America/New_York',
            'currency' => 'USD', 'is_active' => true,
        ]);
    }

    private function customers(Franchise $franchise, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Customer::create([
                'owning_franchise_id' => $franchise->id,
                'number' => "{$franchise->name}-{$i}-".bin2hex(random_bytes(2)),
                'name'   => "Person {$franchise->name} {$i}",
            ]);
        }
    }

    private function scopeTo(string $scope): void
    {
        $user = User::withoutGlobalScopes()->find($this->w['admin']->id);

        $user->forceFill([
            'preferences' => array_merge($user->preferences ?? [], ['navigation.scope' => $scope]),
        ])->save();
    }

    private function scope(): ViewScope
    {
        return ViewScope::forUser(User::withoutGlobalScopes()->find($this->w['admin']->id));
    }

    private function countInView(): int
    {
        return Customer::withoutGlobalScopes()
            ->whereIn('owning_franchise_id', $this->scope()->franchiseIds())
            ->count();
    }

    // ── the owner's four numbers ─────────────────────────────────

    public function test_the_organization_view_shows_everything(): void
    {
        $this->scopeTo('organization');

        $this->assertSame(50, $this->countInView());
    }

    public function test_a_company_view_shows_its_own_franchises(): void
    {
        $this->scopeTo('company:'.$this->w['chimney']->id);

        $this->assertSame(30, $this->countInView());
    }

    public function test_a_company_view_includes_its_sub_companies(): void
    {
        $this->scopeTo('company:'.$this->w['everdoors']->id);

        // 15 of its own plus Premier's 5. §3: "Company view = all its franchises
        // + sub-companies combined."
        $this->assertSame(20, $this->countInView());
    }

    public function test_a_franchise_view_shows_only_that_franchise(): void
    {
        $this->scopeTo('franchise:'.$this->w['everOh']->id);

        $this->assertSame(15, $this->countInView());
    }

    public function test_a_sub_company_never_sees_its_parent(): void
    {
        $this->scopeTo('company:'.$this->w['premier']->id);

        // Roll-ups go UP. Premier sees its own 5 and nothing of Everdoors'.
        $this->assertSame(5, $this->countInView());
    }

    public function test_the_company_views_add_up_to_the_organizations(): void
    {
        $total = 0;

        foreach (['chimney', 'everdoors'] as $key) {
            $this->scopeTo('company:'.$this->w[$key]->id);
            $total += $this->countInView();
        }

        // The arithmetic only works because customers are counted by their ONE
        // owning franchise. Counting everyone a company has done work for would
        // double-count anybody served by two, and these would not sum.
        $this->assertSame(50, $total);
    }

    // ── the scope can only ever narrow ───────────────────────────

    public function test_a_scope_outside_your_reach_falls_back_to_the_organization(): void
    {
        $beta = app(ProvisionOrganization::class)(
            organizationName: 'Beta',
            administrator: ['name' => 'B', 'email' => 'b@beta.test', 'password' => 'a-real-password'],
            companyName: 'Beta Co', franchiseName: 'BF1',
        );

        $this->scopeTo('company:'.$beta['company']->id);

        // A preference is user input that happens to have been saved earlier.
        // Pointing it at another tenant must resolve to nothing of theirs.
        $this->assertTrue($this->scope()->isOrganization());
        $this->assertSame(50, $this->countInView());
    }

    public function test_a_scope_naming_something_deleted_falls_back(): void
    {
        $this->scopeTo('franchise:019f0000-0000-7000-8000-000000000000');

        $this->assertTrue($this->scope()->isOrganization());
    }

    public function test_nonsense_falls_back_rather_than_failing(): void
    {
        // Refusing would leave somebody with an app that will not load and no
        // control to fix it, because the control lives inside the app.
        foreach (['', 'company', 'wat:123', 'organization:extra'] as $bad) {
            $this->scopeTo($bad);
            $this->assertTrue($this->scope()->isOrganization(), "'{$bad}' should fall back");
        }
    }

    public function test_the_scope_never_widens_beyond_reach(): void
    {
        $limited = app(TenantContext::class)->run($this->w['org'], function () {
            $user = User::create([
                'organization_id' => $this->w['org']->id,
                'role_id' => $this->w['admin']->role_id,
                'name' => 'Limited', 'email' => 'l@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            \App\Models\Assignment::create([
                'organization_id' => $this->w['org']->id,
                'user_id' => $user->id,
                'assigned_franchise_id' => $this->w['ct']->id,
            ]);

            return $user;
        });

        $limited->forceFill([
            'preferences' => ['navigation.scope' => 'organization'],
        ])->save();

        // Even asking for the whole organization, reach is the floor: this
        // person is assigned to one franchise and sees its 10.
        $this->assertSame(
            [$this->w['ct']->id],
            ViewScope::forUser($limited->fresh())->franchiseIds(),
        );
    }

    // ── what the endpoints do with it ────────────────────────────

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_customer_list_follows_the_view(): void
    {
        $this->scopeTo('company:'.$this->w['everdoors']->id);

        $this->assertSame(
            20,
            $this->as()->getJson('/api/v1/customers')->assertOk()->json('meta.total'),
        );
    }

    public function test_search_still_reaches_the_whole_organization(): void
    {
        $this->scopeTo('franchise:'.$this->w['premierCt']->id);

        // §5.2's whole point is that a customer served by two branches is ONE
        // person. Somebody who cannot find an existing record creates a
        // duplicate — the exact failure sharing exists to prevent — so search
        // deliberately escapes the scope.
        $found = $this->as()
            ->getJson('/api/v1/customers?search=Person Ohio')
            ->assertOk()->json('meta.total');

        $this->assertSame(35, $found);
    }

    public function test_me_reports_the_resolved_scope_not_the_stored_string(): void
    {
        $this->scopeTo('company:'.$this->w['everdoors']->id);

        $this->as()->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'company')
            ->assertJsonPath('data.scope.label', 'Everdoors');
    }
}
