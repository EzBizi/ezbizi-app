<?php

namespace Tests\Concerns;

use App\Models\Assignment;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/**
 * A tenant, and — the part that was missing — somebody in it who is NOT an
 * administrator.
 *
 * ── Why this exists ──────────────────────────────────────────────
 *
 * A review of the authorization layer found that 32 of the application's 33
 * `authorize()` calls could be deleted with the suite still green. Not because
 * the tests were careless: until roles could be created there was no cheap way
 * to build a user who lacks a permission, so almost every test signed in as the
 * organization's Administrator — for whom `hasPermission()` returns true
 * unconditionally, so the check it passes through proves nothing.
 *
 * Three lines here now produce a real, restricted, signed-in user:
 *
 *     $alpha  = $this->provisionTenant('Alpha');
 *     $viewer = $this->userWithRole($alpha, 'Viewer', ['customers.view']);
 *     $this->signedInAs($viewer)->postJson(...)->assertForbidden();
 *
 * Permissions are attached through `Role::syncPermissionKeys()` rather than
 * being poked into the pivot table, so a fixture cannot build a role in a state
 * the application itself refuses to store — a test that starts from an
 * impossible state proves something about a system that does not exist.
 */
trait BuildsTenants
{
    /**
     * A complete organization: administrator, company, franchise, assignment.
     *
     * @return array{org: Organization, admin: User, company: \App\Models\Company, franchise: Franchise}
     */
    /**
     * The tenant as A1-Group, the only organization the RingCentral env
     * credential belongs to (Jose, 2026-10-15): matched by slug.
     */
    protected function asRingCentralEnvOwner(array $tenant): array
    {
        $tenant['org']->forceFill(['slug' => \App\Support\Reports\Experimental\RingCentralCallLog::ENV_ORGANIZATION_SLUG])->save();

        return $tenant;
    }

    protected function provisionTenant(string $name = 'Alpha', ?string $email = null): array
    {
        $email ??= strtolower(preg_replace('/[^a-z0-9]/i', '', $name)).'@example.test';

        $result = app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: ['name' => "{$name} Admin", 'email' => $email, 'password' => 'a-real-password'],
            companyName:   "{$name} Co",
            franchiseName: "{$name} F1",
        );

        return [
            'org'       => $result['organization'],
            'admin'     => $result['administrator'],
            'company'   => $result['company'],
            'franchise' => $result['franchise'],
        ];
    }

    /**
     * A user holding exactly these permissions, and nothing else.
     *
     * Assigned at ORGANIZATION level by default so that reach — the other half
     * of §4 — is never the accidental reason a test fails. When a test is about
     * reach it passes a franchise instead, and then the two gates can be told
     * apart.
     *
     * @param  array{org: Organization, franchise: Franchise}  $tenant
     * @param  string[]  $permissionKeys
     */
    protected function userWithRole(
        array $tenant,
        string $roleName,
        array $permissionKeys = [],
        ?string $email = null,
        ?Franchise $onlyAt = null,
    ): User {
        $email ??= strtolower(preg_replace('/[^a-z0-9]/i', '', $roleName))
            .'@'.$tenant['org']->slug.'.test';

        return $this->inTenant($tenant['org'], function () use ($tenant, $roleName, $permissionKeys, $email, $onlyAt) {
            $role = Role::create([
                'organization_id' => $tenant['org']->id,
                'name'            => $roleName,
                'is_system'       => false,
            ]);

            $role->syncPermissionKeys($permissionKeys);

            $user = User::create([
                'organization_id' => $tenant['org']->id,
                'role_id'         => $role->id,
                'name'            => $roleName,
                'email'           => $email,
                'password'        => 'a-real-password',
                'is_active'       => true,
            ]);

            /*
             * Fixture people are ESTABLISHED staff, so they arrive verified
             * (TODO #22). In the product these accounts came in by invitation,
             * and redeeming an invitation link is itself proof of the mailbox —
             * InvitationAcceptanceController marks them exactly this way.
             *
             * Without this every test that builds a tenant and then writes
             * anything would meet RequireVerifiedEmail and get a 403, which
             * would say far more about the fixture than about the feature under
             * test. A test that specifically wants an unconfirmed account should
             * sign one up through /auth/signup, which is the only route that
             * produces one.
             *
             * forceFill because `email_verified_at` is deliberately not in the
             * model's #[Fillable] list — a verification state must never be mass
             * assignable — so passing it to create() above is silently dropped.
             */
            $user->forceFill(['email_verified_at' => now()])->save();

            Assignment::create([
                'organization_id' => $tenant['org']->id,
                'user_id'         => $user->id,
                ...($onlyAt === null
                    ? ['assigned_organization_id' => $tenant['org']->id]
                    : ['assigned_franchise_id' => $onlyAt->getKey()]),
            ]);

            return $user;
        });
    }

    /**
     * Sign in, and return $this so the call reads as one sentence.
     *
     * Sanctum::actingAs rather than a real login round trip: these tests are
     * about what a role may do, and AuthApiTest already proves a token survives
     * the wire. `fresh()` because a user built moments ago carries no loaded
     * `role` relation, and the permission check would resolve it against
     * whatever tenant happens to be in context at the time.
     */
    protected function signedInAs(User $user): static
    {
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->inTenant($user->organization, fn () => $user->fresh()));

        return $this;
    }

    /**
     * A SECOND franchise under the same company.
     *
     * For the tests that are about one franchise not seeing another's work —
     * the sideways case, which a single-franchise tenant cannot express at all.
     * Strictly additive: it does not touch what `provisionTenant` returns, and
     * every module's tests read that array.
     */
    protected function extraFranchise(array $tenant, string $name): Franchise
    {
        return $this->inTenant($tenant['org'], fn () => Franchise::create([
            'company_id' => $tenant['company']->id,
            'name'       => $name,
        ]));
    }

    /** Roles and permissions are tenant data, so they cannot be read with no tenant set. */
    protected function inTenant(Organization $organization, callable $fn): mixed
    {
        return app(TenantContext::class)->run($organization, $fn);
    }
}
