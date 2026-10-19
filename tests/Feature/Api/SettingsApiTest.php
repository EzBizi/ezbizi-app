<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Role;
use App\Models\User;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Settings\SettingDefinition;
use App\Support\Settings\SettingRegistry;
use App\Support\Settings\SettingsResolver;
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
        $this->assertSame(
            ['value' => 'en', 'label' => 'English'],
            collect($locale['options'])->firstWhere('value', 'en'),
        );

        $timezone = collect($response->json('data'))->firstWhere('key', 'timezone');
        $this->assertSame('enum', $timezone['type']);
        $this->assertContains(
            'UTC',
            array_column($timezone['options'], 'value'),
        );
        $this->assertContains(
            'Etc/GMT+5',
            array_column($timezone['options'], 'value'),
        );
        // Places are offered now, and this used to assert they were not.
        //
        // The old line was `assertNotContains('America/New_York')`. It pinned a
        // list of 27 fixed offsets and nothing else, which meant no caller
        // could choose a timezone that observes daylight saving — so every
        // clock built on this setting ran an hour out from March to November.
        // Changed on 2026-09-09 with Jose's word, and the DEFAULT followed
        // the same day once someone was awake to review the seven expectations
        // it actually moves - fewer than the seventeen files first counted,
        // because most of those set their own timezone and never read the
        // default. The reasoning is on
        // SettingRegistryTest::test_timezone_offers_places_as_well_as_offsets.
        $this->assertContains(
            'America/New_York',
            array_column($timezone['options'], 'value'),
        );

        // Labelled as fixed, so the offsets read as the exception they are.
        $this->assertSame(
            ['value' => 'Etc/GMT+5', 'label' => 'UTC-5'],
            collect($timezone['options'])->firstWhere('value', 'Etc/GMT+5'),
        );
        $this->assertSame(
            ['value' => 'America/New_York', 'label' => 'Eastern — New York'],
            collect($timezone['options'])->firstWhere('value', 'America/New_York'),
        );
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

    public function test_a_subset_offers_the_organizations_own_list_as_its_choices(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        // A label unlike its value, and no default status at all: a client
        // reading the registry's default, or printing values, cannot pass.
        $this->patchJson("/api/v1/settings/jobs.statuses?scope_type=organization&scope_id={$org->id}", ['value' => [
            ['value' => 'done', 'label' => 'Done and paid'],
            ['value' => 'quoted', 'label' => 'Quoted'],
        ]])->assertOk();

        $earned = collect($this->getJson("/api/v1/settings?scope_type=organization&scope_id={$org->id}")->json('data'))
            ->firstWhere('key', 'jobs.earned_statuses');

        $this->assertSame('subset', $earned['type']);
        $this->assertSame([
            ['value' => 'done', 'label' => 'Done and paid'],
            ['value' => 'quoted', 'label' => 'Quoted'],
        ], $earned['options']);
    }

    public function test_a_read_permission_hides_the_row_and_refuses_the_write(): void
    {
        $registry = new SettingRegistry();
        $registry->register(new SettingDefinition(
            key: 'test.secret_routing', label: 'Routing', type: SettingDefinition::TYPE_STRING,
            default: '', allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
            readPermission: 'leads.manage',
        ));
        $registry->register(new SettingDefinition(
            key: 'test.open', label: 'Open', type: SettingDefinition::TYPE_STRING,
            default: '', allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
        ));
        $this->app->instance(SettingRegistry::class, $registry);
        $this->app->forgetInstance(SettingsResolver::class);

        ['organization' => $org] = $this->org('Alpha', 'a@a.test');

        $person = fn (string $name, array $keys) => app(TenantContext::class)->run($org, function () use ($org, $name, $keys) {
            $role = Role::create(['organization_id' => $org->id, 'name' => $name]);
            $role->syncPermissionKeys($keys);

            return User::create([
                'organization_id' => $org->id, 'role_id' => $role->id,
                'name' => $name, 'email' => strtolower($name).'@a.test', 'password' => 'pw-here-please',
            ])->fresh();
        });

        $keys = function (User $user) use ($org) {
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);

            return collect($this->getJson("/api/v1/settings?scope_type=organization&scope_id={$org->id}")
                ->assertOk()->json('data'))->pluck('key');
        };

        $plain   = $person('Plain', ['organizations.edit']);
        $manager = $person('Manager', ['organizations.edit', 'leads.manage']);

        $this->assertTrue($keys($plain)->contains('test.open'), 'control: an ungated row is still listed');
        $this->assertFalse($keys($plain)->contains('test.secret_routing'));
        $this->assertTrue($keys($manager)->contains('test.secret_routing'));

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($plain);
        $this->patchJson("/api/v1/settings/test.secret_routing?scope_type=organization&scope_id={$org->id}", ['value' => 'x'])
            ->assertForbidden();
    }

    public function test_a_place_map_offers_this_organizations_places_and_takes_codes_only(): void
    {
        $registry = new SettingRegistry();
        $registry->register(new SettingDefinition(
            key: 'test.places', label: 'Test places', type: SettingDefinition::TYPE_FRANCHISE_MAP,
            default: [], allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
        ));
        $this->app->instance(SettingRegistry::class, $registry);
        $this->app->forgetInstance(SettingsResolver::class);

        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        $this->org('Beta', 'b@b.test');
        Sanctum::actingAs($admin);

        $row = collect($this->getJson("/api/v1/settings?scope_type=organization&scope_id={$org->id}")->json('data'))
            ->firstWhere('key', 'test.places');
        $this->assertSame([['value' => $f->id, 'label' => $f->name]], $row['options']);

        $url = "/api/v1/settings/test.places?scope_type=organization&scope_id={$org->id}";
        $this->patchJson($url, ['value' => [' NJ ' => $f->id]])->assertOk()->assertJsonPath('data.value', ['NJ' => $f->id]);
        $this->patchJson($url, ['value' => [$f->id]])->assertStatus(422);
        $this->patchJson($url, ['value' => ['NJ' => '']])->assertStatus(422);
    }

    public function test_a_company_setting_offers_this_organizations_companies(): void
    {
        $registry = new SettingRegistry();
        $registry->register(new SettingDefinition(
            key: 'test.company', label: 'Test company', type: SettingDefinition::TYPE_COMPANY,
            default: null, allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
        ));
        $this->app->instance(SettingRegistry::class, $registry);
        $this->app->forgetInstance(SettingsResolver::class);

        ['organization' => $org, 'administrator' => $admin, 'company' => $company] = $this->org('Alpha', 'a@a.test');
        ['company' => $other] = $this->org('Beta', 'b@b.test');
        Sanctum::actingAs($admin);

        $row = collect($this->getJson("/api/v1/settings?scope_type=organization&scope_id={$org->id}")->json('data'))
            ->firstWhere('key', 'test.company');

        $this->assertSame([['value' => $company->id, 'label' => $company->name]], $row['options'], "Beta's company must not be offered.");
        $this->assertNotSame($other->id, $company->id);

        $this->patchJson("/api/v1/settings/test.company?scope_type=organization&scope_id={$org->id}", ['value' => $company->id])
            ->assertOk()->assertJsonPath('data.value', $company->id);
    }

    public function test_a_subset_stores_each_choice_once_and_refuses_anything_but_text(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $url = "/api/v1/settings/jobs.earned_statuses?scope_type=organization&scope_id={$org->id}";

        $this->patchJson($url, ['value' => ['completed', 'completed']])
            ->assertOk()
            ->assertJsonPath('data.value', ['completed']);

        // Empty is an answer — "nothing is earned yet" — not an error.
        $this->patchJson($url, ['value' => []])->assertOk()->assertJsonPath('data.value', []);

        $this->patchJson($url, ['value' => [['nested']]])->assertStatus(422);
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

    // ── a write answers with the row it wrote ────────────────────
    //
    // update() used to return a thin {key, value, scope}, which left a client
    // nothing it could splice — so the settings screen refetched everything
    // after every change and blanked itself to a spinner in the process. These
    // pin the contract that removed the refetch: all three endpoints answer in
    // the shape index() does, built by one method so they cannot drift apart.

    public function test_an_update_answers_in_the_same_shape_as_a_read(): void
    {
        ['administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $read = collect($this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}")->json('data'))
            ->firstWhere('key', 'money.tax_rate');

        $written = $this->patchJson(
            "/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f->id}",
            ['value' => 6.625]
        )->assertOk()->json('data');

        // Same keys, same order. Anything a client can read off a listed row it
        // can read off a written one, which is the whole point.
        $this->assertSame(array_keys($read), array_keys($written));
        $this->assertSame(6.625, $written['value']);
        $this->assertSame('Default tax rate (%)', $written['label']);
    }

    public function test_a_reset_answers_in_the_same_shape_as_a_read(): void
    {
        ['administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f->id}", ['value' => 6.625])
            ->assertOk();

        $read = collect($this->getJson("/api/v1/settings?scope_type=franchise&scope_id={$f->id}")->json('data'))
            ->firstWhere('key', 'money.tax_rate');

        $reset = $this->deleteJson("/api/v1/settings/money.tax_rate?scope_type=franchise&scope_id={$f->id}")
            ->assertOk()->json('data');

        $this->assertSame(array_keys($read), array_keys($reset));
    }

    public function test_the_source_flips_to_here_on_a_write_and_to_the_ancestor_on_a_reset(): void
    {
        ['organization' => $org, 'administrator' => $admin, 'franchise' => $f] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", ['value' => 'es'])
            ->assertOk();

        $written = $this->patchJson(
            "/api/v1/settings/locale?scope_type=franchise&scope_id={$f->id}",
            ['value' => 'en']
        )->assertOk()->json('data');

        // It was inherited from the organization a moment ago and is set here
        // now. The row has to say so, or the screen goes on offering to reset an
        // override it does not know exists.
        $this->assertSame('en', $written['value']);
        $this->assertSame('franchise', $written['source']['type']);
        $this->assertSame($f->id, $written['source']['id']);
        $this->assertFalse($written['source']['inherited']);

        $reset = $this->deleteJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$f->id}")
            ->assertOk()->json('data');

        // And back the other way, to an ancestor the client had no way to pick
        // for itself — which is precisely why the server sends it.
        $this->assertSame('es', $reset['value']);
        $this->assertSame('organization', $reset['source']['type']);
        $this->assertSame($org->id, $reset['source']['id']);
        $this->assertTrue($reset['source']['inherited']);
    }

    public function test_a_reset_with_nothing_above_it_reports_no_source_at_all(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", ['value' => 'es'])
            ->assertOk();

        $reset = $this->deleteJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}")
            ->assertOk()->json('data');

        // Organization is the top of the cascade, so clearing it falls back to
        // the definition's default rather than to a parent. The old response
        // said `now_inherited: true` unconditionally, which would have the
        // screen claim "Inherited from Alpha" about a value Alpha does not hold.
        $this->assertSame('en', $reset['value']);
        $this->assertNull($reset['source']);
    }

    public function test_a_sensitive_settings_value_stays_null_when_it_is_written(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');
        $this->registerSensitiveSetting();
        Sanctum::actingAs($admin);

        $secret = 'whsec-never-echo-this';

        $response = $this->patchJson(
            "/api/v1/settings/integrations.webhook_secret?scope_type=organization&scope_id={$org->id}",
            ['value' => $secret]
        )->assertOk();

        // The refactor that made writes return a full row must not make the
        // write the one route that hands the secret back.
        $this->assertTrue($response->json('data.is_sensitive'));
        $this->assertNull($response->json('data.value'));
        $response->assertDontSee($secret);

        // Withheld, not discarded — the write really happened.
        app(TenantContext::class)->run($org, function () use ($org, $secret) {
            $this->assertSame(
                $secret,
                app(SettingsResolver::class)->get('integrations.webhook_secret', $org)
            );
        });

        // And a read says the same thing, which is the shape the write copies.
        $listed = collect($this->getJson("/api/v1/settings?scope_type=organization&scope_id={$org->id}")->json('data'))
            ->firstWhere('key', 'integrations.webhook_secret');

        $this->assertNull($listed['value']);
        $this->assertFalse($listed['source']['inherited']);
    }

    /**
     * A sensitive setting to write to.
     *
     * The catalogue holds none today. A test that skipped when it found no
     * secret would stop checking silently the day somebody adds one, so this
     * brings its own — the guarantee is about the controller, not about which
     * settings happen to be registered.
     */
    private function registerSensitiveSetting(): void
    {
        $registry = new SettingRegistry();

        $registry->register(new SettingDefinition(
            key: 'integrations.webhook_secret',
            label: 'Webhook signing secret',
            type: SettingDefinition::TYPE_STRING,
            default: '',
            allowedScopes: [SettingDefinition::SCOPE_ORGANIZATION],
            isSensitive: true,
        ));

        $this->app->instance(SettingRegistry::class, $registry);

        // The resolver is a singleton that holds its own registry. Left alone it
        // would keep the catalogue without this key and getOrFail() on it.
        $this->app->forgetInstance(SettingsResolver::class);
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

    /**
     * Deactivation locks the writes, and it is worth pinning at THIS level.
     *
     * The deploy desk's sweep on 2026-09-15 found four sites that re-derive
     * "stands at the organization" by hand and so miss the `is_active` clause
     * the model method carries. Mine were two of them and both now ask
     * `canActOnOrganization`.
     *
     * WHAT THIS PINS IS THE PROPERTY, NOT EITHER IMPLEMENTATION OF IT, and I
     * only know that because the first version of this docblock was wrong and
     * a mutation said so. I had written that neither change was observable,
     * reasoning that `refusalFor` asks `hasPermission()` before `reaches()` and
     * that its `is_active` line therefore answers first. Measured instead:
     *
     *   both clauses in place                     PATCH -> 403
     *   hasPermission's is_active removed         PATCH -> 403   (this line's
     *                                                            new call holds
     *                                                            it alone)
     *   canActOnOrganization's removed too        PATCH -> 200   test fails
     *
     * So asking the model rather than re-deriving the test made a SECOND
     * enforcement point where there had been one, which is the whole of what
     * defence in depth means here and is more than I claimed for it. Three runs
     * rather than an argument, and the last one is the control: without it,
     * "still 403" would equally describe an endpoint nobody can reach.
     *
     * WHAT IT DOES NOT PIN, because the sweep that prompted it was retracted
     * the next hour and the retraction is the more useful half.
     *
     * "Somebody let go stops being able to write" is NOT held up by this test,
     * and a reader who thinks it is would be trusting the thinnest of the three
     * mechanisms. The product refuses them twice over before anything here is
     * reached, and I read all three rather than taking the correction:
     *
     *   AuthenticateUser:93    a deactivated account fails the password path
     *   AuthenticateUser:209   and the second-step path after it
     *   HrPeopleController:386 deactivating through HR DELETES their tokens
     *
     * So the ordinary path leaves them unable to sign in and holding nothing to
     * sign in with. What this test covers is the residual: a session already
     * open when somebody is deactivated by a DIRECT DATABASE WRITE, outside the
     * HR flow, whose tokens therefore survive. `Sanctum::actingAs` is that
     * shape exactly — it mints reach without going near the login the real
     * account would have failed.
     *
     * Narrow, real, and worth one test. Stated at its true size because a test
     * believed to guard more than it does is how the guard that actually
     * matters stops being checked.
     *
     * The read is the control. Without it this passes against a suspended
     * account that cannot reach the endpoint at all, or a scope id that was
     * never found, and those are different failures with the same status code.
     */
    public function test_a_deactivated_administrator_can_no_longer_write_settings(): void
    {
        ['organization' => $org, 'administrator' => $admin] = $this->org('Alpha', 'a@a.test');

        Sanctum::actingAs($admin);

        $this->patchJson(
            "/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}",
            ['value' => 'en']
        )->assertOk();

        app(TenantContext::class)->run($org, fn () => $admin->forceFill(['is_active' => false])->save());

        $this->patchJson(
            "/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}",
            ['value' => 'en']
        )->assertForbidden();
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

    /**
     * A module's Settings tab gates its rows HERE too (deploy desk, 2026-09-29).
     *
     * Both roles hold `organizations.edit`, so the only difference between them
     * is the tab grant — an administrator fixture would pass either way.
     */
    public function test_a_modules_settings_rows_follow_its_settings_tab(): void
    {
        ['organization' => $org] = $this->org('Alpha', 'a@a.test');

        $person = fn (string $name, array $keys) => app(TenantContext::class)->run($org, function () use ($org, $name, $keys) {
            $role = Role::create(['organization_id' => $org->id, 'name' => $name]);
            $role->syncPermissionKeys($keys);

            return User::create([
                'organization_id' => $org->id, 'role_id' => $role->id,
                'name' => $name, 'email' => strtolower($name).'@a.test', 'password' => 'pw-here-please',
            ])->fresh(); // is_active comes from the column default, which create() does not load
        });

        $without = $person('Without', ['organizations.edit']);
        $with    = $person('With', ['organizations.edit', 'work_hours.settings.view']);

        $keys = function (User $user) use ($org) {
            // The guard keeps the first user across requests otherwise.
            $this->app['auth']->forgetGuards();
            Sanctum::actingAs($user);

            return collect($this->getJson("/api/v1/settings?scope_type=organization&scope_id={$org->id}")
                ->assertOk()->json('data'))->pluck('key');
        };

        $this->assertTrue($keys($without)->contains('locale'), 'A group with no module tab stays readable.');
        $this->assertFalse($keys($without)->contains('time_clock.enabled'));
        $this->assertTrue($keys($with)->contains('time_clock.enabled'));

        // And not writable by the back door the rows used to be.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($without);
        $this->patchJson("/api/v1/settings/time_clock.enabled?scope_type=organization&scope_id={$org->id}", ['value' => true])
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
