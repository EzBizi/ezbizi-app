<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Services\Organizations\ProvisionOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The surface that makes automation tenant-configurable (plan §5.5, §2.1).
 *
 * An engine nobody can configure is not the no-hardcoding rule — it is a
 * hardcoded engine. These test the things that make it safe to hand to a tenant.
 */
class AutomationsApiTest extends TestCase
{
    use RefreshDatabase;

    private array $org;

    protected function setUp(): void
    {
        parent::setUp();

        $r = app(ProvisionOrganization::class)(
            organizationName: 'Alpha',
            administrator: ['name' => 'Admin', 'email' => 'a@alpha.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $this->org = ['organization' => $r['organization'], 'admin' => $r['administrator']];
    }

    private function as(): static
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'a@alpha.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    public function test_the_catalogue_comes_from_the_module_registry(): void
    {
        $response = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk();

        $modules = collect($response->json('data.modules'))->keyBy('key');

        // A module added next year appears here the moment it declares itself.
        // That is the §5.5 standing rule doing its job — nothing is listed twice,
        // in code and in a catalogue that drifts.
        $this->assertTrue($modules->has('jobs'));
        $this->assertContains(
            'job.status_changed',
            collect($modules['jobs']['triggers'])->pluck('key')->all()
        );
        $this->assertNotEmpty($response->json('data.operators'));

        // Modules with nothing to automate are absent rather than empty — an
        // empty group is a tab a person opens for nothing.
        $this->assertFalse($modules->has('audit'));
    }

    /**
     * The shape, exactly.
     *
     * Two ends are built against this at once and neither can see the other, so
     * the contract is asserted key by key rather than "it has some triggers".
     * A field quietly renamed here is a client that renders blanks.
     */
    public function test_the_catalogue_matches_the_contract(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $this->assertSame(
            ['modules', 'operators', 'action_types', 'limits'],
            array_keys($data)
        );

        foreach ($data['modules'] as $module) {
            $this->assertSame(['key', 'label', 'triggers', 'declared_actions'], array_keys($module));

            foreach ($module['triggers'] as $trigger) {
                $this->assertSame(['key', 'label', 'description'], array_keys($trigger));
            }

            foreach ($module['declared_actions'] as $action) {
                $this->assertSame(
                    ['key', 'label', 'description', 'implemented'],
                    array_keys($action)
                );
                $this->assertIsBool($action['implemented']);
            }
        }

        foreach ([...$data['operators'], ...$data['action_types']] as $entry) {
            $this->assertSame(['key', 'label'], array_keys($entry));
        }

        $this->assertSame(['max_depth', 'action_budget'], array_keys($data['limits']));
    }

    /**
     * The catalogue is the vocabulary, so it has to be readable on its own.
     *
     * Every trigger arrives with a name somebody would say out loud and a
     * sentence saying when it fires. This is the whole reason the endpoint
     * stopped returning bare strings: the builder was rendering
     * `organization.settings_changed` at the person writing the rule.
     */
    public function test_no_wire_key_reaches_the_screen_as_a_name(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        foreach ($data['modules'] as $module) {
            $this->assertNotSame('', trim($module['label']));

            foreach ([...$module['triggers'], ...$module['declared_actions']] as $entry) {
                $this->assertNotSame($entry['key'], $entry['label'], $entry['key']);
                $this->assertDoesNotMatchRegularExpression('/[._]/', $entry['label'], $entry['key']);
                $this->assertNotSame('', trim($entry['description']), $entry['key']);
            }
        }

        $jobs = collect($data['modules'])->firstWhere('key', 'jobs');

        $this->assertSame(
            'Status changed',
            collect($jobs['triggers'])->firstWhere('key', 'job.status_changed')['label']
        );
    }

    /**
     * "This exists but is not built yet" is renderable without guessing.
     *
     * The flag says which; the wording beside it has to carry the rest, because
     * a badge on a row reading `job.assign_to_user` explains nothing. And the
     * description must describe the ACTION, not its absence — the sentence has
     * to still read correctly on the day the flag flips.
     */
    public function test_an_action_the_engine_cannot_run_still_explains_itself(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $unbuilt = collect($data['modules'])
            ->flatMap(fn (array $m) => $m['declared_actions'])
            ->reject(fn (array $a) => $a['implemented']);

        $this->assertNotEmpty($unbuilt, 'Nothing is marked unbuilt, so this proves nothing.');

        foreach ($unbuilt as $action) {
            $this->assertNotSame('', trim($action['label']), $action['key']);
            $this->assertNotSame('', trim($action['description']), $action['key']);
        }

        // And the three the runner actually performs are marked as such, in the
        // same list rather than a separate one — hiding them would leave the
        // catalogue disagreeing with what the module declared.
        $implemented = collect($data['modules'])
            ->flatMap(fn (array $m) => $m['declared_actions'])
            ->filter(fn (array $a) => $a['implemented'])
            ->pluck('key');

        $this->assertContains('job.update_field', $implemented);
        $this->assertContains('customer.update_field', $implemented);
    }

    /**
     * A trigger nothing emits is listed, and says so.
     *
     * Hiding it would be tidier and worse: the validator accepts any trigger the
     * registry declares, so a tenant could not build the rule from the picker
     * but the rule would still be legal — and the picker would be lying about
     * what the system knows about.
     */
    public function test_a_trigger_nothing_raises_yet_warns_the_person_building_the_rule(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $users = collect($data['modules'])->firstWhere('key', 'users');
        $invited = collect($users['triggers'])->firstWhere('key', 'user.invited');

        $this->assertSame('Invited', $invited['label']);
        $this->assertStringContainsString(
            \App\Support\Modules\TriggerDefinition::NOT_RAISED_YET,
            $invited['description']
        );
    }

    public function test_a_new_automation_starts_switched_off(): void
    {
        $response = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Close completed jobs',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'notify', 'message' => 'Job {{ title }} is done']],
        ])->assertCreated();

        // A rule that starts firing the instant it is saved fires before anyone
        // has read it back.
        $this->assertFalse($response->json('data.is_active'));
        $this->assertSame(1, $response->json('data.version'));
    }

