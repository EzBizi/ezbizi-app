<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\PlatformSubscriptionDefaults;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Organization provisioning (plan §3, §13) — the first thing that makes the
 * hierarchy real rather than theoretical.
 */
class ProvisionOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function provision(string $name = 'Milk United Group', bool $demo = false, ?int $trialDays = null): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: [
                'name'     => 'Jose Demasi',
                'email'    => 'jose@example.com',
                'password' => 'a-real-password',
            ],
            companyName:   'Serenisima',
            franchiseName: 'New Jersey',
            isDemo:        $demo,
            trialDays:     $trialDays,
        );
    }

    public function test_creates_a_complete_usable_tree(): void
    {
        ['organization' => $org, 'administrator' => $admin,
         'company' => $company, 'franchise' => $franchise] = $this->provision();

        $this->assertSame('Milk United Group', $org->name);
        $this->assertSame('Serenisima', $company->name);
        $this->assertSame('New Jersey', $franchise->name);

        // Everything belongs to the same tenant.
        $this->assertSame($org->id, $admin->organization_id);
        $this->assertSame($org->id, $company->organization_id);
        $this->assertSame($org->id, $franchise->organization_id);
        $this->assertSame($company->id, $franchise->company_id);
    }

    public function test_the_first_company_always_has_a_franchise(): void
    {
        ['organization' => $org, 'company' => $company] = $this->provision();

        // §3: business data lives at franchise level, so a company without one
        // has nowhere to put anything.
        app(TenantContext::class)->run($org, function () use ($company) {
            $this->assertCount(1, $company->franchises()->get());
        });
    }

    public function test_administrator_role_is_a_system_role_holding_everything(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->provision();

        app(TenantContext::class)->run($org, function () use ($admin) {
            $role = $admin->role;

            $this->assertTrue($role->is_system);
            $this->assertTrue($role->isAdministrator());

            // No permission rows are attached on purpose: Administrator returns
            // true for anything, so a module added next year is automatically
            // visible to the owner rather than invisible until someone backfills
            // every Administrator role in every tenant.
            $this->assertSame(0, $role->permissions()->count());
            $this->assertTrue($role->hasPermission('a.module.that.does.not.exist.yet'));
        });
    }

    public function test_administrator_is_assigned_at_organization_level(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $franchise] = $this->provision();

        app(TenantContext::class)->run($org, function () use ($org, $admin, $franchise) {
            $assignment = Assignment::where('user_id', $admin->id)->firstOrFail();

            $this->assertSame(Assignment::SCOPE_ORGANIZATION, $assignment->scopeType());
            $this->assertSame($org->id, $assignment->assigned_organization_id);

            // Org-level reach means franchises added later are covered without
            // anyone remembering to re-assign the administrator.
            $this->assertTrue($admin->fresh()->canActOnFranchise($franchise));

            $later = Franchise::create([
                'organization_id' => $org->id,
                'company_id'      => $franchise->company_id,
                'name'            => 'Pennsylvania',
            ]);

            $this->assertTrue($admin->fresh()->canActOnFranchise($later));
        });
    }

    public function test_starts_a_trial_of_the_platform_default_length_in_days(): void
    {
        ['organization' => $org] = $this->provision();

        $this->assertNotNull($org->trial_ends_at);

        // Days, never addMonth(). §13 rejects calendar months outright — they
        // make a February trial shorter than a March one from the same wording.
        $this->assertEqualsWithDelta(
            now()->addDays(PlatformSubscriptionDefaults::trialDays())->timestamp,
            $org->trial_ends_at->timestamp,
            5
        );
        $this->assertFalse($org->isReadOnly());
    }

    public function test_the_trial_length_is_data_rather_than_a_deploy(): void
    {
        // "Sales can extend it" (owner, 2026-08-07). Changing the control-plane
        // row changes what the next signup gets, with no release.
        PlatformSubscriptionDefaults::current()->update(['trial_days' => 45]);

        ['organization' => $org] = $this->provision();

        $this->assertEqualsWithDelta(now()->addDays(45)->timestamp, $org->trial_ends_at->timestamp, 5);
    }

    public function test_one_organization_can_be_given_a_longer_trial_than_the_default(): void
    {
        ['organization' => $org] = $this->provision(trialDays: 90);

        // The per-organization override, for a tenant created by staff from the
        // control plane (§13). Everybody else still gets the default.
        $this->assertEqualsWithDelta(now()->addDays(90)->timestamp, $org->trial_ends_at->timestamp, 5);
        $this->assertSame(30, PlatformSubscriptionDefaults::trialDays());
    }

    public function test_goes_read_only_when_the_trial_lapses(): void
    {
        ['organization' => $org] = $this->provision();

        $org->update(['trial_ends_at' => now()->subDay()]);

        // §13: the owner may still log in and view, but not enter new data.
        // Enforced server-side, never by hiding buttons.
        $this->assertTrue($org->fresh()->isReadOnly());
    }

    public function test_defaults_to_usd_and_a_renameable_franchise_label(): void
    {
        ['organization' => $org] = $this->provision();

        $this->assertSame('USD', $org->reporting_currency);   // single-currency v1 (§7)
        $this->assertSame('Franchise', $org->franchise_label); // renameable (§8)
    }

    public function test_slugs_never_collide(): void
    {
        $a = $this->provision('Acme Services');
        $b = $this->provision('Acme Services');

        $this->assertNotSame(
            $a['organization']->slug,
            $b['organization']->slug,
            'Two organizations may share a name; the slug must still be unique.'
        );
    }

    public function test_provisioning_is_all_or_nothing(): void
    {
        $before = Organization::count();

        try {
            app(ProvisionOrganization::class)(
                organizationName: 'Doomed Co',
                administrator: [
                    'name'     => 'X',
                    'email'    => 'x@example.com',
                    'password' => 'pw',
                ],
                companyName:   'C',
                franchiseName: str_repeat('x', 500), // too long for the column
            );
        } catch (\Throwable) {
            // expected
        }

        // A half-built tenant — an organization with no franchise, or no admin —
        // is worse than a failed signup: it looks valid and behaves oddly.
        $this->assertSame($before, Organization::count());
        $this->assertNull(Organization::where('name', 'Doomed Co')->first());
    }

    public function test_a_demo_organization_is_flagged_as_such(): void
    {
        ['organization' => $org] = $this->provision('Demo Co', demo: true);

        // §12: must be clearly flagged and resettable, so a prospect poking
        // around never contaminates real data.
        $this->assertTrue($org->is_demo);
    }
}
