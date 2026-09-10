<?php

namespace Tests\Feature\Api;

use App\Models\Automation;
use App\Models\Customer;
use App\Models\Job;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
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
    /**
     * The flag, not only the sentence.
     *
     * `TriggerDefinition` appends "Nothing raises this yet…" to the
     * description, which says the consequence. It arrives as the tail of a
     * paragraph, so the picker could not mark the row without reading prose.
     * Publishing `is_raised` is what lets it badge one.
     *
     * Break it by dropping the key from the catalogue and this goes red on the
     * false case — the one that matters, because a true that is wrong merely
     * badges nothing, while a false that is wrong badges every trigger.
     */
    public function test_the_catalogue_says_which_triggers_nothing_raises_yet(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $published = [];

        foreach ($data['modules'] as $module) {
            foreach ($module['triggers'] as $trigger) {
                $published[$trigger['key']] = $trigger['is_raised'];
            }
        }

        $this->assertNotEmpty($published);

        // Against the registry rather than a hand-written list: the set of
        // unraised triggers shrinks as emitters land, and a literal here would
        // be a second copy that goes stale the day one does.
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->triggers as $trigger) {
                if (! array_key_exists($trigger->key, $published)) {
                    continue;   // a module this actor cannot reach
                }

                $this->assertSame(
                    $trigger->isRaised,
                    $published[$trigger->key],
                    "The catalogue disagrees with the registry about whether "
                    ."'{$trigger->key}' is raised, so the builder would mark the "
                    .'wrong row.'
                );
            }
        }

        // And prove the false case is actually exercised — an assertion loop
        // over triggers that are all raised would pass while testing nothing.
        $this->assertContains(
            false,
            $published,
            'No unraised trigger reached the catalogue, so this test proved '
            .'nothing about the flag it exists to check.'
        );
    }

    public function test_the_catalogue_matches_the_contract(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $this->assertSame(
            ['modules', 'operators', 'action_types', 'timezones', 'schedule_kinds', 'limits'],
            array_keys($data)
        );

        /*
         * The zones are the settings registry's, not a list kept here.
         *
         * Asserted as non-empty rather than by content: the twelve places and
         * the whole-hour offsets belong to Settings and will change without
         * this test being told, but a catalogue that published NO zones would
         * leave the builder unable to offer a schedule at all while looking
         * fine — the empty list is what it checks before drawing the editor.
         */
        $this->assertNotEmpty($data['timezones']);
        $this->assertSame(['id', 'label'], array_keys($data['timezones'][0]));

        $this->assertSame(
            ['daily', 'weekly', 'monthly'],
            array_column($data['schedule_kinds'], 'key')
        );

        foreach ($data['modules'] as $module) {
            $this->assertSame(
                ['key', 'label', 'parent', 'subject', 'triggers', 'declared_actions'],
                array_keys($module)
            );

            if ($module['subject'] !== null) {
                $this->assertSame(
                    ['key', 'label', 'writable_fields'],
                    array_keys($module['subject'])
                );

                foreach ($module['subject']['writable_fields'] as $field) {
                    $this->assertSame(['key', 'label'], array_keys($field));
                }
            }

            foreach ($module['triggers'] as $trigger) {
                $this->assertSame(
                    ['key', 'label', 'description', 'is_raised'],
                    array_keys($trigger)
                );
            }

            foreach ($module['declared_actions'] as $action) {
                $this->assertSame(
                    ['key', 'label', 'description', 'type', 'subject_bound', 'implemented'],
                    array_keys($action)
                );
                $this->assertIsString($action['type']);
                $this->assertIsBool($action['subject_bound']);
                $this->assertIsBool($action['implemented']);
            }
        }

        foreach ($data['operators'] as $entry) {
            $this->assertSame(['key', 'label'], array_keys($entry));
        }

        foreach ($data['action_types'] as $entry) {
            $this->assertSame(['key', 'label', 'module_only', 'parameters'], array_keys($entry));
            $this->assertIsBool($entry['module_only']);
            foreach ($entry['parameters'] as $parameter) {
                $this->assertSame(
                    ['key', 'label', 'kind', 'required', 'nullable'],
                    array_keys($parameter),
                );
            }
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

        /*
         * Settings, not `user.invited`.
         *
         * This test used to name `user.invited`, which was the clearest example
         * of an unraised trigger right up until Human Resources started raising
         * it. Settings are still saved without going through the outbox, so
         * `organization.settings_changed` is the remaining one — and it is asked
         * of the REGISTRY rather than named twice, so the day that one is wired
         * up as well this test says what needs doing instead of failing on a
         * detail of which trigger happened to be chosen.
         */
        $unraised = collect(app(ModuleRegistry::class)->all())
            ->flatMap(fn ($module) => $module->triggers)
            ->first(fn (TriggerDefinition $t) => ! $t->isRaised);

        $this->assertNotNull(
            $unraised,
            'Every declared trigger is now raised. Delete this test, or keep it by declaring '
            .'the next unraised one — it exists to prove the catalogue MARKS them.',
        );

        $listed = collect($data['modules'])
            ->flatMap(fn (array $m) => $m['triggers'])
            ->firstWhere('key', $unraised->key);

        $this->assertSame($unraised->label, $listed['label']);
        $this->assertStringContainsString(
            TriggerDefinition::NOT_RAISED_YET,
            $listed['description'],
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

    /**
     * What a rule may SET, published — and derived from the one list that
     * enforces it.
     *
     * The field was a free-text box because the allowlist lived somewhere the
     * form could not see. Asserted against `ActionRunner::writableOn` rather
     * than against a list retyped here, because a second copy in the test is the
     * same mistake one layer further out: it would pass while the wire and the
     * engine disagreed.
     */
    public function test_the_catalogue_publishes_what_a_rule_may_set_on_each_subject(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $modules = collect($data['modules'])->keyBy('key');

        $this->assertSame('job', $modules['jobs']['subject']['key']);
        $this->assertSame('Job', $modules['jobs']['subject']['label']);

        foreach ([Job::class => 'jobs', Customer::class => 'customers'] as $model => $key) {
            $this->assertSame(
                ActionRunner::writableOn($model),
                collect($modules[$key]['subject']['writable_fields'])->pluck('label', 'key')->all(),
                "The catalogue and the engine disagree about what is writable on {$model}."
            );
        }

        // A picker a business owner reads. "scheduled_for" is a wire name.
        $fields = collect($modules['jobs']['subject']['writable_fields'])->pluck('label', 'key');

        $this->assertSame('Date and time', $fields['scheduled_for']);
        $this->assertSame('Amount in cents', $fields['amount_minor']);

        foreach ($fields as $key => $label) {
            $this->assertNotSame($key, $label, $key);
            $this->assertDoesNotMatchRegularExpression('/[._]/', $label, $key);
        }

        // Empty, not absent. "A rule can react to a company but cannot change
        // one" is an answer; a missing key is indistinguishable from an older
        // server, and the client would have to guess which.
        $this->assertSame('Company', $modules['companies']['subject']['label']);
        $this->assertSame([], $modules['companies']['subject']['writable_fields']);
    }

    /**
     * The probe that returned 201.
     *
     * `{'type':'update_field','field':'organization_id'}` on a job trigger was
     * accepted, and then refused by ActionRunner on every run for the life of
     * the rule — in a queue worker, where the only trace is a run log nobody is
     * watching. Refusing it here is the same call the webhook check already
     * makes one action along.
     */
    public function test_a_field_no_rule_may_set_is_refused_while_someone_is_looking_at_the_form(): void
    {
        $response = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Move the job',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'update_field', 'field' => 'organization_id', 'value' => 'x']],
        ])->assertStatus(422);

        // The key is the literal string 'actions.0.field', so dot traversal walks past it.
        $message = $response->json('errors')['actions.0.field'][0];

        // Names the field, and says what IS allowed — a 422 reading "invalid"
        // makes somebody guess.
        $this->assertStringContainsString('organization_id', $message);
        $this->assertStringContainsString('Job', $message);
        $this->assertStringContainsString('title, description, status', $message);
    }

    public function test_an_empty_condition_row_is_refused_while_someone_is_looking_at_the_form(): void
    {
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Broken if',
            'trigger_key' => 'job.status_changed',
            'conditions' => ['all' => [['field' => '', 'op' => 'eq']]],
            'actions' => [['type' => 'notify', 'message' => 'hi']],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('conditions.all.0');
    }

    /**
     * The other subject's fields are refused too, which is the second half of
     * the same defect: the picker offered "Customers › Update a field" under a
     * job trigger, and the engine writes to the JOB.
     */
    public function test_a_field_belonging_to_the_other_subject_is_refused(): void
    {
        $response = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Email the job',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'update_field', 'field' => 'email', 'value' => 'a@b.test']],
        ])->assertStatus(422);

        $this->assertStringContainsString(
            'not a field an automation may set on Job',
            $response->json('errors')['actions.0.field'][0]
        );
    }

    public function test_a_field_writable_on_the_triggers_subject_still_saves(): void
    {
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Close it',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'update_field', 'field' => 'status', 'value' => 'completed']],
        ])->assertCreated();

        // The same field name would be wrong on the other subject, so this is
        // not "any known field" — it is this trigger's record.
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Note it',
            'trigger_key' => 'customer.created',
            'actions' => [['type' => 'update_field', 'field' => 'notes', 'value' => 'New']],
        ])->assertCreated();

        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Wrong way round',
            'trigger_key' => 'customer.created',
            'actions' => [['type' => 'update_field', 'field' => 'status', 'value' => 'completed']],
        ])->assertStatus(422);
    }

    /**
     * A trigger whose record nothing may change says so, rather than listing an
     * empty allowance and leaving somebody to conclude they typed it wrong.
     */
    public function test_a_trigger_about_a_record_no_rule_may_change_says_so(): void
    {
        $response = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rename the company',
            'trigger_key' => 'company.created',
            'actions' => [['type' => 'update_field', 'field' => 'name', 'value' => 'Other']],
        ])->assertStatus(422);

        $message = $response->json('errors')['actions.0.field'][0];

        $this->assertStringContainsString('cannot set any field on Company', $message);
        // And points at the records that CAN be changed, read off the registry
        // rather than written into the sentence.
        $this->assertStringContainsString('a Customer, a Job or a Vehicle', $message);
    }

    /**
     * change_status is generic, so the catalogue will offer it under any trigger
     * whose module declared it. The engine only runs it for a Job or a Vehicle.
     * Saving it against a Customer would look configured and fail on every run.
     */
    public function test_change_status_on_a_record_that_cannot_change_status_is_refused_at_save(): void
    {
        $response = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Status the customer',
            'trigger_key' => 'customer.created',
            'actions' => [['type' => 'change_status', 'value' => 'active']],
        ])->assertStatus(422);

        $message = (string) ($response->json('errors')['actions.0.type'][0] ?? '');

        $this->assertNotSame('', $message, 'The rule was refused without saying anything.');
        $this->assertStringContainsString('cannot change the status of Customer', $message);
        $this->assertStringContainsString('Job', $message);
        $this->assertStringContainsString('Vehicle', $message);
        $this->assertStringNotContainsString('customer.created', $message);
    }

    public function test_change_status_on_a_job_or_vehicle_still_saves(): void
    {
        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Close the job',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'change_status', 'value' => 'completed']],
        ])->assertCreated();

        $this->as()->postJson('/api/v1/automations', [
            'name' => 'Park the van',
            'trigger_key' => 'vehicle.created',
            'actions' => [['type' => 'change_status', 'value' => 'in_shop']],
        ])->assertCreated();
    }

    /**
     * An edit is checked against the trigger the rule already has.
     *
     * A PATCH may not carry `trigger_key` at all, so the subject has to come off
     * the record — and a check that quietly passed because the request had no
     * trigger in it would leave the update path exactly as open as the create
     * path used to be.
     */
    public function test_replacing_the_actions_is_checked_against_the_rules_own_trigger(): void
    {
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Close it',
            'trigger_key' => 'job.status_changed',
            'actions' => [['type' => 'update_field', 'field' => 'status', 'value' => 'completed']],
        ])->assertCreated()->json('data.id');

        $this->as()->patchJson("/api/v1/automations/{$id}", [
            'actions' => [['type' => 'update_field', 'field' => 'email', 'value' => 'a@b.test']],
        ])->assertStatus(422);

        $this->as()->patchJson("/api/v1/automations/{$id}", [
            'actions' => [['type' => 'update_field', 'field' => 'title', 'value' => 'Done']],
        ])->assertOk();
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
