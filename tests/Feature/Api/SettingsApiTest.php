<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The settings API (plan §6, §11.4) — the first real exercise of the REST
 * contract, and therefore the pattern the rest of the API follows.
 *
 * The tests that matter most are the boundary ones: another organization's
 * scope id must be unreachable, a franchise outside your assignments must be
 * refused, and a lapsed subscription must block writes while still allowing
 * reads.
 */
class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private function org(string $name, string $email): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => 'Admin', 'email' => $email, 'password' => 'pw-here-please'],
            companyName: 'Co', franchiseName: 'F1',
        );
    }

    // ── reading ──────────────────────────────────────────────────

    public function test_lists_settings_with_their_source(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}");

        $response->assertOk()
            ->assertJsonStructure(['data' => [['key', 'label', 'type', 'value', 'editable_here', 'source']]]);

        $locale = collect($response->json('data'))->firstWhere('key', 'locale');
        $this->assertSame('en', $locale['value']);
        $this->assertNull($locale['source'], 'Nothing set yet, so the default applies and there is no source.');
    }

    public function test_marks_inherited_values_as_such(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", ['value' => 'es'])
            ->assertOk();

        $response = $this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}");
        $locale = collect($response->json('data'))->firstWhere('key', 'locale');

        // "Where did this come from" is a different question from "what is it",
        // and an admin needs both to know which level to change.
        $this->assertSame('es', $locale['value']);
        $this->assertSame('organization', $locale['source']['type']);
        $this->assertTrue($locale['source']['inherited']);
    }

    public function test_reports_whether_a_setting_is_editable_at_this_scope(): void
    {
        ['administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $data = collect($this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}")->json('data'));

        // The client renders a control or a read-only inherited value from this.
        $this->assertFalse($data->firstWhere('key', 'security.require_two_factor')['editable_here']);
        $this->assertTrue($data->firstWhere('key', 'money.tax_rate')['editable_here']);
    }

    // ── writing ──────────────────────────────────────────────────

    public function test_updates_a_setting(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f->id}", ['value' => 6.625])
            ->assertOk()
            ->assertJsonPath('data.value', 6.625);
    }

    public function test_rejects_an_invalid_enum_value(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", ['value' => 'fr'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    public function test_rejects_setting_a_security_policy_below_organization_level(): void
    {
        ['administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        // Even an Administrator cannot — the restriction is structural, not a
        // permission. Otherwise a franchise admin could weaken org policy.
        $this->patchJson(
            "/api/v1/settings/security.require_two_factor?scope_type=franchise&scope_id={$f->id}",
            ['value' => false]
        )->assertStatus(422);
    }

    public function test_unknown_settings_return_404(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/not.a.real.setting?scope_type=organization&scope_id={$org->id}", ['value' => 1])
            ->assertNotFound()
            ->assertJsonPath('code', 'unknown_setting');
    }

    public function test_deleting_an_override_restores_inheritance(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", ['value' => 'es']);
        $this->patchJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$f->id}", ['value' => 'en']);

        $this->deleteJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$f->id}")
            ->assertOk()
            ->assertJsonPath('data.value', 'es');   // inheriting again
    }

    // ── tenancy boundary ─────────────────────────────────────────

    public function test_cannot_read_another_organizations_scope(): void
    {
        ['administrator' => $adminA] = $this->org('Alpha', 'a@a.test');
        ['franchise' => $franchiseB] = $this->org('Bravo', 'b@b.test');

        Sanctum::actingAs($adminA);

        // Every lookup is tenant-scoped, so B's id simply isn't found from A.
        $this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$franchiseB->id}")
            ->assertNotFound();
    }

    public function test_cannot_write_to_another_organizations_scope(): void
    {
        ['administrator' => $adminA] = $this->org('Alpha', 'a@a.test');
        ['franchise' => $franchiseB] = $this->org('Bravo', 'b@b.test');

        Sanctum::actingAs($adminA);

        $this->patchJson(
            "/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$franchiseB->id}",
            ['value' => 99]
        )->assertNotFound();
    }

    // ── reach (§4) ───────────────────────────────────────────────

    public function test_a_franchise_user_cannot_configure_another_franchise(): void
    {
        ['organization' => $org, 'company' => $company, 'franchise' => $f1] = $this->org('Alpha', 'a@a.test');

        [$scoped, $f2] = app(TenantContext::class)->run($org, function () use ($org, $company) {
            $f2 = Franchise::create([
                'organization_id' => $org->id, 'company_id' => $company->id, 'name' => 'F2',
            ]);

            $role = Role::create(['organization_id' => $org->id, 'name' => 'Manager']);
            $role->permissions()->attach(
                \App\Models\Permission::where('key', 'organizations.edit')->first()?->id ?? []
            );

            $user = User::create([
                'organization_id' => $org->id, 'role_id' => $role->id,
                'name' => 'Scoped', 'email' => 'scoped@a.test', 'password' => 'pw-here-please',
            ]);

            Assignment::create([
                'organization_id' => $org->id, 'user_id' => $user->id,
                'assigned_franchise_id' => $company->franchises()->first()->id,
            ]);

            return [$user, $f2];
        });

        Sanctum::actingAs($scoped);

        // Holding the permission is not enough — reach is a separate gate.
        $this->patchJson("/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f2->id}", ['value' => 5])
            ->assertForbidden();
    }

    public function test_a_user_without_the_permission_is_refused(): void
    {
        ['organization' => $org, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');

        $noPerms = app(TenantContext::class)->run($org, function () use ($org, $f) {
            $role = Role::create(['organization_id' => $org->id, 'name' => 'Viewer']);
            $user = User::create([
                'organization_id' => $org->id, 'role_id' => $role->id,
                'name' => 'Viewer', 'email' => 'viewer@a.test', 'password' => 'pw-here-please',
            ]);
            Assignment::create([
                'organization_id' => $org->id, 'user_id' => $user->id, 'assigned_franchise_id' => $f->id,
            ]);

            return $user;
        });

        Sanctum::actingAs($noPerms);

        $this->patchJson("/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f->id}", ['value' => 5])
            ->assertForbidden();
    }

    // ── subscription state (§13) ─────────────────────────────────

    public function test_a_lapsed_subscription_blocks_writes_but_allows_reads(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        $org->update(['trial_ends_at' => now()->subDay()]);

        Sanctum::actingAs($admin->fresh());

        // Reads keep working — the owner may still see their own data.
        $this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}")->assertOk();

        // Writes do not.
        $this->patchJson("/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f->id}", ['value' => 5])
            ->assertStatus(402)
            ->assertJsonPath('code', 'subscription_inactive');
    }

    // ── auth ─────────────────────────────────────────────────────

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/v1/settings?scope_type=organization&scope_id=00000000-0000-0000-0000-000000000000')
            ->assertUnauthorized();
    }

    public function test_me_returns_reach_not_every_franchise(): void
    {
        ['organization' => $org, 'company' => $company, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');

        app(TenantContext::class)->run($org, fn () => Franchise::create([
            'organization_id' => $org->id, 'company_id' => $company->id, 'name' => 'F2',
        ]));

        Sanctum::actingAs($admin);

        // The client offers what the user can actually act on, rather than
        // showing everything and failing on save.
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonCount(2, 'data.franchises')
            ->assertJsonPath('data.organization.is_read_only', false);
    }

    public function test_responses_carry_a_correlation_id(): void
    {
        ['administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        // Ties the request to every audit event it raised (§10).
        $this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}")
            ->assertOk()
            ->assertHeader('X-Correlation-Id');
    }
}
