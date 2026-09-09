<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\Organization;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `organization.settings_changed` — the trigger that was declared and dead.
 *
 * It shipped with `isRaised: false`, which puts "Nothing raises this yet, so a
 * rule built on it will not run" in front of the tenant. Visible and honest, and
 * still a rule somebody would want: a setting change is an administrative act,
 * and the people who care are the ones who did not make it.
 *
 * The half that needs a test is not the emit — it is WHO HEARS. A subject that
 * cannot say where it sits sends `NotificationDispatcher::audienceFor` to every
 * active user in the organization, and `notify` is not gated per trigger, so
 * every raised trigger is an audience question. That is the owed model check
 * `AutomationSubjectScopeTest` names and cannot perform itself: it proves the
 * interface is DECLARED, never what it answers.
 */
class SettingsAutomationEventTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        Sanctum::actingAs($this->org['administrator']);
    }

    private function events(): Collection
    {
        return app(TenantContext::class)->run(
            $this->org['organization'],
            fn () => AutomationEvent::where('event_key', 'organization.settings_changed')
                ->orderBy('occurred_at')
                ->get(),
        );
    }

    public function test_saving_a_setting_raises_the_trigger_with_the_change_in_it(): void
    {
        $org = $this->org['organization'];

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", [
            'value' => 'es',
        ])->assertOk();

        $this->assertCount(1, $this->events(), 'The trigger the builder offers has to fire.');

        $event = $this->events()->first();

        $this->assertSame('locale', $event->payload['key']);
        $this->assertSame('organization', $event->payload['scope_type']);
        $this->assertSame($org->id, $event->payload['scope_id']);
        // Nested, because that is where ConditionEvaluator reads "changed to"
        // and "changed from" — a flat payload would answer neither.
        $this->assertSame('en', $event->payload['before']['value']);
        $this->assertSame('es', $event->payload['after']['value']);
    }

    public function test_the_level_the_value_was_written_at_travels_in_the_payload(): void
    {
        $franchise = $this->org['franchise'];

        $this->patchJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$franchise->id}", [
            'value' => 'es',
        ])->assertOk();

        $event = $this->events()->first();

        $this->assertSame('franchise', $event->payload['scope_type']);
        $this->assertSame($franchise->id, $event->payload['scope_id']);
        // Filed against the tenant root even so — see SettingsController::announce.
        $this->assertSame(Organization::class, $event->subject_type);
        $this->assertSame($this->org['organization']->id, $event->subject_id);
    }

    public function test_saving_the_value_that_is_already_there_raises_nothing(): void
    {
        $org = $this->org['organization'];
        $url = "/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}";

        $this->patchJson($url, ['value' => 'es'])->assertOk();
        $this->patchJson($url, ['value' => 'es'])->assertOk();

        $this->assertCount(1, $this->events(), 'The second save moved nothing, so there was nothing to react to.');
    }

    public function test_dropping_an_override_raises_it_too(): void
    {
        $org       = $this->org['organization'];
        $franchise = $this->org['franchise'];

        $this->patchJson("/api/v1/settings/locale?scope_type=organization&scope_id={$org->id}", [
            'value' => 'es',
        ])->assertOk();

        $this->patchJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$franchise->id}", [
            'value' => 'en',
        ])->assertOk();

        $this->deleteJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$franchise->id}")
            ->assertOk();

        $reset = $this->events()->last();

        $this->assertSame('en', $reset->payload['before']['value']);
        $this->assertSame('es', $reset->payload['after']['value'], 'The franchise inherits the organization again.');
    }

    public function test_resetting_an_override_that_changed_nothing_raises_nothing(): void
    {
        $franchise = $this->org['franchise'];

        // Set the franchise to the value it already inherits, then drop it. The
        // effective value never moves, so neither call is a change.
        $this->patchJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$franchise->id}", [
            'value' => 'en',
        ])->assertOk();

        $this->deleteJson("/api/v1/settings/locale?scope_type=franchise&scope_id={$franchise->id}")
            ->assertOk();

        $this->assertCount(0, $this->events());
    }

    /**
     * The model check the shared gate cannot make (AutomationSubjectScopeTest).
     *
     * Reading the answer, not the interface: a subject that implements
     * CarriesAutomationScope and answers wrongly passes every assertion in that
     * file. `[null, null]` is what sends `audienceFor` down the organization
     * branch — the people who STAND at the organization — instead of the
     * every-active-user fallthrough a missing interface produces.
     */
    public function test_the_organization_says_it_is_organization_wide(): void
    {
        $this->assertSame([null, null], $this->org['organization']->automationScope());
    }

    /**
     * And the engine check: an organization-wide rule covers it, a narrower one
     * does not. Guards `coversScope` rather than the model — if its semantics
     * move, this module finds out instead of silently widening.
     */
    public function test_only_an_organization_wide_rule_covers_a_settings_change(): void
    {
        [$type, $id] = $this->org['organization']->automationScope();

        $organizationWide = new Automation(['scope_type' => null, 'scope_id' => null]);
        $atTheCompany     = new Automation(['scope_type' => 'company', 'scope_id' => $this->org['company']->id]);
        $atTheFranchise   = new Automation(['scope_type' => 'franchise', 'scope_id' => $this->org['franchise']->id]);

        $this->assertTrue($organizationWide->coversScope($type, $id, []));
        $this->assertFalse($atTheCompany->coversScope($type, $id, []));
        $this->assertFalse($atTheFranchise->coversScope($type, $id, []));
    }
}
