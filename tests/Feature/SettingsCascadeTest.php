<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Setting;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Settings\SettingsResolver;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The settings cascade (plan §6).
 *
 * The test that matters most is the Sub Company one. My first draft of the plan
 * wrote the chain as Franchise → Company → Organization, which silently skips
 * Sub Companies — a franchise under Serecor would have inherited Serenisima's
 * settings and quietly ignored everything Serecor defined. Nothing errors; the
 * value is just wrong, which is the worst failure mode a settings system has.
 */
class SettingsCascadeTest extends TestCase
{
    use RefreshDatabase;

    private SettingsResolver $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = app(SettingsResolver::class);
    }

    /** The plan's own example: Milk United → Serenisima → Serecor → franchises. */
    private function tree(): array
    {
        $provisioned = app(ProvisionOrganization::class)(
            organizationName: 'Milk United Group',
            administrator: ['name' => 'Admin', 'email' => 'admin@milk.test', 'password' => 'pw-here'],
            companyName:   'Serenisima',
            franchiseName: 'New Jersey',
        );

        $org = $provisioned['organization'];

        return app(TenantContext::class)->run($org, function () use ($org, $provisioned) {
            $serecor = Company::create([
                'organization_id'   => $org->id,
                'parent_company_id' => $provisioned['company']->id,
                'name'              => 'Serecor',
            ]);

            return [
                'organization'   => $org,
                'serenisima'     => $provisioned['company'],
                'serecor'        => $serecor,
                'newJersey'      => $provisioned['franchise'],
                'serecorTexas'   => Franchise::create([
                    'organization_id' => $org->id,
                    'company_id'      => $serecor->id,
                    'name'            => 'Texas',
                ]),
            ];
        });
    }

    // ── inheritance ──────────────────────────────────────────────

    public function test_falls_back_to_the_definition_default(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->assertSame('en', $this->settings->get('locale', $t['newJersey']));
            $this->assertNull($this->settings->sourceOf('locale', $t['newJersey']));
            $this->assertSame(0, Setting::count(), 'Nothing set means no rows — the table is sparse.');
        });
    }

    public function test_a_franchise_inherits_from_its_organization(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('locale', $t['organization'], 'es');

            $this->assertSame('es', $this->settings->get('locale', $t['newJersey']));
            $this->assertTrue($this->settings->sourceOf('locale', $t['newJersey'])->is($t['organization']));
        });
    }

    public function test_the_nearest_scope_wins(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('locale', $t['organization'], 'es');
            $this->settings->set('locale', $t['serenisima'], 'en');
            $this->settings->set('locale', $t['newJersey'], 'es');

            $this->assertSame('es', $this->settings->get('locale', $t['newJersey']));
            $this->assertTrue($this->settings->sourceOf('locale', $t['newJersey'])->is($t['newJersey']));
        });
    }

    // ── THE Sub Company step ─────────────────────────────────────

    public function test_a_franchise_under_a_sub_company_inherits_from_that_sub_company(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('locale', $t['organization'], 'en');
            $this->settings->set('locale', $t['serecor'], 'es');

            // Texas sits under Serecor. Skip the Sub Company and this returns
            // 'en' from the organization — wrong, and silently so.
            $this->assertSame('es', $this->settings->get('locale', $t['serecorTexas']));
            $this->assertTrue($this->settings->sourceOf('locale', $t['serecorTexas'])->is($t['serecor']));
        });
    }

    public function test_a_sub_company_franchise_falls_through_to_the_parent_company(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('date_format', $t['organization'], 'YYYY-MM-DD');
            $this->settings->set('date_format', $t['serenisima'], 'D/M/YYYY');
            // Serecor sets nothing — it should inherit from Serenisima, its parent,
            // NOT jump straight to the organization.

            $this->assertSame('D/M/YYYY', $this->settings->get('date_format', $t['serecorTexas']));
            $this->assertTrue($this->settings->sourceOf('date_format', $t['serecorTexas'])->is($t['serenisima']));
        });
    }

    public function test_a_sub_company_inherits_from_its_parent(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('week_starts_on', $t['serenisima'], 'monday');

            $this->assertSame('monday', $this->settings->get('week_starts_on', $t['serecor']));
        });
    }

    public function test_sibling_branches_do_not_affect_each_other(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('locale', $t['serecor'], 'es');

            // New Jersey hangs off Serenisima, not Serecor.
            $this->assertSame('en', $this->settings->get('locale', $t['newJersey']));
            $this->assertSame('es', $this->settings->get('locale', $t['serecorTexas']));
        });
    }

    // ── clearing an override ─────────────────────────────────────

    public function test_forgetting_an_override_restores_inheritance(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('locale', $t['organization'], 'es');
            $this->settings->set('locale', $t['newJersey'], 'en');
            $this->assertSame('en', $this->settings->get('locale', $t['newJersey']));

            $this->settings->forget('locale', $t['newJersey']);

            // Back to inheriting — and it now TRACKS the parent rather than
            // holding a frozen copy of whatever the parent said at the time.
            $this->assertSame('es', $this->settings->get('locale', $t['newJersey']));

            $this->settings->set('locale', $t['organization'], 'en');
            $this->assertSame('en', $this->settings->get('locale', $t['newJersey']));
        });
    }

    // ── scope restrictions ───────────────────────────────────────

    public function test_a_franchise_cannot_weaken_an_organization_security_policy(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('security.require_two_factor', $t['organization'], true);

            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/cannot be set at franchise level/');

            // The reason allowedScopes exists: a franchise administrator must not
            // be able to switch off a policy the organization imposed.
            $this->settings->set('security.require_two_factor', $t['newJersey'], false);
        });
    }

    public function test_an_organization_only_setting_still_reaches_every_franchise(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('security.require_two_factor', $t['organization'], true);

            $this->assertTrue($this->settings->get('security.require_two_factor', $t['newJersey']));
            $this->assertTrue($this->settings->get('security.require_two_factor', $t['serecorTexas']));
        });
    }

    // ── validation ───────────────────────────────────────────────

    public function test_an_invalid_enum_value_is_rejected_on_write(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/must be one of/');

            // Rejected here, not stored and discovered later on read.
            $this->settings->set('locale', $t['organization'], 'fr');
        });
    }

    public function test_an_unknown_setting_is_rejected(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/Unknown setting/');

            // Definitions are code (§2.1) — you can't invent one at runtime.
            $this->settings->set('made_up_setting', $t['organization'], 'x');
        });
    }

    public function test_values_are_cast_to_their_declared_type(): void
    {
        $t = $this->tree();

        app(TenantContext::class)->run($t['organization'], function () use ($t) {
            $this->settings->set('security.session_timeout_minutes', $t['organization'], '90');

            $this->assertSame(90, $this->settings->get('security.session_timeout_minutes', $t['organization']));
        });
    }

    // ── tenancy ──────────────────────────────────────────────────

    public function test_settings_never_leak_between_organizations(): void
    {
        $a = $this->tree();
        $b = app(ProvisionOrganization::class)(
            organizationName: 'Other Group',
            administrator: ['name' => 'A', 'email' => 'a@other.test', 'password' => 'pw-here'],
            companyName: 'C', franchiseName: 'F',
        );

        app(TenantContext::class)->run($a['organization'],
            fn () => $this->settings->set('locale', $a['organization'], 'es')
        );

        app(TenantContext::class)->run($b['organization'], function () use ($b) {
            $this->settings->flush();
            $this->assertSame('en', $this->settings->get('locale', $b['franchise']));
        });
    }
}
