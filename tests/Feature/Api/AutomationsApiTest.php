<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AutomationsController;
use App\Models\Automation;
use App\Models\Customer;
use App\Models\Franchise;
use App\Support\Tenancy\TenantContext;
use App\Models\Job;
use App\Services\Organizations\ProvisionOrganization;
use App\Support\Automations\ActionRunner;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
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
            ['modules', 'people', 'work_hours_reports', 'whatsapp_groups', 'report_text_kinds', 'reports', 'places', 'templates', 'trainings', 'task_boards', 'contracts', 'audiences', 'roles', 'operators', 'action_types', 'timezones', 'schedule_kinds', 'limits'],
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
                    $this->assertSame(['key', 'label', 'kind', 'options'], array_keys($field));
                }
            }

            foreach ($module['triggers'] as $trigger) {
                $this->assertSame(
                    ['key', 'label', 'description', 'is_raised', 'subjects'],
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
            $this->assertSame(['key', 'label', 'module_only', 'parameters', 'subjects'], array_keys($entry));
            $this->assertIsBool($entry['module_only']);
            foreach ($entry['parameters'] as $parameter) {
                $this->assertSame(
                    ['key', 'label', 'kind', 'required', 'nullable', 'variables'],
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
     * Execution types several modules share, and WHY each one is shared.
     *
     * `ActionDefinition::type()` derives the execution type as everything after
     * the first dot, so `customer.update_field` and `job.update_field` both
     * reduce to `update_field` and route to one arm. That is sometimes the
     * whole design and sometimes a collision, and the two look identical here.
     *
     * A bare allowlist would be worse than nothing: it would grandfather the
     * dangerous half into invisibility. So each entry says which kind it is.
     */
    private const SHARED_EXECUTION_TYPES = [
        /*
         * GENERIC BY DESIGN. The arm exists and reads the EVENT'S SUBJECT, so
         * several modules pointing at it is deliberate — one implementation
         * applied to many records, which is the thing `type()` strips the
         * prefix for in the first place.
         */
        'update_field' => 'generic: updateField writes to whatever subject the event carries',
        'change_status' => 'generic: changeStatus routes on the subject, a Job or a Vehicle',

        /*
         * UNDECIDED, AND THIS IS THE HALF WORTH WATCHING.
         *
         * Declared by several modules with NO ARM YET, so nobody has chosen
         * whether they are one generic implementation or several module-specific
         * ones — and the first person to write an arm decides for all of them
         * without being asked. If they write a Customer-shaped `deactivate`,
         * then `company.deactivate`, `franchise.deactivate` and
         * `user.deactivate` begin deactivating through it, with no error
         * anywhere, because routing by a shared key cannot tell the cases apart.
         *
         * That is the near-miss Inspection renamed away from in one commit —
         * `report.email` and `report.send` would have been one module's executor
         * serving the other's action — except this one will arrive as somebody
         * implementing their own action rather than as somebody adding a second.
         *
         * WHOEVER WRITES THE FIRST ARM FOR ONE OF THESE: either make it generic
         * over the subject and say so here, or give your declaration an explicit
         * `executionType` so it stops sharing. Do not simply add the arm.
         *
         * Found by the Training session, who swept all 31 declarations after
         * Inspection's rename rather than checking only their own.
         */
        'deactivate' => 'undecided: four modules declare it, no arm has chosen yet',
        'assign_to_user' => 'undecided: two modules declare it, no arm has chosen yet',
    ];

    /**
     * A NEW shared execution type has to be argued for, not discovered later.
     *
     * Sharing is legitimate and common here, so this cannot refuse it — a guard
     * that goes red on correct practice gets weakened and then watches nothing.
     * It refuses only an UNRECORDED sharing, which costs one line and a sentence
     * and puts the question in front of whoever created it, at the moment they
     * can still rename for free.
     */
    public function test_a_shared_execution_type_is_one_somebody_argued_for(): void
    {
        $byType = [];

        foreach (app(ModuleRegistry::class)->all() as $key => $module) {
            foreach ($module->automationActions as $action) {
                $byType[$action->type()][] = "{$key}/{$action->key}";
            }
        }

        $shared = array_keys(array_filter($byType, fn (array $owners) => count($owners) > 1));
        $unrecorded = array_values(array_diff($shared, array_keys(self::SHARED_EXECUTION_TYPES)));

        $detail = implode('; ', array_map(
            fn (string $t) => "{$t} <- ".implode(', ', $byType[$t]),
            $unrecorded,
        ));

        $this->assertSame([], $unrecorded, implode("\n", [
            'These execution types are claimed by more than one declaration and',
            'nothing says whether that is deliberate: '.$detail,
            '',
            'They route to ONE arm. Either the arm is generic over the event subject —',
            'record it in SHARED_EXECUTION_TYPES saying so — or give your declaration',
            'an explicit executionType so it stops sharing. Renaming is free until',
            'somebody can select the action.',
        ]));
    }

    /**
     * EVERY ARM THE RUNNER HAS IS AN ACTION SOMEBODY CAN CHOOSE.
     *
     * `IMPLEMENTED_ACTIONS` and `ActionRunner`'s match are two halves of one
     * fact, and they drift in two directions with very different volumes.
     *
     * The const naming a type with no arm is LOUD: rules save, every run
     * records FAILED, somebody complains within the week. That direction has
     * been caught by hand twice.
     *
     * An ARM WITH NO CONST ENTRY IS SILENT. No exception, no failed run, no red
     * test — the catalogue publishes `implemented: false`, the builder draws
     * "Not built yet" and refuses the tap, and the only symptom is a feature
     * nobody can switch on. `report.email` shipped that way: a working Mailable
     * behind an action no customer could reach, and nothing in the suite asked.
     *
     * Only this direction is asserted. The reverse is legitimately allowed —
     * `training.assign` deliberately sat in the const ahead of its arm so the
     * two would land together — so a bidirectional test would have been red by
     * design and someone would have loosened it.
     *
     * Read from source because a match statement cannot be reflected over.
     * Anchored on `=> $this->` so a comment mentioning a key cannot be mistaken
     * for an arm; that is the known weakness of a source-scanning guard and it
     * is why the pattern is this tight rather than merely quoting the key.
     */
    /**
     * An action that says it sets a field must have a field to set.
     *
     * `lead.update_field` was published as working and `writableOn(Lead::class)`
     * answered an empty array, so the action was offered, the field picker had
     * no options, and the description promised two fields nobody could choose.
     * The arm/const drift guard could not see it: the arm exists, the const
     * lists it, and the thing missing was a THIRD list neither of them reads.
     *
     * So this checks the pairing directly — every module whose subject-bound
     * `update_field` is published must have writable fields declared for that
     * subject. Asserting the count alone would pass against a map that had
     * gained an unrelated entry, so it names the module that fails.
     */
    public function test_a_published_update_field_action_has_fields_to_write(): void
    {
        $registry = app(\App\Support\Modules\ModuleRegistry::class);

        $empty = [];

        foreach ($registry->all() as $key => $module) {
            foreach ($module->automationActions as $action) {
                if ($action->type() !== 'update_field') {
                    continue;
                }

                $published = (new ReflectionClass(AutomationsController::class))
                    ->getConstant('IMPLEMENTED_ACTIONS');

                if (! in_array('update_field', $published, true)) {
                    continue;
                }

                if (ActionRunner::writableOn($module->subject?->model) === []) {
                    $empty[] = $key.'/'.$action->key;
                }
            }
        }

        $this->assertSame([], $empty, implode("
", [
            'These modules publish "Update a field" as working and declare no writable',
            'fields for their subject, so the picker offers the action and then offers',
            'nothing to set: '.implode(', ', $empty),
        ]));
    }

    public function test_every_action_the_runner_can_perform_can_also_be_chosen(): void
    {
        $source = file_get_contents(app_path('Support/Automations/ActionRunner.php'));

        $match = preg_match('/return match \(\$type\) \{(.*?)\n        \};/s', $source, $m);
        $this->assertSame(1, $match, 'the runner no longer has one match on $type — this guard needs rewriting, not deleting');

        preg_match_all("/^\s+'([a-z0-9_.]+)' => \\\$this->/m", $m[1], $found);
        $arms = $found[1];

        $this->assertNotEmpty($arms, 'no arms were found, so this proves nothing about drift');

        // The regex above only sees an arm whose body is `$this->something`. An arm
        // written any other way vanishes from $arms and this test stays GREEN while the
        // drift it exists to catch goes unseen. So count every key in the block too and
        // require the two to agree: a shape this guard cannot read must be loud.
        preg_match_all("/^\s+'([a-z0-9_.]+)'[^\n]*=>/m", $m[1], $everyKey);
        $unreadable = array_values(array_diff($everyKey[1], $arms));

        $this->assertSame([], $unreadable, implode("\n", [
            'These arms exist but do not match the `=> $this->` shape this guard scans',
            'for, so it cannot check them against IMPLEMENTED_ACTIONS and would pass while',
            'they drift: '.implode(', ', $unreadable),
            'Widen the regex above - do not delete this assertion.',
        ]));

        $implemented = (new ReflectionClass(AutomationsController::class))
            ->getConstant('IMPLEMENTED_ACTIONS');

        $missing = array_values(array_diff($arms, $implemented));

        $this->assertSame([], $missing, implode("\n", [
            'These actions have a working arm in ActionRunner and are NOT in',
            'AutomationsController::IMPLEMENTED_ACTIONS, so the catalogue publishes them',
            'as not built, the builder refuses to let anybody choose them, and nothing',
            'anywhere reports it: '.implode(', ', $missing),
        ]));
    }

    /**
     * The warning is IN the sentence, the way an unraised trigger's already is.
     *
     * `implemented: false` is the machine-readable half and the builder draws it
     * — but a description is what a PERSON reads, and an unbuilt action's read
     * as a live capability. `report.send` promises to email a saved report and
     * has no arm in the runner; in the week SES production access was granted,
     * that sentence is what somebody answers "can a rule email me the weekly
     * report yet?" from.
     *
     * Both halves asserted, because only the pair can tell the fix from a
     * blanket append that would put the warning on everything.
     */
    public function test_an_unbuilt_action_says_so_in_its_description_and_a_built_one_does_not(): void
    {
        $data = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $actions = collect($data['modules'])->flatMap(fn (array $m) => $m['declared_actions']);

        $unbuilt = $actions->reject(fn (array $a) => $a['implemented']);
        $built = $actions->filter(fn (array $a) => $a['implemented']);

        $this->assertNotEmpty($unbuilt, 'Nothing is unbuilt, so this proves nothing.');
        $this->assertNotEmpty($built, 'Nothing is built, so the negative half proves nothing.');

        foreach ($unbuilt as $action) {
            $this->assertStringContainsString(
                ActionDefinition::NOT_BUILT_YET,
                $action['description'],
                "'{$action['key']}' cannot run and its description does not say so.",
            );
        }

        // The half that stops this becoming a blanket append. An action the
        // engine performs must NOT carry the warning, or the sentence is noise
        // on every row and nobody reads it on the rows that need it.
        foreach ($built as $action) {
            $this->assertStringNotContainsString(
                ActionDefinition::NOT_BUILT_YET,
                $action['description'],
                "'{$action['key']}' runs, but is described as not built.",
            );
        }
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
        // The list is DERIVED from ActionRunner::WRITABLE rather than written
        // out here, which is why adding Lead to that map changed this sentence
        // and reddened this test. That is the behaviour worth having: the
        // refusal cannot go stale against the capability it describes.
        $this->assertStringContainsString('a Customer, a Job, a Vehicle or a Lead', $message);
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

    public function test_a_patch_of_only_the_actions_keeps_the_conditions(): void
    {
        $conditions = ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'completed']]];
        $id = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Rule', 'trigger_key' => 'job.status_changed', 'conditions' => $conditions,
            'actions' => [['type' => 'notify', 'message' => 'first']],
        ])->assertCreated()->json('data.id');

        $updated = $this->as()->patchJson("/api/v1/automations/{$id}", [
            'actions' => [['type' => 'notify', 'message' => 'second']],
        ])->assertOk();

        $this->assertEquals($conditions, $updated->json('data.conditions'));
        $this->assertSame('second', $updated->json('data.actions.0.message'));

        // And the other half: conditions alone keep the actions.
        $updated = $this->as()->patchJson("/api/v1/automations/{$id}", ['conditions' => null])->assertOk();
        $this->assertNull($updated->json('data.conditions'));
        $this->assertSame('second', $updated->json('data.actions.0.message'));
    }

    public function test_texting_every_new_lead_needs_saying_out_loud(): void
    {
        $rule = ['name' => 'Text all', 'trigger_key' => 'lead.received', 'actions' => [['type' => 'sms.send']]];

        $this->as()->postJson('/api/v1/automations', $rule)->assertStatus(422)->assertJsonValidationErrors('conditions');
        $this->as()->postJson('/api/v1/automations', $rule + ['confirm_no_conditions' => true])->assertCreated();
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

    /**
     * A RULE BELONGS WHERE IT WAS MADE, AND IS READ WHERE IT ACTS.
     *
     * Jose, 2026-09-17: *"i made an automation for a-1chimney, but it's applying
     * for everything and im seeing it on any company"*. Both halves are one bug
     * seen twice: nothing ever SET a scope, so every rule was stored
     * organization-wide, and the list then showed every rule in the business to
     * everybody standing anywhere.
     *
     * THE ENGINE WAS NEVER WRONG, which is why this went unnoticed for so long
     * — `coversScope` has honoured company and franchise since it was written,
     * and every test that built a rule set the scope BY HAND. The app never did.
     * A fixture doing for the code what the code does not do for itself is the
     * shape that hides a missing write.
     */
    public function test_a_rule_made_in_one_view_belongs_to_it_and_says_so(): void
    {
        $franchise = Franchise::withoutGlobalScopes()
            ->where('organization_id', $this->org['organization']->id)
            ->firstOrFail();

        // Written while standing at ONE FRANCHISE, and the builder sends no
        // scope — exactly what the screen posts today.
        $id = $this->as()
            ->withHeader('X-EzBizi-View', 'franchise:'.$franchise->id)
            ->postJson('/api/v1/automations', [
                'name' => 'Only here',
                'trigger_key' => 'job.created',
                'actions' => [['type' => 'notify', 'message' => 'A job was made.']],
            ])->assertCreated()->json('data.id');

        $stored = Automation::withoutGlobalScopes()->findOrFail($id);

        $this->assertSame('franchise', $stored->scope_type,
            'a rule written at one place was stored as applying to the whole business');
        $this->assertSame($franchise->id, $stored->scope_id);

        // AND THE CHIP CARRIES A NAME. An id on a chip tells nobody anything,
        // and at the organization the name is the only thing separating one
        // company's rule from another's.
        $listed = $this->as()
            ->withHeader('X-EzBizi-View', 'franchise:'.$franchise->id)
            ->getJson('/api/v1/automations')->assertOk()->json('data');

        $this->assertSame(
            $franchise->name,
            collect($listed)->firstWhere('id', $id)['scope']['label'],
        );
    }

    /**
     * ASKING FOR EVERYWHERE IS STILL POSSIBLE, and is not the same as silence.
     *
     * The key being PRESENT is what separates "make this one apply to the whole
     * business" from "the builder said nothing". Without that distinction the
     * only way to write an organization-wide rule would be to change the top
     * bar first, which is a setting nobody would connect to the rule.
     */
    public function test_an_explicit_everywhere_survives_a_narrow_view(): void
    {
        $franchise = Franchise::withoutGlobalScopes()
            ->where('organization_id', $this->org['organization']->id)
            ->firstOrFail();

        $id = $this->as()
            ->withHeader('X-EzBizi-View', 'franchise:'.$franchise->id)
            ->postJson('/api/v1/automations', [
                'name' => 'Everywhere please',
                'trigger_key' => 'job.created',
                'actions' => [['type' => 'notify', 'message' => 'A job was made.']],
                'scope_type' => null,
                'scope_id' => null,
            ])->assertCreated()->json('data.id');

        $stored = Automation::withoutGlobalScopes()->findOrFail($id);

        $this->assertNull($stored->scope_type);
        $this->assertSame('Everywhere', collect(
            $this->as()->getJson('/api/v1/automations')->assertOk()->json('data')
        )->firstWhere('id', $id)['scope']['label']);
    }

    /**
     * ANOTHER PLACE'S RULE IS NOT ON YOUR SCREEN, and yours still is.
     *
     * This is the half Jose actually saw — he was looking at one company and
     * reading a rule he had written for another. A list showing every rule in
     * the business is not only noise: a franchise manager could switch off a
     * rule that runs for everybody, from a screen that gave no hint it was not
     * theirs.
     *
     * Asked through the SAME `coversScope` the engine asks, so the list cannot
     * drift from what actually runs. The organization view is deliberately NOT
     * filtered by it — see the comment there.
     */
    public function test_one_places_rule_does_not_appear_at_another(): void
    {
        $organization = $this->org['organization'];

        $mine = Franchise::withoutGlobalScopes()
            ->where('organization_id', $organization->id)->firstOrFail();

        $theirs = app(TenantContext::class)->run($organization, fn () => Franchise::create([
            'organization_id' => $organization->id,
            'company_id' => $mine->company_id,
            'name' => 'Second Franchise',
            'is_active' => true,
        ]));

        $here = $this->as()->withHeader('X-EzBizi-View', 'franchise:'.$mine->id)
            ->postJson('/api/v1/automations', [
                'name' => 'Mine',
                'trigger_key' => 'job.created',
                'actions' => [['type' => 'notify', 'message' => 'Here.']],
            ])->assertCreated()->json('data.id');

        $elsewhere = $this->as()->withHeader('X-EzBizi-View', 'franchise:'.$theirs->id)
            ->postJson('/api/v1/automations', [
                'name' => 'Theirs',
                'trigger_key' => 'job.created',
                'actions' => [['type' => 'notify', 'message' => 'There.']],
            ])->assertCreated()->json('data.id');

        $seenHere = collect(
            $this->as()->withHeader('X-EzBizi-View', 'franchise:'.$mine->id)
                ->getJson('/api/v1/automations')->assertOk()->json('data')
        )->pluck('id');

        // THE CONTROL: this person's own rule is still listed, so the filter is
        // narrowing by PLACE and not just hiding things.
        $this->assertContains($here, $seenHere);
        $this->assertNotContains($elsewhere, $seenHere);

        // And at the top, both — with the names that tell them apart.
        $atTheTop = collect(
            $this->as()->withHeader('X-EzBizi-View', 'organization')
                ->getJson('/api/v1/automations')->assertOk()->json('data')
        );

        $this->assertContains($here, $atTheTop->pluck('id'));
        $this->assertContains($elsewhere, $atTheTop->pluck('id'));
        $this->assertSame('Second Franchise', $atTheTop->firstWhere('id', $elsewhere)['scope']['label']);
    }

    /**
     * EVERY TEMPLATE MUST NAME A TRIGGER AND ACTIONS THAT REALLY EXIST.
     *
     * Jose, 2026-09-17: *"i want to have Templates as well, these will be
     * global, with basic actions ... we'll be adding more"*. The whole value is
     * that somebody presses one and gets a working rule, so a template naming a
     * trigger nothing raises, or an action the engine cannot run, is worse than
     * no template at all: it applies cleanly, looks configured, and then fails
     * at save or silently never fires.
     *
     * This is the guard for the ones added LATER, which is where it will
     * actually earn its keep — the five here were checked by hand once.
     */
    public function test_every_template_names_a_real_trigger_and_runnable_actions(): void
    {
        $registry = app(ModuleRegistry::class);

        $runnable = collect($this->as()->getJson('/api/v1/automations/catalogue')
            ->assertOk()->json('data.action_types'))->pluck('key')->all();

        foreach (\App\Support\Automations\AutomationTemplates::all() as $template) {
            $trigger = $registry->findTrigger($template['trigger_key']);

            $this->assertNotNull(
                $trigger,
                "Template {$template['key']} fires on {$template['trigger_key']}, which no module declares.",
            );

            $this->assertTrue(
                $trigger->isRaised,
                "Template {$template['key']} fires on {$template['trigger_key']}, which nothing ever raises, "
                .'so applying it would produce a rule that can never run.',
            );

            // A template that runs on a clock must carry a schedule the save
            // would accept, or applying it fills a form that then refuses.
            if ($template['trigger_key'] === 'automation.schedule_reached') {
                \App\Support\Automations\AutomationSchedule::assertValid($template['schedule'] ?? null);
            }

            foreach ($template['actions'] as $action) {
                // Asked of the CATALOGUE rather than of a private constant:
                // what the builder is told is runnable is what a template has
                // to stay inside.
                $this->assertContains(
                    $action['type'],
                    $runnable,
                    "Template {$template['key']} uses {$action['type']}, which the engine cannot perform.",
                );
            }
        }
    }

    /**
     * A TEMPLATE THIS PERSON COULD NOT BUILD IS NOT OFFERED.
     *
     * Applying one that names a module they cannot open would fill the form in
     * and then save into a 403, which reads as the app changing its mind rather
     * than as a permission.
     */
    public function test_templates_are_filtered_to_the_modules_in_reach(): void
    {
        $offered = collect($this->as()->getJson('/api/v1/automations/catalogue')
            ->assertOk()->json('data.templates'));

        // THE CONTROL: an administrator reaches everything, so the list is not
        // empty and the assertion below is about filtering rather than about a
        // key that is always absent.
        $this->assertNotEmpty($offered);

        $modules = collect($this->as()->getJson('/api/v1/automations/catalogue')
            ->assertOk()->json('data.modules'))->pluck('key');

        foreach ($offered as $template) {
            $this->assertNotNull($template['trigger_key']);
            $this->assertNotEmpty($template['actions']);
        }

        $this->assertNotEmpty($modules);
    }

    /**
     * EVERY RUNNABLE ACTION HAS A FORM THE BUILDER CAN FIND.
     *
     * The builder offers a module's action only when `action_types` carries a
     * key equal to that action's EXECUTION type — ActionDefinition derives it
     * from everything after the first dot. "Reply to the lead" was declared as
     * `lead.reply`, derived to `reply`, and had its form filed under
     * `lead.reply`: the arm was built and running, the server said implemented,
     * and the builder greyed it out as "Not built yet" for as long as it
     * existed. Jose found it, not a test.
     *
     * The existing drift guard compares the runner's arms with
     * IMPLEMENTED_ACTIONS, which were both right. This asserts the third list —
     * the one the client actually reads — and it asks the client's own
     * question, so it cannot pass while the screen disagrees.
     */
    public function test_every_runnable_action_has_a_form_under_its_own_type(): void
    {
        $catalogue = $this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data');

        $formKeys = collect($catalogue['action_types'])->pluck('key')->all();

        $unreachable = [];

        foreach ($catalogue['modules'] as $module) {
            foreach ($module['declared_actions'] as $action) {
                if (($action['implemented'] ?? false) && ! in_array($action['type'], $formKeys, true)) {
                    $unreachable[] = "{$action['key']} (type {$action['type']})";
                }
            }
        }

        $this->assertSame([], $unreachable,
            'Built and running, but the builder greys these out as "Not built yet": '
            .'action_types has no key equal to their execution type.');
    }

    /**
     * Franchises are named after territories, so every company had its own
     * "Connecticut" and the picker listed eight identical rows (Mia,
     * 2026-10-02). Each now says whose it is.
     */
    public function test_a_franchise_in_the_place_picker_names_its_company(): void
    {
        $orgId = $this->org['organization']->id;

        app(TenantContext::class)->run($this->org['organization'], function () use ($orgId) {
            foreach (['Chimney Co', 'Garage Co'] as $name) {
                $company = \App\Models\Company::create(['organization_id' => $orgId, 'name' => $name]);
                Franchise::create(['organization_id' => $orgId, 'company_id' => $company->id, 'name' => 'Connecticut']);
            }
        });

        $labels = collect($this->as()->getJson('/api/v1/automations/catalogue')->assertOk()->json('data.places'))
            ->pluck('label');

        $this->assertContains('Connecticut — Chimney Co', $labels);
        $this->assertContains('Connecticut — Garage Co', $labels);
        $this->assertNotContains('Connecticut', $labels);
    }

    /**
     * A copy carries the live version's steps and conditions, and is OFF even
     * when the original is live, so the two cannot both send before somebody
     * points the copy somewhere else (Mia, 2026-10-02).
     */
    public function test_a_copied_rule_keeps_its_steps_and_starts_switched_off(): void
    {
        $original = $this->as()->postJson('/api/v1/automations', [
            'name' => 'Greet chats',
            'trigger_key' => 'job.status_changed',
            'conditions' => ['all' => [['field' => 'status', 'op' => 'eq', 'value' => 'completed']]],
            'actions' => [['type' => 'notify', 'message' => 'Job {{ title }} is done']],
        ])->assertCreated()->json('data');

        $this->as()->patchJson("/api/v1/automations/{$original['id']}", ['is_active' => true])->assertOk();

        $copy = $this->as()->postJson("/api/v1/automations/{$original['id']}/duplicate")
            ->assertCreated()
            ->json('data');

        $this->assertNotSame($original['id'], $copy['id']);
        $this->assertSame('Greet chats (copy)', $copy['name']);
        $this->assertFalse($copy['is_active']);
        $this->assertSame(1, $copy['version']);
        $this->assertSame($original['trigger_key'], $copy['trigger_key']);
        $this->assertSame($original['conditions'], $copy['conditions']);
        $this->assertSame($original['actions'], $copy['actions']);

        $this->assertTrue(Automation::query()->withoutGlobalScopes()->findOrFail($original['id'])->is_active);
    }
}
