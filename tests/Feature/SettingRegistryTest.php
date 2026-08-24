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

    public function test_timezone_is_a_utc_offset_selector(): void
    {
        $timezone = $this->registry->getOrFail('timezone');

        $this->assertSame(SettingDefinition::TYPE_ENUM, $timezone->type);
        $this->assertSame('Etc/GMT+5', $timezone->default);
        $this->assertContains('UTC', $timezone->options);
        $this->assertContains('Etc/GMT+5', $timezone->options);
        $this->assertContains('Etc/GMT-3', $timezone->options);
        $this->assertNotContains('America/New_York', $timezone->options);
        $this->assertCount(27, $timezone->options);
        $this->assertSame('UTC', $timezone->labelFor('UTC'));
        $this->assertSame('UTC-5', $timezone->labelFor('Etc/GMT+5'));
        $this->assertSame('UTC+3', $timezone->labelFor('Etc/GMT-3'));

        foreach ($timezone->options as $id) {
            $this->assertInstanceOf(\DateTimeZone::class, new \DateTimeZone($id), $id);
        }

        // POSIX inversion: Etc/GMT+5 is UTC-5, which Laravel's timezone rule
        // and Carbon both accept for the franchise column.
        $winter = new \DateTime('2026-01-15', new \DateTimeZone('Etc/GMT+5'));
        $this->assertSame(-5 * 3600, $winter->getOffset());
    }

    public function test_inactivity_is_a_duration_stored_as_minutes(): void
    {
        $timeout = $this->registry->getOrFail('security.session_timeout_minutes');

        $this->assertSame(SettingDefinition::TYPE_DURATION, $timeout->type);
        $this->assertSame(480, $timeout->default);
        $this->assertSame(480, $timeout->cast(480));
    }

    public function test_every_setting_has_a_label(): void
    {
        foreach ($this->registry->all() as $key => $definition) {
            $this->assertNotEmpty($definition->label, "Setting '{$key}' has no label.");
        }
    }

    /**
     * A setting whose form lives on another screen says WHICH screen.
     *
     * `editedOn` exists so the Settings screen does not draw a box of raw JSON
     * for `organization.structure_canvas` — five hundred `{kind,id,x,y}`
     * objects to hand-edit, for a picture that has its own screen and a button
     * that rebuilds it correctly in one press.
     *
     * The name is what makes that a redirection rather than a hiding place, and
     * it is the same argument `notYetInEffect` makes: a flag says "not here"
     * and leaves the next question — "then where?" — unanswered. So a blank one
     * is refused at construction, and this asserts both halves: the guard bites,
     * and no definition in the catalogue has slipped past it.
     */
    public function test_a_setting_edited_on_another_screen_names_the_screen(): void
    {
        $named = 0;

        foreach ($this->registry->all() as $key => $definition) {
            if ($definition->isEditedOnSettingsScreen()) {
                $this->assertNull(
                    $definition->editedOn,
                    "Setting '{$key}' disagrees with itself about where it is edited.",
                );

                continue;
            }

            $named++;

            $this->assertNotSame(
                '', trim((string) $definition->editedOn),
                "Setting '{$key}' says it is edited elsewhere and names nowhere.",
            );
        }

        // The control: at least one setting actually uses this, so the loop
        // above is not passing by never entering its second branch.
        $this->assertSame(1, $named, 'Expected exactly the structure canvas to be edited elsewhere.');

        // And the guard refuses a definition that names nowhere, rather than
        // leaving the check to a test nobody runs before deploying.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not name one/');

        new SettingDefinition(
            key: 'example.nowhere', label: 'Nowhere',
            type: SettingDefinition::TYPE_STRING, default: '',
            editedOn: '   ',
        );
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

            // A whole week, because that is what the `hours` type requires. It
            // used to be two days: valid then, when the type was `json` and
            // validated nothing, and refused now — a client that drops a day by
            // accident would otherwise quietly shut the business on that day.
            $hours = [
                'monday'    => ['open' => '07:00', 'close' => '19:00'],
                'tuesday'   => ['open' => '07:00', 'close' => '19:00'],
                'wednesday' => ['open' => '07:00', 'close' => '19:00'],
                'thursday'  => ['open' => '07:00', 'close' => '19:00'],
                'friday'    => ['open' => '07:00', 'close' => '19:00'],
                'saturday'  => ['open' => '09:00', 'close' => '13:00'],
                'sunday'    => null,   // closed — distinct from open-for-zero-minutes
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

    /**
     * Grouping is declared, not derived from the key.
     *
     * It used to split on the dot, which gave `general`, `ops` and `jobs` —
     * identifiers, not names, and the settings screen printed them upper-cased
     * because there was nothing better to print. The group a setting belongs to
     * and the words for it are now stated where the definitions are.
     */
    public function test_every_setting_declares_a_named_group(): void
    {
        $groups = $this->registry->grouped();

        foreach ([
            'localisation', 'operations', 'money', 'numbering',
            'security', 'notifications', 'customers', 'jobs',
        ] as $g) {
            $this->assertArrayHasKey($g, $groups, "Expected a '{$g}' settings group.");
        }

        $this->assertArrayNotHasKey('other', $groups, 'A registered setting named no group.');

        // The label is the part a client cannot work out for itself.
        $this->assertSame(
            ['key' => 'numbering', 'label' => 'Document numbering'],
            $this->registry->groupOf('numbering.pad_to_digits'),
        );

        // Declared in its own section rather than inheriting Customers', which
        // is where the constructor call used to sit.
        $this->assertSame('jobs', $this->registry->groupOf('jobs.statuses')['key']);
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
