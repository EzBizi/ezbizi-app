<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PlatformModuleFlag;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Modules\ModuleAvailability;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Platform release flags (plan §2.1, layer 3) — EzBizi's own rollout control,
 * sitting above every organization's permissions.
 *
 * The behaviour that matters most: `disabled` beats Administrator. If it didn't,
 * a kill switch would leave the exact users most likely to be hammering a broken
 * module still able to hammer it.
 */
class ModuleAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private ModuleAvailability $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = app(ModuleAvailability::class);
    }

    private function provision(string $name): array
    {
        return app(ProvisionOrganization::class)(
            organizationName: $name,
            administrator: [
                'name' => 'Admin',
                'email' => strtolower(str_replace(' ', '', $name)).'@example.com',
                'password' => 'a-real-password',
            ],
            companyName: 'Co',
            franchiseName: 'F1',
        );
    }

    /**
     * What this user may do, asked from inside their own tenant.
     *
     * hasPermission() consults their Role, and roles are tenant-owned — so the
     * question cannot be asked with no tenant in context. A request has one by
     * the time a permission is checked; a test has to establish it, the same way
     * ViewScopeTest does for reach.
     *
     * These assertions used to run outside any tenant and passed only because
     * the read scope failed open and matched every organization's roles.
     */
    private function may(User $user, string $permission): bool
    {
        return app(TenantContext::class)->run(
            $user->organization,
            fn () => $user->fresh()->hasPermission($permission),
        );
    }

    // ── defaults ─────────────────────────────────────────────────

    public function test_a_module_with_no_flag_is_available(): void
    {
        // Fail-OPEN here, deliberately: the module exists in code, so no flag
        // means nobody has restricted it — not that it's forbidden. Failing
        // closed would make every new module invisible until someone remembered
        // to insert a row.
        $this->assertTrue($this->availability->isAvailable('companies'));
        $this->assertSame([], PlatformModuleFlag::all()->all());
    }

    public function test_a_module_not_in_the_registry_is_never_available(): void
    {
        // No code behind it, so there is nothing to switch on.
        $this->assertFalse($this->availability->isAvailable('imaginary_module'));
    }

    // ── the kill switch ──────────────────────────────────────────

    public function test_disabling_a_module_denies_everyone_including_administrators(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->provision('Alpha');

        // Administrator normally holds everything, unconditionally.
        $this->assertTrue($this->may($admin, 'companies.edit'));

        $this->availability->setState(
            'companies',
            PlatformModuleFlag::DISABLED,
            reason: 'Data corruption bug — investigating',
            changedBy: 'staff:lead-dev',
        );

        // A kill switch that exempts admins isn't a kill switch.
        $this->assertFalse($this->may($admin, 'companies.edit'));
        $this->assertFalse($this->availability->isAvailable('companies', $org));
    }

    public function test_disabling_one_module_does_not_affect_others(): void
    {
        ['administrator' => $admin] = $this->provision('Alpha');

        $this->availability->setState(
            'companies', PlatformModuleFlag::DISABLED, reason: 'broken'
        );

        $this->assertFalse($this->may($admin, 'companies.edit'));
        $this->assertTrue($this->may($admin, 'franchises.edit'));
    }

    public function test_re_enabling_restores_access(): void
    {
        ['administrator' => $admin] = $this->provision('Alpha');

        $this->availability->setState('companies', PlatformModuleFlag::DISABLED, reason: 'x');
        $this->assertFalse($this->may($admin, 'companies.edit'));

        $this->availability->setState('companies', PlatformModuleFlag::ENABLED);
        $this->availability->flushCache();

        $this->assertTrue($this->may($admin, 'companies.edit'));
    }

    // ── beta rollout ─────────────────────────────────────────────

    public function test_beta_reaches_only_the_listed_organizations(): void
    {
        ['organization' => $alpha, 'administrator' => $alphaAdmin] = $this->provision('Alpha');
        ['organization' => $bravo, 'administrator' => $bravoAdmin] = $this->provision('Bravo');

        $this->availability->setState(
            'companies',
            PlatformModuleFlag::BETA,
            reason: 'Piloting with Alpha before general release',
            betaOrganizationIds: [$alpha->id],
        );

        $this->assertTrue($this->availability->isAvailable('companies', $alpha));
        $this->assertFalse($this->availability->isAvailable('companies', $bravo));

        $this->assertTrue($this->may($alphaAdmin, 'companies.edit'));
        $this->assertFalse($this->may($bravoAdmin, 'companies.edit'));
    }

    public function test_beta_with_no_organizations_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one organization/');

        // Otherwise it's just a disable with a misleading name.
        $this->availability->setState(
            'companies', PlatformModuleFlag::BETA, reason: 'pilot', betaOrganizationIds: []
        );
    }

    // ── guard rails ──────────────────────────────────────────────

    public function test_restricting_a_module_requires_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/reason is required/');

        // Six months on, an unexplained disabled module is indistinguishable
        // from a mistake — and whoever finds it is usually mid-incident.
        $this->availability->setState('companies', PlatformModuleFlag::DISABLED);
    }

    public function test_enabling_needs_no_reason(): void
    {
        $flag = $this->availability->setState('companies', PlatformModuleFlag::ENABLED);

        $this->assertSame(PlatformModuleFlag::ENABLED, $flag->state);
        $this->assertNull($flag->reason);
    }

    public function test_flagging_an_unknown_module_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown module/');

        // Flags reference the registry, which lives in code — a flag for a
        // module that doesn't exist would sit there forever doing nothing.
        $this->availability->setState('nope', PlatformModuleFlag::DISABLED, reason: 'x');
    }

    public function test_an_unknown_state_fails_closed(): void
    {
        $flag = new PlatformModuleFlag(['module_key' => 'companies', 'state' => 'garbage']);

        // Corrupt data must deny, never grant.
        $this->assertFalse($flag->availableTo(null));
    }

    // ── the flag is platform-wide, not tenant data ───────────────

    public function test_flags_are_not_tenant_scoped(): void
    {
        ['organization' => $alpha] = $this->provision('Alpha');
        ['organization' => $bravo] = $this->provision('Bravo');

        $this->availability->setState('companies', PlatformModuleFlag::DISABLED, reason: 'x');

        // One row governs everyone — during an incident you switch it off once,
        // not once per customer.
        $this->assertSame(1, PlatformModuleFlag::count());
        $this->assertFalse($this->availability->isAvailable('companies', $alpha));
        $this->assertFalse($this->availability->isAvailable('companies', $bravo));

        // And it is invisible to tenant scoping — no organization_id on it at all.
        $this->assertFalse(
            in_array('organization_id', app(PlatformModuleFlag::class)->getFillable(), strict: true)
        );
    }
}
