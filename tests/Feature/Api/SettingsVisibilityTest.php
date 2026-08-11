<?php

namespace Tests\Feature\Api;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the settings screen is told it may do (plan §6, §2.1).
 *
 * The failure these defend against is a screen that looks writable and is not.
 * Every control rendered live for everybody, because the payload answered "may
 * this SCOPE hold a value" and the client had no way to ask "may this PERSON
 * set it" — so somebody without the permission flicked a switch, watched it
 * animate, and got a 403 from a control that had already moved.
 */
class SettingsVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private array $alpha;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->alpha = [
            'org'       => $r['organization'],
            'admin'     => $r['administrator'],
            'company'   => $r['company'],
            'franchise' => $r['franchise'],
        ];
    }

    private function tokenFor(string $email, string $password = 'a-real-password'): string
    {
        $token = $this->postJson('/api/v1/auth/login', compact('email', 'password'))
            ->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $token;
    }

    /** Somebody who may read settings but not change them. */
    private function readOnlyUser(): string
    {
        return app(TenantContext::class)->run($this->alpha['org'], function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Viewer', 'is_administrator' => false,
            ]);

            $view = Permission::where('key', 'organizations.view')->first();

            if ($view !== null) {
                $role->permissions()->attach($view->getKey());
            }

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => $role->getKey(),
                'name' => 'Viewer', 'email' => 'v@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            \App\Models\Assignment::create([
                'organization_id'      => $this->alpha['org']->id,
                'user_id'              => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return 'v@alpha.test';
        }) === 'v@alpha.test' ? $this->tokenFor('v@alpha.test') : '';
    }

    public function test_an_administrator_is_told_they_may_edit(): void
    {
        $settings = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor('a@alpha.test'))
            ->getJson('/api/v1/settings?scope_type=organization&scope_id='.$this->alpha['org']->id)
            ->assertOk()->json('data');

        $editable = array_filter($settings, fn ($s) => $s['may_edit'] === true);

        $this->assertNotEmpty($editable);
    }

    public function test_somebody_without_the_permission_is_told_so_before_they_click(): void
    {
        $token = $this->readOnlyUser();

        $settings = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/settings?scope_type=organization&scope_id='.$this->alpha['org']->id)
            ->assertOk()->json('data');

        $this->assertNotEmpty($settings);

        foreach ($settings as $setting) {
            $this->assertFalse(
                $setting['may_edit'],
                "{$setting['key']} claimed to be editable by somebody without the permission.",
            );

            // And told WHY, in words. The row used to carry the bare permission
            // key for the client to print — "Read-only — needs
            // organizations.edit" — which R8 forbids: a wire name on a
            // customer's screen, naming something that appears nowhere in the
            // product and nobody they could go and ask.
            $this->assertNotEmpty($setting['may_edit_reason']);
            $this->assertStringNotContainsString(
                'organizations.edit',
                $setting['may_edit_reason'],
                "{$setting['key']} explained itself with a permission key.",
            );
            $this->assertStringContainsString(
                'part of a role',
                $setting['may_edit_reason'],
                "{$setting['key']} said what was missing and not who could add it.",
            );
            $this->assertArrayNotHasKey(
                'permission',
                $setting,
                'A field whose only use was being printed is one somebody prints again.',
            );
        }
    }

    public function test_the_claim_matches_what_a_save_actually_does(): void
    {
        $token = $this->readOnlyUser();

        $settings = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/settings?scope_type=organization&scope_id='.$this->alpha['org']->id)
            ->assertOk()->json('data');

        $this->app['auth']->forgetGuards();

        // The whole point of the flag is that it predicts the answer. A flag
        // that says "no" while the endpoint says "yes" is worse than no flag —
        // it hides a capability instead of only mislabelling it.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->patchJson(
                '/api/v1/settings/'.$settings[0]['key']
                .'?scope_type=organization&scope_id='.$this->alpha['org']->id,
                ['value' => 'anything'],
            )->assertForbidden();
    }

    public function test_settings_can_be_read_and_written_at_company_scope(): void
    {
        $token = $this->tokenFor('a@alpha.test');
        $company = $this->alpha['company'];

        // §6 allows a company tier and the client had no way to select one, so
        // any setting scoped organization+company could only be configured
        // through the API or not at all.
        $settings = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/settings?scope_type=company&scope_id={$company->id}")
            ->assertOk()->json('data');

        $this->assertNotEmpty($settings);

        $atCompany = array_values(array_filter($settings, fn ($s) => $s['editable_here']));

        $this->assertNotEmpty($atCompany, 'No setting is settable at company scope.');
    }

    public function test_me_reports_the_companies_behind_your_franchises(): void
    {
        $me = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor('a@alpha.test'))
            ->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertSame(
            [$this->alpha['company']->name],
            array_column($me['companies'], 'name'),
        );
    }

    public function test_every_setting_carries_its_help_text_or_nothing(): void
    {
        $settings = $this->withHeader('Authorization', 'Bearer '.$this->tokenFor('a@alpha.test'))
            ->getJson('/api/v1/settings?scope_type=organization&scope_id='.$this->alpha['org']->id)
            ->assertOk()->json('data');

        // The screen renders this now. The assertion is that the key exists on
        // every row — a missing key would throw in the client rather than
        // rendering nothing.
        foreach ($settings as $setting) {
            $this->assertArrayHasKey('help', $setting);
        }
    }

    /**
     * A franchise assignment must not reach the organization (§4, TODO #12).
     *
     * Measured before the fix, on a plain non-administrator role carrying only
     * `organizations.edit` and assigned to ONE franchise: it lowered
     * security.password_min_length from 12 to 4 and switched
     * security.require_two_factor on, both 200 OK, for the whole tenant.
     *
     * authorizeEdit had a branch for Franchise and one for Company and simply
     * none for Organization, so holding the permission was the entire test.
     */
    public function test_a_franchise_user_cannot_rewrite_organization_settings(): void
    {
        $email = app(TenantContext::class)->run($this->alpha['org'], function () {
            $role = Role::create([
                'organization_id' => $this->alpha['org']->id,
                'name' => 'Branch Manager', 'is_administrator' => false,
            ]);

            // firstOrCreate, because the catalogue is synced by an artisan
            // command rather than a migration and the test database has none.
            // Skipping when it is absent would make this test quietly stop
            // covering anything, which is the failure mode it exists to catch.
            $edit = Permission::firstOrCreate(
                ['key' => 'organizations.edit'],
                ['module' => 'organizations', 'action' => 'edit', 'label' => 'Change organization settings'],
            );

            $role->permissions()->attach($edit->getKey());

            $user = User::create([
                'organization_id' => $this->alpha['org']->id,
                'role_id' => $role->getKey(),
                'name' => 'Branch Manager', 'email' => 'bm@alpha.test',
                'password' => 'a-real-password', 'is_active' => true,
            ]);

            \App\Models\Assignment::create([
                'organization_id'       => $this->alpha['org']->id,
                'user_id'               => $user->id,
                'assigned_franchise_id' => $this->alpha['franchise']->id,
            ]);

            return 'bm@alpha.test';
        });

        $token = $this->tokenFor($email);
        $org   = $this->alpha['org']->id;

        // The write is refused...
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(
                '/api/v1/settings/security.password_min_length'
                ."?scope_type=organization&scope_id={$org}",
                ['value' => 4],
            )
            ->assertForbidden();

        // ...and, just as importantly, the screen never offered it. A control
        // that animates and then 403s is the regression this file exists for.
        $row = collect(
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->getJson("/api/v1/settings?scope_type=organization&scope_id={$org}")
                ->assertOk()->json('data')
        )->firstWhere('key', 'security.password_min_length');

        $this->assertFalse($row['may_edit'], 'may_edit disagreed with what the write does.');

        // And the franchise they ARE assigned to still works, so this is a reach
        // check and not a blanket refusal.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(
                '/api/v1/settings/money.tax_rate'
                .'?scope_type=franchise&scope_id='.$this->alpha['franchise']->id,
                ['value' => 6.35],
            )
            ->assertOk();
    }
}
