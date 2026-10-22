<?php

namespace Tests\Feature;

use DateTimeImmutable;
use DateTimeZone;
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

    public function test_every_work_hours_setting_has_a_section(): void
    {
        $seen = [];
        foreach ($this->registry->all() as $key => $definition) {
            if ($this->registry->groupOf($key)['key'] !== 'work_hours') {
                continue;
            }
            $this->assertNotNull($definition->section, "{$key} has no section on Work Hours › Settings.");
            $seen[] = $definition->section;
        }

        $this->assertSame(
            ['Clock in and lock screen', 'Shift timing', 'Daily approval', 'Clock-in zone', 'Paid time off'],
            array_values(array_unique($seen)),
        );
        $this->assertSame('Clock-in zone', $this->registry->get('time_clock.geofence_radius_m')->section);
    }

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
            //
            // A company setting's default IS null — "none chosen" — so for it
            // surviving means cast() does not throw (it would above).
            if ($definition->type === SettingDefinition::TYPE_COMPANY) {
                $this->assertNull($definition->cast($definition->default), "Default for '{$key}' must be no company.");

                continue;
            }

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

    /**
     * A timezone that observes daylight saving can be CHOSEN.
     *
     * ── What this test used to assert, and why it changed ────────
     *
     * It pinned the opposite: `assertNotContains('America/New_York')` and
     * `assertCount(27)` — twenty-seven fixed UTC offsets and not one real
     * place, deliberately, with no docblock saying why.
     *
     * Marketing measured what that cost on 2026-09-08. `Etc/GMT+5` is UTC-5 in
     * January and UTC-5 in July, while New York is UTC-4 in July, so a
     * newsletter set for 09:00 went out at 10:00 local from March to November.
     * The same value reaches Clock In, Companies, Franchises, dashboard periods
     * and Phone reports, where it moves which DAY a near-midnight call is in.
     *
     * The important part is that this was not a badly chosen default. There was
     * no non-drifting value to choose, so "set your timezone properly" was
     * advice nobody could take.
     *
     * ── The default moved too, on 2026-09-09 ─────────────────────
     *
     * Making a place CHOOSABLE shipped first; the default followed the same
     * day, once somebody was awake to review the expectations it moves.
     *
     * It was reported as a five-module job across seventeen test files. That
     * count was the number of files MENTIONING `Etc/GMT+5`, and most of them
     * set their own timezone explicitly and never read the default at all.
     * The default change alone failed SEVEN tests, in five files. The larger
     * number came from measuring a combined change - default plus relabelling
     * the offsets - and attributing all of it to the default.
     *
     * Six of the seven wanted their expected value re-derived. One wanted its
     * INSTANT moved: TimeClockDayListTest froze 04:00 UTC to sit on the
     * previous local day, which was true at a fixed UTC−5 and is exactly
     * midnight in EDT. Swapping its expected date would have left a green test
     * asserting the opposite of its purpose. That is the real cost of moving a
     * default, and it is not visible in a count of files.
     *
     * The offset labels are still bare `UTC-5`. TimeClock asserts that text
     * and nothing about it is wrong.
     */
    public function test_timezone_offers_places_as_well_as_offsets(): void
    {
        $timezone = $this->registry->getOrFail('timezone');

        $this->assertSame(SettingDefinition::TYPE_ENUM, $timezone->type);

        // Moved 2026-09-09. Jose: "whatever you recommend, our company is
        // physically at Miami Florida."
        $this->assertSame('America/New_York', $timezone->default);
        $this->assertSame('UTC-5', $timezone->labelFor('Etc/GMT+5'));
        $this->assertSame('UTC', $timezone->labelFor('UTC'));
        $this->assertContains('Etc/GMT-3', $timezone->options);

        // The new part.
        $this->assertContains('America/New_York', $timezone->options);
        $this->assertSame('Eastern — New York', $timezone->labelFor('America/New_York'));
        $this->assertGreaterThan(27, count($timezone->options));

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

    /**
     * At least one option really does move with the seasons.
     *
     * Asserted by BEHAVIOUR, not by the shape of the id. `America/New_York`
     * could be present and still be a fixed offset wearing a place name, which
     * a string check would happily accept — and the whole defect was a value
     * that looks like a timezone and does not act like one.
     *
     * Asserted as a CLASS rather than naming one zone, for the same reason the
     * Phone hours pane's test is written that way: a list that satisfies
     * "contains America/New_York" is still wrong for somebody in Denver.
     */
    public function test_a_timezone_that_observes_daylight_saving_can_be_chosen(): void
    {
        $timezone = $this->registry->getOrFail('timezone');

        $moving = [];
        foreach ($timezone->options as $id) {
            $zone = new DateTimeZone($id);
            $january = new DateTimeImmutable('2026-01-15 12:00', $zone);
            $july = new DateTimeImmutable('2026-07-15 12:00', $zone);

            if ($january->getOffset() !== $july->getOffset()) {
                $moving[] = $id;
            }
        }

        $this->assertGreaterThan(
            5,
            count($moving),
            'no option changes offset between January and July, so every '
            .'choice drifts an hour from the businesses using it for half the '
            .'year and there is nothing anybody can pick instead',
        );

        // And the fixed offsets are still available for the case that wants
        // them — a clock that should ignore daylight saving is a real need.
        $this->assertContains('Etc/GMT+5', $timezone->options);
    }

}
