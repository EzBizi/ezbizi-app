<?php

namespace App\Services\Organizations;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\PlatformSubscriptionDefaults;
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
     * @param  int|null  $trialDays  overrides the platform default for this one
     *                               organization, so a longer trial can be handed
     *                               out without changing it for everybody.
     *                               NOTE: no caller passes this yet. There is no
     *                               signup form and no control-plane creation
     *                               screen — every caller of this class is a test
     *                               — so today the parameter is reachable only
     *                               from tests, and extending a trial in practice
     *                               means writing `organizations.trial_ends_at`
     *                               by hand. The owner's decision is that sales
     *                               can extend a trial; this is the seam that
     *                               makes that a one-line change once either
     *                               entry point exists, not a claim that it
     *                               already works.
     * @return array{organization:Organization, administrator:User, company:Company, franchise:Franchise}
     */
    public function __invoke(
        string $organizationName,
        array $administrator,
        string $companyName,
        string $franchiseName,
        ?string $timezone = 'America/New_York',
        bool $isDemo = false,
        ?int $trialDays = null,
        /*
         * Has the administrator proved they can read that mailbox? (TODO #22)
         *
         * Defaults to TRUE because provisioning is a structural act - build an
         * organization and its owner - and every caller but one already knows
         * the answer is yes. The review seed invents its accounts, and a test
         * fixture is scaffolding for a tenant that is supposed to already exist.
         *
         * The one caller that passes FALSE is self-serve signup, because that is
         * the only route where somebody typed an address nobody has checked.
         * Putting the decision in the caller rather than here is deliberate: how
         * an owner arrived is something only the caller knows, and this service
         * would have to guess.
         *
         * The unsafe direction of this default is somebody adding a second
         * self-serve route and forgetting to pass false. SignupTest pins the
         * signup path against exactly that - it asserts the new account cannot
         * write and that email_verified_at is null - so the default cannot flip
         * silently for the route that matters.
         */
        bool $administratorVerified = true,
    ): array {
        // Resolved before the transaction opens so the row this reads is the
        // same one an operator would edit, not something the transaction is
        // holding open.
        $trialDays ??= PlatformSubscriptionDefaults::trialDays();

        return DB::transaction(function () use (
            $organizationName, $administrator, $companyName, $franchiseName, $timezone, $isDemo,
            $trialDays, $administratorVerified
        ) {
            $organization = Organization::create([
                'name'               => $organizationName,
                'slug'               => $this->uniqueSlug($organizationName),
                'reporting_currency' => 'USD',   // single-currency v1 (§7)
                'franchise_label'    => 'Franchise',

                // A fixed number of DAYS, and the exact expiry stored rather
                // than recomputed (§13). This was addMonth(), which §13 rejects
                // outright: a February signup got 28 days and a July one 31,
                // from identical wording in the same contract. The length itself
                // is data — see PlatformSubscriptionDefaults for why it is not a
                // constant here and not a tenant setting either.
                'trial_ends_at'      => now()->addDays($trialDays),

                'is_active'          => true,
                'is_demo'            => $isDemo,
            ]);

            // Everything below is tenant data, so it must run inside the tenant —
            // BelongsToTenant refuses to write without one, by design.
            return $this->tenant->run($organization, function () use (
                $organization, $administrator, $companyName, $franchiseName, $timezone,
                $administratorVerified
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

                /*
                 * Written in BOTH directions on purpose, never left to a default.
                 *
                 * forceFill rather than a key in create() above, because
                 * `email_verified_at` is deliberately absent from the model's
                 * #[Fillable] list - a verification state must never be mass
                 * assignable - so passing it there is silently discarded.
                 *
                 * The null branch is not redundant. The test harness fills this
                 * in for any user created without one, so that several hundred
                 * fixtures built with a bare User::create do not all fail on a
                 * 403 that says nothing about them. Signup is the one path that
                 * genuinely produces an unconfirmed account, and stating the null
                 * explicitly is what keeps that true under the harness rather
                 * than only in production.
                 */
                $admin->forceFill([
                    'email_verified_at' => $administratorVerified ? now() : null,
                ])->save();

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
     * The organization's own top role — the only built-in one (§4).
     *
     * No permission rows are attached: Role::hasPermission() returns true for
     * Administrator unconditionally. That's what keeps a module added next year
     * automatically visible to the person who owns the organization, instead of
     * invisible until someone remembers to backfill every Administrator role in
     * every tenant.
     *
     * `is_administrator` is set with forceFill because it is deliberately absent
     * from Role::$fillable — this is the ONE place in the application that may
     * write it, and keeping it unfillable is what stops a role-editor payload
     * from making a second one. The name is a default the tenant sees, not an
     * identity: identity is the column, for the reason the 2026_08_10_010000
     * migration sets out at length.
     */
    private function createAdministratorRole(Organization $organization): Role
    {
        $role = new Role([
            'organization_id' => $organization->id,
            'name'            => Role::ADMINISTRATOR,
            'is_system'       => true,
        ]);

        $role->forceFill(['is_administrator' => true])->save();

        return $role;
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
