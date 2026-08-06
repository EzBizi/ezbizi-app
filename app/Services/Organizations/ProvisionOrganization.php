<?php

namespace App\Services\Organizations;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Create a complete, usable organization in one transaction (plan §3, §13).
 *
 * "Complete" is the operative word. The plan requires a Company to have at least
 * one Franchise from the moment it exists (§3 answer 1-3), and an organization
 * with no administrator is unusable. So this creates the whole minimum viable
 * tree — organization, Administrator role with every permission, first admin
 * user, first company, first franchise — or nothing at all.
 *
 * Called from two places (§13): self-serve signup, and staff creation from the
 * control plane. Both land here so neither can produce a half-built tenant.
 */
class ProvisionOrganization
{
    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{name:string, email:string, password:string}  $administrator
     * @return array{organization:Organization, administrator:User, company:Company, franchise:Franchise}
     */
    public function __invoke(
        string $organizationName,
        array $administrator,
        string $companyName,
        string $franchiseName,
        ?string $timezone = 'America/New_York',
        bool $isDemo = false,
    ): array {
        return DB::transaction(function () use (
            $organizationName, $administrator, $companyName, $franchiseName, $timezone, $isDemo
        ) {
            $organization = Organization::create([
                'name'               => $organizationName,
                'slug'               => $this->uniqueSlug($organizationName),
                'reporting_currency' => 'USD',   // single-currency v1 (§7)
                'franchise_label'    => 'Franchise',
                'shares_customers'   => true,    // ON by default (§5.2, owner decision)
                'trial_ends_at'      => now()->addMonth(),  // 1-month trial (§13)
                'is_active'          => true,
                'is_demo'            => $isDemo,
            ]);

            // Everything below is tenant data, so it must run inside the tenant —
            // BelongsToTenant refuses to write without one, by design.
            return $this->tenant->run($organization, function () use (
                $organization, $administrator, $companyName, $franchiseName, $timezone
            ) {
                $role = $this->createAdministratorRole($organization);

                $admin = User::create([
                    'organization_id' => $organization->id,
                    'role_id'         => $role->id,
                    'name'            => $administrator['name'],
                    'email'           => $administrator['email'],
                    'password'        => $administrator['password'],
                    'is_active'       => true,
                ]);

                $company = Company::create([
                    'organization_id' => $organization->id,
                    'name'            => $companyName,
                    'is_active'       => true,
                ]);

                // §3: a company is never franchise-less. Business data lives at
                // franchise level, so a company without one has nowhere to put it.
                $franchise = Franchise::create([
                    'organization_id' => $organization->id,
                    'company_id'      => $company->id,
                    'name'            => $franchiseName,
                    'timezone'        => $timezone,
                    'currency'        => 'USD',
                    'is_active'       => true,
                ]);

                // The administrator is assigned at ORGANIZATION level, so their
                // reach covers companies and franchises added later without
                // anyone remembering to re-assign them (§4).
                Assignment::create([
                    'organization_id'          => $organization->id,
                    'user_id'                  => $admin->id,
                    'assigned_organization_id' => $organization->id,
                ]);

                return [
                    'organization'  => $organization,
                    'administrator' => $admin,
                    'company'       => $company,
                    'franchise'     => $franchise,
                ];
            });
        });
    }

    /**
     * The organization's own top role.
     *
     * No permission rows are attached: Role::hasPermission() returns true for
     * Administrator unconditionally. That's what keeps a module added next year
     * automatically visible to the person who owns the organization, instead of
     * invisible until someone remembers to backfill every Administrator role in
     * every tenant.
     */
    private function createAdministratorRole(Organization $organization): Role
    {
        return Role::create([
            'organization_id' => $organization->id,
            'name'            => Role::ADMINISTRATOR,
            'is_system'       => true,
        ]);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';
        $slug = $base;
        $n    = 1;

        // Organization is not tenant-scoped, so this sees every slug — correct here.
        while (Organization::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$n);
        }

        return $slug;
    }
}