    public function test_a_trigger_nothing_emits_is_refused_at_save_time(): void
    {
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Never fires',
            'trigger_key' => 'job.levitated',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertStatus(422)
          ->assertJsonPath('errors.trigger_key.0',
              "Nothing in this system emits 'job.levitated'. Pick one from the catalogue "
              .'— a rule listening for something that never happens looks configured and '
              .'simply never runs.');
    }

    public function test_an_internal_webhook_is_refused_while_someone_is_looking_at_the_form(): void
    {
        $response = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Exfiltrate',
            'trigger_key' => 'job.created',
            'actions' => [['type' => 'webhook', 'url' => 'https://169.254.169.254/latest/meta-data/']],
        ])->assertStatus(422);

        // Checked at save, not only when it runs hours later in a queue worker
        // where only the run log would mention it.
        // The key is the literal string 'actions.0.url', so dot traversal walks past it.
        $errors = $response->json('errors');
        $this->assertStringContainsString('internal address', $errors['actions.0.url'][0]);
    }

    public function test_editing_writes_a_new_version_and_leaves_the_old_one_alone(): void
    {
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'first']],
        ])->assertCreated()->json('data.id');

        $updated = $this->as()->patchJson("/api/v1/automations/{$id}", [
            'actions' => [['type' => 'notify', 'message' => 'second']],
        ])->assertOk();

        $this->assertSame(2, $updated->json('data.version'));
        $this->assertCount(2, $updated->json('data.versions'));
        $this->assertSame('second', $updated->json('data.actions.0.message'));
    }

    public function test_an_earlier_version_can_be_reactivated(): void
    {
        $created = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'first']],
        ])->assertCreated();

        $id = $created->json('data.id');
        $firstVersion = $created->json('data.versions.0.id');

        $this->as()->patchJson("/api/v1/automations/{$id}", [
            'actions' => [['type' => 'notify', 'message' => 'second']],
        ])->assertOk();

        // Rollback is the whole reason versions are immutable: pointing back at
        // an older definition is the recovery path when a new rule is wrong.
        $rolled = $this->as()
            ->postJson("/api/v1/automations/{$id}/activate/{$firstVersion}")
            ->assertOk();

        $this->assertSame('first', $rolled->json('data.actions.0.message'));
        $this->assertSame(1, $rolled->json('data.version'));
    }

    public function test_the_trigger_cannot_be_changed_after_creation(): void
    {
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        // Changing it makes it a different rule, and every run recorded so far
        // would describe something it no longer does.
        $this->as()->patchJson("/api/v1/automations/{$id}", [
            'trigger_key' => 'job.deleted',
        ])->assertStatus(422);
    }

    public function test_testing_a_rule_with_nothing_to_test_against_says_so(): void
    {
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rule', 'trigger_key' => 'customer.owner_reassigned',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        // Better than an empty result that looks like "it did not match".
        $this->as()->postJson("/api/v1/automations/{$id}/test")
            ->assertStatus(422)
            ->assertJsonPath('code', 'no_event_to_test');
    }

    public function test_re_enabling_clears_the_auto_disable(): void
    {
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        Automation::withoutGlobalScopes()->whereKey($id)->update([
            'disabled_at' => now(), 'disabled_reason' => 'It kept failing.',
            'consecutive_failures' => 5,
        ]);

        $response = $this->as()->patchJson("/api/v1/automations/{$id}", ['is_active' => true])->assertOk();

        // Switching it back on is a deliberate act that clears the failure count
        // — otherwise it would trip again on the next single failure.
        $this->assertTrue($response->json('data.is_live'));
        $this->assertNull($response->json('data.disabled_at'));
    }

    public function test_another_organization_cannot_see_or_edit_the_rules(): void
    {
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Alpha rule', 'trigger_key' => 'job.created',
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertCreated()->json('data.id');

        app(ProvisionOrganization::class)(
            organizationName: 'Bravo',
            administrator: ['name' => 'B', 'email' => 'b@bravo.test', 'password' => 'a-real-password'],
            companyName: 'Co', franchiseName: 'F1',
        );

        $token = $this->postJson('/api/v1/auth/login', [
            'email' => 'b@bravo.test', 'password' => 'a-real-password',
        ])->assertOk()->json('token');

        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/automations')->assertOk()->assertJsonCount(0, 'data');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/automations/{$id}")->assertNotFound();
    }
}
