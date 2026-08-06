<?php

namespace Tests\Feature;

use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The setting catalogue itself (plan §6).
 *
 * Guards the properties that must hold for EVERY setting, so a definition added
 * next year can't quietly break the rules — particularly the security ones,
 * where the scope restriction IS the enforcement.
 */
class SettingRegistryTest extends TestCase
{
    use RefreshDatabase;

    private SettingRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(SettingRegistry::class);
    }

    // ── invariants across the whole catalogue ────────────────────

    public function test_every_security_setting_is_organization_only(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            if (! str_starts_with($key, 'security.')) {
                continue;
            }

            $this->assertSame(
                [SettingDefinition::SCOPE_ORGANIZATION],
                $definition->allowedScopes,
                "Security setting '{$key}' must be organization-only. Allowing a lower "
                .'scope lets a franchise administrator weaken a policy the organization set.'
            );
        }
    }

    public function test_every_default_survives_its_own_cast(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            // A default that fails validation would blow up on first read for
            // any tenant that never overrode it — i.e. almost all of them.
            $this->assertNotNull(
                $definition->cast($definition->default),
                "Default for '{$key}' does not survive cast()."
            );
        }
    }

    public function test_every_enum_default_is_one_of_its_options(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            if ($definition->type !== SettingDefinition::TYPE_ENUM) {
                continue;
            }

            $this->assertContains(
                $definition->default,
                $definition->options,
                "Enum '{$key}' defaults to a value not in its own options list."
            );
        }
    }

    public function test_every_setting_has_a_label(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            $this->assertNotEmpty($definition->label, "Setting '{$key}' has no label.");
        }
    }

    // ── the new types ────────────────────────────────────────────

    public function test_json_settings_round_trip(): void
    {
        ['organization' => $org, 'franchise' => $franchise] = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'A', 'email' => 'a@alpha.test', 'password' => 'pw-here'],
            companyName: 'Co', franchiseName: 'F1',
        );

        app(TenantContext::class)->run($org, function () use ($org, $franchise) {
            $resolver = app(SettingsResolver::class);

            $hours = [
                'monday'  => ['open' => '07:00', 'close' => '19:00'],
                'sunday'  => null,   // closed — distinct from open-for-zero-minutes
            ];

            $resolver->set('ops.business_hours', $franchise, $hours);

            $read = $resolver->get('ops.business_hours', $franchise);
            $this->assertSame('07:00', $read['monday']['open']);
            $this->assertNull($read['sunday']);
        });
    }

    public function test_invalid_json_is_rejected(): void
    {
        $definition = $this->registry->getOrFail('ops.business_hours');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/valid JSON/');

        $definition->cast('{not json');
    }

    public function test_decimal_settings_keep_fractional_precision(): void
    {
        $definition = $this->registry->getOrFail('money.tax_rate');

        // Tax rates are rarely whole numbers — 6.625% is New Jersey's.
        $this->assertSame(6.625, $definition->cast('6.625'));
    }

    public function test_non_numeric_decimal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/expects a number/');

        $this->registry->getOrFail('money.tax_rate')->cast('six percent');
    }

    // ── grouping for the settings UI ─────────────────────────────

    public function test_settings_group_by_prefix(): void
    {
        $groups = $this->registry->grouped();

        foreach (['general', 'ops', 'money', 'numbering', 'security', 'notifications', 'customers'] as $g) {
            $this->assertArrayHasKey($g, $groups, "Expected a '{$g}' settings group.");
        }
    }

    public function test_scope_filtering_hides_organization_only_settings(): void
    {
        $franchiseSettings = $this->registry->forScope(SettingDefinition::SCOPE_FRANCHISE);

        // The settings screen renders this list — a security setting appearing
        // here would be an editable control that then throws on save.
        $this->assertArrayNotHasKey('security.require_two_factor', $franchiseSettings);
        $this->assertArrayHasKey('money.tax_rate', $franchiseSettings);
    }

    // ── money reaches the level that actually varies ─────────────

    public function test_tax_rate_can_differ_per_franchise(): void
    {
        ['organization' => $org, 'company' => $company, 'franchise' => $nj] = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'A', 'email' => 'a2@alpha.test', 'password' => 'pw-here'],
            companyName: 'Co', franchiseName: 'New Jersey',
        );

        app(TenantContext::class)->run($org, function () use ($org, $company, $nj) {
            $resolver = app(SettingsResolver::class);

            $tx = \App\Models\Franchise::create([
                'organization_id' => $org->id, 'company_id' => $company->id, 'name' => 'Texas',
            ]);

            $resolver->set('money.tax_rate', $org, 0.0);
            $resolver->set('money.tax_rate', $nj, 6.625);
            $resolver->set('money.tax_rate', $tx, 8.25);

            // Jurisdictional — one organization-wide rate would be wrong for
            // everyone except whichever state it was copied from.
            $this->assertSame(6.625, $resolver->get('money.tax_rate', $nj));
            $this->assertSame(8.25, $resolver->get('money.tax_rate', $tx));
        });
    }
}
