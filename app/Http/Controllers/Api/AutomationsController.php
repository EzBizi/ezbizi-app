<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\ActionRunner;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\AutomationSchedule;
use App\Support\Automations\CarriesAutomationScope;
use App\Support\Automations\OutboundWebhook;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\PermissionCatalogue;
use App\Support\Modules\TriggerDefinition;
use App\Support\Privacy\SensitiveValues;
use App\Support\Settings\SettingRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant-authored automation (plan §5.5, §2.1).
 *
 * An engine nobody can configure is not the no-hardcoding rule — it is a
 * hardcoded engine. This is the surface that makes it real: an organization
 * builds its own rules from the triggers and actions the modules declare, and
 * never asks for a deployment to do it.
 *
 * Editing writes a NEW VERSION and repoints the automation. Nothing here can
 * change a version in place, because every run recorded which one it executed.
 */
class AutomationsController extends Controller
{
    /**
     * How many recent events `triggerFields` reads to learn a trigger's shape.
     *
     * Enough that a payload key added recently shows up, small enough that the
     * picker is one indexed read. Not a page size — nothing is returned per
     * event, only the union of their keys.
     */
    private const FIELD_SAMPLE = 200;

    /**
     * The one trigger that is a clock rather than something happening (§5.5).
     *
     * Named once because three places ask the same question — the save check,
     * the schedule column and the sweep — and a literal repeated three times is
     * how two of them come to disagree.
     */
    public const CLOCK_TRIGGER = 'automation.schedule_reached';

    /** What ActionRunner can actually perform. The validator agrees with this. */
    private const IMPLEMENTED_ACTIONS = [
        'update_field',
        'change_status',
        'webhook',
        'notify',
        // Nested Phone keys: ActionDefinition::type() keeps everything after the
        // first dot (voicemail.mark_dealt, missed.create_job).
        'voicemail.mark_dealt',
        'missed.create_job',

        // Email's, three segments in the module so it does not arrive here as a
        // bare `mark_dealt` in the namespace every module shares.
        'message.mark_dealt',
        'email.send',
        'whatsapp.send',

        // Not subject-bound: it exists so a rule on something with no phone in
        // it — an email arriving — can still send a text, so the line and the
        // destination come from the rule rather than the record.
        'sms.send',
        // Spelled out in the module too — a two-segment key would otherwise
        // execute as a bare `clock_in` in a namespace every module shares.
        'time_clock.clock_in',
        'time_clock.clock_out',

        /*
         * Inspection's, added by Inspection with its arm already in place.
         *
         * `ActionRunner::emailInspectionReport` renders the frozen report and
         * sends it to the customer through MailDispatcher::asTenant. Without
         * this line the arm existed and the builder drew the action "Not built
         * yet" and refused to select it — declared, executable, tested, and
         * unreachable, which is the QUIET direction of this const going wrong.
         * `training.assign` once had the opposite shape, const without arm, and
         * that announced itself: every run recorded FAILED. Caught by the
         * Training session reading main, not by any test.
         *
         * Spelled `inspection_report.email` rather than the `report.email` the
         * key derives to, because Reports declares `report.send` labelled
         * "Email a report" and the two would be one rename apart.
         */
        'inspection_report.email',
        /*
         * `ActionRunner::assignTraining` (task 9) is the real arm — the same
         * `App\Support\Training\AssignmentWriter` a person's own POST
         * /training/assignments calls, so a rule reaches no further, and
         * needs no more PERMISSION, than the rule's own creator would by
         * hand: `AssignmentWriter::create` checks `training.assign` itself,
         * first, before anything else — not merely §4 reach. (Round-1 review
         * found this entry's earlier wording true of reach and silently
         * false of permission: `training.assign` was checked only in
         * `AssignmentsController::store`, so a rule using this action ran
         * with no permission check at all. Fixed by moving the gate into
         * `AssignmentWriter::create`, the one path both take — see its own
         * docblock — rather than by adding a second check here.)
         *
         * Spelled out here for the same reason time_clock is spelled out
         * above: the module declares executionType: 'training.assign', or
         * this entry would never match the bare `assign` that type() would
         * default to.
         */
        'training.assign',


        /*
         * ── A GENERAL "EMAIL SOMEBODY" ACTION IS NOT HERE, AND IS NOT
         *    WAITING ON A DECISION ─────────────────────────────────────
         *
         * `inspection_report.email` above is Inspection's and narrow: it emails one
         * inspection report to the customer that report is about. §5.5 lists
         * "Send email" among the six decided actions and the general one — a
         * rule emailing a recipient it names — is still unbuilt.
         *
         * IT IS OWNED, NOT BLOCKED. Jose ruled on 2026-09-14, asked whether a
         * training assignment should email the employee: "it should be an
         * option to be enabled on automations, never hardcoded." So the shape
         * is settled — a rule a company builds and switches on, rather than a
         * send path wired into Training, HR or anywhere else. The Email module
         * took the work the same day, by the `sms.send` precedent that the
         * module owning the capability writes its own block here.
         *
         * Written down because the ownership lives ONLY in messages between
         * sessions — it is in no file, no MODULE-OWNERS row and no agent mail,
         * checked. Sessions here turn over in minutes, and a decision that
         * exists only in a conversation is one restart from being re-asked.
         *
         * This session put that question to Jose six times across three days
         * while holding the work, because the hold outlived its reason and
         * nothing announces when a reason goes. If you are about to ask him
         * whether automations may email: he has answered, the answer is yes,
         * and it is not yours to build.
         */
    ];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AutomationEngine $engine,
        private readonly OutboundWebhook $webhook,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly PermissionCatalogue $permissions,
        private readonly ScopeReach $reach,
    ) {}

    /**
     * GET /api/v1/automations/catalogue
     *
     * What can be built. Comes from the module registry rather than a list kept
     * here, so a module added next year appears the moment it declares itself —
     * which is the §5.5 standing rule doing its job.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Automation::class);

        /*
         * A MODULE IS ONLY IN THE CATALOGUE IF THIS PERSON CAN REACH ITS TAB.
         *
         * The tab is the view permission (§4), so "may build rules on Human
         * Resources" is "may open Human Resources" — and this endpoint returns
         * the wording modules write for themselves, which is real information
         * about the business. An HR trigger list tells somebody with no HR
         * permission that contracts are signed here, that invitations lapse
         * after a week and that amendments carry a reason.
         *
         * `automations.view` alone used to be enough to see all of it. That was
         * survivable while the catalogue held customers and jobs, which is the
         * work of a business rather than its private administration; it stopped
         * being survivable the moment HR declared anything.
         *
         * `heldBy` and not the role's raw keys: it drops modules the PLATFORM
         * has switched off for this organization as well, which is the other way
         * a module can be unreachable and the one nobody remembers.
         */
        $reachable = $this->modulesInReach($request);

        $modules = [];

        foreach ($this->registry->all() as $key => $module) {
            if ($module->triggers === [] && $module->automationActions === []) {
                continue;
            }

            // The same rule the SAVE path applies — see assertTriggerExists for
            // what happened while these were two separate checks.
            if (! in_array($key, $reachable, true)) {
                continue;
            }

            $modules[] = [
                'key' => $key,
                'label' => $module->label,
                /*
                 * Where the module is drawn in the app (ModuleDefinition::$parent),
                 * so the builder's pickers group events the way the person
                 * writing the rule just navigated to them. Somebody who found
                 * Leads as a tab inside Marketing should not have to look for a
                 * separate Leads heading here.
                 *
                 * The key, not a composed label: the builder already holds every
                 * module and can read the parent's own wording off the same
                 * list, and a second label composed here would be a second
                 * chance to disagree with it.
                 */
                'parent' => $module->parent,
                /*
                 * The RECORD these events are about, and what a rule may set on
                 * it.
                 *
                 * It belongs to the module rather than to the actions, because
                 * that is where the fact lives: `ActionRunner::updateField`
                 * writes to the subject of the EVENT, so the trigger decides the
                 * field list and the action has no say in it. Hanging the list
                 * off `update_field` would put the same answer under two module
                 * actions that can disagree with the one trigger — and would
                 * leave the generic "Update a field", which belongs to no
                 * module at all, with nowhere to read it from.
                 *
                 * The fields come from ActionRunner, the class that enforces
                 * them. A copy assembled here would be a second allowlist, and
                 * the whole reason the field was a free-text box is that the
                 * only list lived somewhere the form could not see.
                 */
                'subject' => $this->subjectPayload($module),
                /*
                 * Words, not wire keys.
                 *
                 * This used to be a bare list of strings, and the builder drew
                 * exactly that: twenty rows reading `organization.settings_changed`
                 * with nothing to say when one fires or how it differs from the
                 * row above. The label and the description come from the module
                 * that raises the event (§5.5) — the client is not expected to
                 * keep a dictionary, because a dictionary in the client is a
                 * second place to forget.
                 */
                'triggers' => array_map(fn (TriggerDefinition $t) => [
                    'key' => $t->key,
                    'label' => $t->label,
                    'description' => $t->description,
                    /*
                     * Whether anything actually emits this yet — as a FLAG, not
                     * only as prose.
                     *
                     * `TriggerDefinition` already appends "Nothing raises this
                     * yet, so a rule built on it will not run" to the
                     * description, and that sentence says the consequence,
                     * which a badge cannot. But it arrives as the tail of a
                     * paragraph, so the row carrying it is indistinguishable
                     * from the twenty around it until you read to the end of
                     * one. A rule built on such a trigger saves, sits enabled,
                     * and never runs — no error, ever.
                     *
                     * That is the silent-failure class this app already has a
                     * vocabulary for; the same argument put five phone lines
                     * into voicemail for months behind grey secondary text.
                     * Sending the flag lets the picker mark the row the way it
                     * already marks an action the engine cannot run.
                     *
                     * Raised by the UI/UX session, 2026-09-08. Additive: an
                     * older client ignores the key and keeps the sentence,
                     * which is why this could ship without waiting for one.
                     */
                    'is_raised' => $t->isRaised,
                ], $module->triggers),
                /*
                 * Marked, not merely listed.
                 *
                 * A module declares the actions it INTENDS to expose (the §5.5
                 * standing rule makes that mandatory at the moment the module is
                 * written). The engine implements a smaller generic set. Listing
                 * them together without saying which is which advertised action
                 * names that POST /automations then rejected — the catalogue
                 * and the validator were two different vocabularies.
                 *
                 * `implemented` is HALF of that distinction, and the wording
                 * beside it has to stand on its own: the description says what
                 * the action would do, and never that it is missing. A client
                 * renders "not built yet" from the flag and the sentence still
                 * reads correctly on the day it flips.
                 *
                 * The other half is not answerable here. Whether an action
                 * applies at all depends on the TRIGGER of the rule being
                 * written — "Customers › Update a field" means nothing under a
                 * job trigger, because the engine writes to the event's subject
                 * — and the catalogue is asked before any rule exists. So it
                 * publishes `subject` above and lets the builder compare the
                 * two, and the save-time check refuses the mismatch for real.
                 */
                'declared_actions' => array_map(function (ActionDefinition $a) {
                    $implemented = in_array($a->type(), self::IMPLEMENTED_ACTIONS, strict: true);

                    return [
                        'key' => $a->key,
                        'label' => $a->label,
                        /*
                         * The warning goes IN the sentence, not only beside it.
                         *
                         * `implemented` below is the machine-readable half and
                         * the builder draws it correctly — an unbuilt action is
                         * unselectable with a "Not built yet" badge. But a
                         * DESCRIPTION is what a person reads, and it read as a
                         * live capability: `report.send` promises to email a
                         * saved report and has no arm in ActionRunner.
                         *
                         * Same fix the triggers already had. See
                         * ActionDefinition::NOT_BUILT_YET for the incident.
                         */
                        'description' => $implemented
                            ? $a->description
                            : rtrim($a->description).' '.ActionDefinition::NOT_BUILT_YET,
                        'type' => $a->type(),
                        'subject_bound' => $a->subjectBound,
                        'implemented' => $implemented,
                    ];
                }, $module->automationActions),
            ];
        }

        return response()->json([
            'data' => [
                'modules' => $modules,
                // Fixed in code, deliberately: a runtime-editable operator set is
                // arbitrary expressions over arbitrary fields (§2.1 category 1).
                'operators' => [
                    ['key' => 'eq', 'label' => 'is'],
                    ['key' => 'neq', 'label' => 'is not'],
                    ['key' => 'in', 'label' => 'is one of'],
                    ['key' => 'not_in', 'label' => 'is not one of'],
                    ['key' => 'gt', 'label' => 'is greater than'],
                    ['key' => 'gte', 'label' => 'is at least'],
                    ['key' => 'lt', 'label' => 'is less than'],
                    ['key' => 'lte', 'label' => 'is at most'],
                    ['key' => 'contains', 'label' => 'contains'],
                    ['key' => 'not_contains', 'label' => 'does not contain'],
                    ['key' => 'is_empty', 'label' => 'is empty'],
                    ['key' => 'is_not_empty', 'label' => 'is not empty'],
                    ['key' => 'changed', 'label' => 'changed'],
                    ['key' => 'changed_to', 'label' => 'changed to'],
                    ['key' => 'changed_from', 'label' => 'changed from'],
                ],
                // The vocabulary POST /automations accepts. Anything not here is
                // declared intent, not a usable action.
                'action_types' => $this->actionTypes(),
                /*
                 * The zones a schedule may be written in, from the one list that
                 * defines them.
                 *
                 * Published rather than hardcoded in the client for the reason
                 * every closed list in this application is: a second copy is a
                 * second thing to forget. SettingRegistry already owns this one
                 * — twelve real places, plus every whole-hour offset — and the
                 * settings screen draws its own selector from the same source.
                 *
                 * A rule's zone is NOT read from the organization's timezone
                 * setting, which is a different question. That setting says how
                 * to DISPLAY a time and defaults to `Etc/GMT+5`, a fixed offset
                 * with no daylight saving; scheduling against it would run an
                 * hour off local civil time for eight months of the year. The
                 * real places are in this list so somebody can choose one.
                 */
                'timezones' => $this->timezoneChoices(),
                'schedule_kinds' => [
                    ['key' => AutomationSchedule::DAILY, 'label' => 'Every day'],
                    ['key' => AutomationSchedule::WEEKLY, 'label' => 'Days of the week'],
                    ['key' => AutomationSchedule::MONTHLY, 'label' => 'Once a month'],
                ],
                'limits' => [
                    'max_depth' => AutomationEvent::MAX_DEPTH,
                    'action_budget' => AutomationEvent::ACTION_BUDGET,
                ],
            ],
        ]);
    }

    /**
     * A module's subject, with the fields a rule may set on it.
     *
     * `writable_fields` is empty rather than absent for a subject nothing is
     * writable on — a company, a user. Empty is an answer ("a rule can react to
     * this, but it cannot change it"); a missing key is indistinguishable from
     * an older server, and the client would have to guess which.
     */
    /**
     * GET /automations/trigger-fields?trigger=email.received
     *
     * The fields a condition can actually read on this trigger, so the builder
     * can offer them instead of asking somebody to guess.
     *
     * ── Why these are OBSERVED rather than declared ──────────────
     *
     * The obvious design was a field list on TriggerDefinition beside the label
     * and description. It was rejected for two reasons and the second is the
     * one that decided it.
     *
     * There are 107 triggers across 21 modules, and their blocks in
     * ModuleServiceProvider belong to those modules — so a declared list means
     * editing twenty other sessions' code in the one file this repo already
     * treats as its sharpest edge.
     *
     * And a declaration can LIE. The payload keys are written at the emit call
     * site, not at the declaration, so the two drift the moment somebody adds a
     * key and does not come back here — which is precisely the failure this
     * endpoint exists to stop, rebuilt one level up and harder to notice.
     * Reading the events themselves cannot drift: it IS what arrived.
     *
     * The keys are gathered the same way ConditionEvaluator::valueOf resolves
     * them — `after` first, then the top level — so the picker offers exactly
     * what a condition can read, and cannot offer something that would evaluate
     * to null.
     *
     * ── What it deliberately does not return ─────────────────────
     *
     * KEYS ONLY, NO VALUES. A sample would be far more helpful — knowing that
     * `from_address` looks like `notify@ringcentral.com` is most of the battle
     * — and it is exactly what must not be returned. Payload values are the
     * subject line a customer wrote and the number they called from. Reach here
     * is `automations.view` plus the module's tab, which is not the same as
     * permission to read one branch's mail, and sampling values would hand
     * whoever opens the builder a window into every inbox in the organization.
     * Keys are structural; values are the data.
     *
     * A trigger that has never fired here returns nothing, and says so rather
     * than pretending the trigger carries no fields.
     */
    public function triggerFields(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Automation::class);

        $key = trim((string) $request->query('trigger', ''));

        if ($this->registry->findTrigger($key) === null) {
            throw ValidationException::withMessages([
                'trigger' => "No module raises '{$key}', so nothing can be built on it.",
            ]);
        }

        // The same gate the catalogue applies: may this person open the tab the
        // event comes from. Without it, asking about a trigger by name would
        // report which fields Human Resources events carry to somebody with no
        // HR permission.
        $moduleKey = $this->registry->moduleKeyOfTrigger($key);

        abort_unless(
            in_array($moduleKey, $this->modulesInReach($request), true),
            403,
            Refusal::permission('see what this event carries')->message(),
        );

        $events = AutomationEvent::query()
            ->where('event_key', $key)
            ->latest('occurred_at')
            ->limit(self::FIELD_SAMPLE)
            ->pluck('payload');

        $fields = [];

        foreach ($events as $payload) {
            $payload = is_array($payload) ? $payload : [];

            foreach (array_keys($payload['after'] ?? []) as $field) {
                $fields[$field] = true;
            }

            foreach (array_keys($payload) as $field) {
                // `before` and `after` are the envelope, not fields. A condition
                // on either name would read an array and compare as JSON.
                if ($field !== 'before' && $field !== 'after') {
                    $fields[$field] = true;
                }
            }
        }

        $fields = array_keys($fields);
        sort($fields);

        /*
         * ADDITIVE, keyed by field, and only for fields that have a closed set.
         *
         * `fields` stays a flat list of strings so a client that has not been
         * updated is unaffected - this is a map beside it, not a change of shape.
         * It answers the half of "people cannot guess them around" that the
         * field picker left open: knowing the field is `source` does not tell
         * anybody that the value they must type is `website_chat`.
         *
         * Values are DECLARED, never sampled. Reading them off recent events
         * would leak what this tenant's records contain, and this endpoint has
         * never returned a value - only which keys exist.
         */
        $values = [];

        foreach (ActionRunner::valuesOn($this->registry->get($moduleKey)?->subject?->model) as $field => $set) {
            if (! in_array($field, $fields, true)) {
                continue;
            }

            $values[$field] = array_map(
                fn (string $value, string $label) => ['value' => $value, 'label' => $label],
                array_keys($set),
                array_values($set),
            );
        }

        return response()->json(['data' => [
            'trigger' => $key,
            'fields' => $fields,
            'values' => $values,
            // So the builder can tell "this trigger carries nothing" apart from
            // "this trigger has not happened here yet", which are different
            // things to say to somebody stuck.
            'sampled' => $events->count(),
        ]]);
    }

    /**
     * A clock trigger needs a schedule, and a schedule needs the clock trigger.
     *
     * Both halves are refused at SAVE rather than discovered later, because
     * both failures are silent. A rule on `automation.schedule_reached` with no
     * schedule has a null `next_run_at`, which is exactly what the sweep uses to
     * skip a rule — so it saves, switches on, looks live and never runs. A
     * schedule attached to an event trigger is the mirror image: the time is
     * stored, nothing ever reads it, and the author is left believing their
     * "when a job completes" rule also runs every Monday.
     *
     * The shape of the schedule itself is AutomationSchedule's to judge; its
     * refusals are already written for a person ("A weekly schedule needs at
     * least one day of the week, or it never comes round") so they are passed
     * through rather than restated here.
     */
    private function assertScheduleMatchesTrigger(array $input, Request $request): void
    {
        $trigger = $input['trigger_key']
            ?? $request->route('automation')?->trigger_key;

        $scheduled = $trigger === self::CLOCK_TRIGGER;
        $schedule = $input['schedule'] ?? null;

        if ($scheduled && ! is_array($schedule)) {
            throw ValidationException::withMessages([
                'schedule' => 'A rule that runs on a clock needs a time to run at.',
            ]);
        }

        if (! $scheduled && $schedule !== null) {
            throw ValidationException::withMessages([
                'schedule' => 'Only a rule that runs on a clock has a schedule. '
                    .'This one runs when something happens.',
            ]);
        }

        if ($schedule !== null) {
            try {
                AutomationSchedule::assertValid($schedule);
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['schedule' => $e->getMessage()]);
            }
        }
    }

    /**
     * The zones a schedule may be written in, read off the settings registry.
     *
     * Through the PUBLIC definition (`get('timezone')`) rather than the private
     * label table behind it, because SettingRegistry belongs to the Settings
     * module and this is a read from another module's file. Its `options` and
     * `optionLabels` are what the settings screen's own selector draws, so the
     * two pickers cannot disagree about what a zone is called.
     *
     * @return array<int, array{id: string, label: string}>
     */
    private function timezoneChoices(): array
    {
        $definition = app(SettingRegistry::class)->get('timezone');

        if ($definition === null) {
            return [];
        }

        return array_values(array_map(
            fn (string $id) => [
                'id' => $id,
                'label' => $definition->optionLabels[$id] ?? $id,
            ],
            $definition->options,
        ));
    }

    private function subjectPayload(ModuleDefinition $module): ?array
    {
        if ($module->subject === null) {
            return null;
        }

        $writable = ActionRunner::writableOn($module->subject->model);

        return [
            'key' => $module->subject->key(),
            'label' => $module->subject->label,
            // Keys AND names. "scheduled_for" is a wire name, and the person
            // reading this picker runs a chimney business.
            'writable_fields' => array_map(
                fn (string $field, string $label) => ['key' => $field, 'label' => $label],
                array_keys($writable),
                array_values($writable),
            ),
        ];
    }

    /** GET /api/v1/automations */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Automation::class);

        $automations = Automation::query()
            ->with('activeVersion')
            ->withCount(['runs'])
            ->orderBy('name')
            ->get()
            ->map(fn (Automation $a) => $this->present($a));

        return response()->json(['data' => $automations]);
    }

    /**
     * Another tenant's id is NOT FOUND, never forbidden.
     *
     * The tenant scope should already make route binding fail for a record from
     * another organization, and for customers and jobs it does. It did not here,
     * and I have not isolated why — so rather than trust it, this says so
     * outright.
     *
     * The distinction matters: 403 confirms the id is real somewhere on the
     * platform and 404 does not, which is the same existence oracle the `exists`
     * validation rules had.
     */
    private function assertMine(Automation $automation): void
    {
        abort_unless($automation->organization_id === $this->tenant->idOrFail(), 404);
    }

    /** GET /api/v1/automations/{automation} */
    public function show(Automation $automation): JsonResponse
    {
        $this->assertMine($automation);
        $this->authorize('view', $automation);

        return response()->json(['data' => $this->present($automation, detailed: true)]);
    }

    /** POST /api/v1/automations */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Automation::class);

        $input = $this->validated($request);

        $automation = DB::transaction(function () use ($input, $request) {
            $automation = Automation::create([
                'name' => $input['name'],
                'description' => $input['description'] ?? null,
                'trigger_key' => $input['trigger_key'],
                'scope_type' => $input['scope_type'] ?? null,
                'scope_id' => $input['scope_id'] ?? null,
                // Off until somebody switches it on. A rule that starts firing
                // the instant it is saved will fire before it has been read back.
                'is_active' => false,
                'created_by' => $request->user()->getKey(),
                'schedule' => $input['schedule'] ?? null,
                /*
                 * The first occurrence is computed at SAVE, not at first sweep.
                 *
                 * A null next_run_at is what the sweep uses to skip a rule, so
                 * leaving it for the worker to fill in would mean the sweep had
                 * to read every scheduled rule in every organization once a
                 * minute to find the ones it had not seen before. One
                 * computation here, and the index answers the rest.
                 *
                 * From NOW, so a schedule typed at 9:05 for "daily at 09:00"
                 * starts tomorrow rather than firing the moment it is switched
                 * on for a time that has already passed today.
                 */
                'next_run_at' => isset($input['schedule'])
                    ? (new AutomationSchedule($input['schedule']))->nextAfter(now())
                    : null,
            ]);

            $this->addVersion($automation, $input, $request);

            $this->audit->log('automation.created', $automation, metadata: [
                'trigger' => $automation->trigger_key,
            ]);

            return $automation;
        });

        return response()->json(
            ['data' => $this->present($automation->fresh(), detailed: true)],
            Response::HTTP_CREATED
        );
    }

    /**
     * PATCH /api/v1/automations/{automation}
     *
     * Writes a new version rather than editing the live one. The previous
     * version stays exactly as it was, because runs point at it.
     */
    public function update(Request $request, Automation $automation): JsonResponse
    {
        $this->assertMine($automation);
        $this->authorize('update', $automation);

        // The automation itself, because an action's legality depends on the
        // trigger and a PATCH is forbidden from carrying one.
        $input = $this->validated($request, existing: $automation);

        DB::transaction(function () use ($automation, $input, $request) {
            $automation->fill(array_intersect_key($input, array_flip([
                'name', 'description', 'scope_type', 'scope_id',
            ])));

            /*
             * A retimed rule gets a new next run, computed from NOW.
             *
             * Without this the row keeps the instant the OLD time resolved to,
             * so somebody who moves a report from 9am to 6am watches it arrive
             * at 9am once more and reasonably concludes the edit did not save.
             *
             * From now rather than from the old next run, for the same reason
             * the sweep advances from now: a rule moved from 6am to 9am must
             * not fire twice today.
             */
            if (array_key_exists('schedule', $input)) {
                $automation->schedule = $input['schedule'];
                $automation->next_run_at = $input['schedule'] === null
                    ? null
                    : (new AutomationSchedule($input['schedule']))->nextAfter(now());
            }

            // Re-enabling clears the auto-disable, which is what makes it a
            // deliberate act rather than something that quietly heals.
            if (($input['is_active'] ?? false) === true) {
                $automation->forceFill([
                    'is_active' => true,
                    'disabled_at' => null,
                    'disabled_reason' => null,
                    'consecutive_failures' => 0,
                ]);
            } elseif (array_key_exists('is_active', $input)) {
                $automation->is_active = false;
            }

            $this->audit->logChange('automation.updated', $automation);
            $automation->save();

            if (isset($input['actions'])) {
                $this->addVersion($automation, $input, $request);
            }
        });

        return response()->json(['data' => $this->present($automation->fresh(), detailed: true)]);
    }

    /**
     * POST /api/v1/automations/{automation}/activate/{version}
     *
     * Rollback, and the reason versions are immutable (§5.5). Pointing back at
     * an older definition is the whole recovery path when a new rule turns out
     * to be wrong.
     */
    public function activateVersion(Request $request, Automation $automation, AutomationVersion $version): JsonResponse
    {
        $this->assertMine($automation);
        $this->authorize('update', $automation);

        abort_unless($version->automation_id === $automation->getKey(), 404);

        $previous = $automation->active_version_id;

        $automation->forceFill(['active_version_id' => $version->getKey()])->save();

        $this->audit->log('automation.version_activated', $automation, metadata: [
            'from' => $previous,
            'to' => $version->getKey(),
            'version' => $version->version,
        ]);

        return response()->json(['data' => $this->present($automation->fresh(), detailed: true)]);
    }

    /**
     * POST /api/v1/automations/{automation}/test
     *
     * Rehearse against a real past event (§5.5 test mode). The conditions are
     * evaluated for real — that is the part people get wrong — and the actions
     * report what they WOULD do without doing it.
     */
    public function test(Request $request, Automation $automation): JsonResponse
    {
        $this->assertMine($automation);
        $this->authorize('update', $automation);

        $input = $request->validate([
            'event_id' => ['nullable', 'uuid'],
        ]);

        $event = $input['event_id'] ?? null
            ? AutomationEvent::findOrFail($input['event_id'])
            : AutomationEvent::where('event_key', $automation->trigger_key)
                ->latest('occurred_at')
                ->first();

        if ($event === null) {
            return response()->json([
                'message' => "Nothing has triggered '{$automation->trigger_key}' yet, so there "
                    .'is no real event to try this against. Create or change a record first.',
                'code' => 'no_event_to_test',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $this->engine->rehearse($automation, $event)]);
    }

    /** GET /api/v1/automations/{automation}/runs */
    public function runs(Request $request, Automation $automation): JsonResponse
    {
        $this->assertMine($automation);
        $this->authorize('view', $automation);

        // Jobs are never shared (§5.2), so a run log that shows every franchise's
        // work would hand a franchise-scoped user exactly what JobPolicy refuses
        // them — including the field values in the condition trace.
        $reachable = $request->user()->reachableFranchises()->pluck('id')->all();

        $runs = AutomationRun::query()
            ->where('automation_id', $automation->getKey())
            ->with('event:id,event_key,subject_type,subject_id,occurred_at')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('started_at')
            ->limit(100)
            ->get()
            ->filter(fn (AutomationRun $r) => $this->maySeeSubjectOf(
                $r, $request->user(), $reachable, $this->modulesInReach($request),
            ))
            ->values()
            ->map(fn (AutomationRun $r) => [
                'id' => $r->id,
                'status' => $r->status,
                'is_test' => $r->is_test,
                'event' => [
                    'key' => $r->event?->event_key,
                    'occurred_at' => $r->event?->occurred_at?->toIso8601String(),
                ],
                // The trace is the point. "It did not match" without saying which
                // clause failed is not an answer to anyone debugging a rule.
                'condition_trace' => $r->condition_trace,
                'action_results' => $r->action_results,
                'error' => $r->error,
                'started_at' => $r->started_at->toIso8601String(),
            ]);

        return response()->json(['data' => $runs]);
    }

    /**
     * Can this caller see the record a run acted on?
     *
     * ── It used to name Job, and only Job ────────────────────────
     *
     * The guard read `if ($type !== Job::class) return true;` — everything that
     * was not a job was visible to anybody who could open the rule. That was
     * defensible while the only two subjects were jobs and customers, because a
     * customer IS shared organization-wide. It stopped being defensible the
     * moment Human Resources started emitting, and the failure is not subtle:
     * `condition_trace` carries `actual`, the real value read off the payload,
     * so a condition on `email` puts an invitee's address in the run log.
     *
     * Both halves were measured:
     *
     *   - a role with `automations.view` and NO HR permission, refused
     *     `GET /hr/invitations` with 403, read an invitee's address out of
     *     `/automations/{id}/runs`.
     *   - a branch manager at F2, holding `hr.view` legitimately, read F1's new
     *     starter out of an organization-wide rule's log.
     *
     * So the default is inverted. A subject is hidden unless something can say
     * the caller may see it — the same fail-closed direction TenantScope takes,
     * and for the same reason: the next subject type somebody adds inherits
     * safety rather than exposure.
     *
     * The two questions are different and both are asked. MAY THEY OPEN THE
     * MODULE at all, which is the permission; and DOES THEIR REACH COVER THIS
     * RECORD, which is §4's authority and is what separates two branch managers
     * holding identical permissions.
     *
     * A run whose subject has been DELETED is still shown. There is nothing
     * left to leak, and hiding it would make the log lie about what happened —
     * that reasoning was right before and is unchanged.
     */
    private function maySeeSubjectOf(
        AutomationRun $run,
        User $actor,
        array $reachable,
        array $modules,
    ): bool {
        $type = $run->event?->subject_type;

        // No subject at all — an organization-level event like a setting
        // changing. Nothing to leak and nothing to check.
        if ($type === null) {
            return true;
        }

        if (! in_array($this->moduleOfTrigger((string) $run->event?->event_key), $modules, true)) {
            return false;
        }

        $subject = class_exists($type)
            ? $type::query()->find($run->event->subject_id)
            : null;

        if ($subject === null) {
            return true;
        }

        if ($subject instanceof CarriesAutomationScope) {
            [$scopeType, $scopeId] = $subject->automationScope();

            // Organization-wide, and they are in the organization by being here.
            return $scopeType === null
                || $this->reach->covers($actor, $scopeType, $scopeId);
        }

        $franchiseId = $subject->getAttribute('franchise_id')
            ?? $subject->getAttribute('owning_franchise_id');

        // Shared organization-wide — a customer. Visible to anybody who got
        // this far, which is now anybody holding the module's view permission.
        return $franchiseId === null
            || in_array($franchiseId, $reachable, strict: true);
    }

    /** DELETE /api/v1/automations/{automation} */
    public function destroy(Automation $automation): JsonResponse
    {
        $this->assertMine($automation);
        $this->authorize('delete', $automation);

        DB::transaction(function () use ($automation) {
            $this->audit->log('automation.deleted', $automation);
            // Soft delete: the runs reference it, and a run pointing at a rule
            // that no longer exists is unreadable.
            $automation->delete();
        });

        return response()->json(['message' => 'Automation deleted.']);
    }

    // ── shared ───────────────────────────────────────────────────

    private function addVersion(Automation $automation, array $input, Request $request): void
    {
        $next = ($automation->versions()->max('version') ?? 0) + 1;

        $version = AutomationVersion::create([
            'automation_id' => $automation->getKey(),
            'version' => $next,
            'conditions' => $input['conditions'] ?? null,
            'actions' => $input['actions'],
            'created_by' => $request->user()->getKey(),
            'created_at' => now(),
        ]);

        $automation->forceFill(['active_version_id' => $version->getKey()])->save();
    }

    /**
     * @param  Automation|null  $existing  the rule being edited, or null for a new one
     */
    private function validated(Request $request, ?Automation $existing = null): array
    {
        $forUpdate = $existing !== null;
        $required = $forUpdate ? 'sometimes' : 'required';

        $input = $request->validate([
            'name' => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'trigger_key' => $forUpdate
                // Changing the trigger makes it a different rule, and every run
                // recorded so far would describe something it no longer does.
                ? ['missing']
                : ['required', 'string', 'max:100'],
            // A schedule is only meaningful on the clock trigger, and only the
            // clock trigger is meaningless without one. Both halves are checked
            // below rather than here, because the rule is about the PAIR.
            'schedule' => ['nullable', 'array'],
            // 'company' is accepted because the engine resolves it (see
            // AutomationEngine::scopeOf). It previously validated as legal
            // and then could never match anything, which is worse than
            // refusing it.
            'scope_type' => ['nullable', Rule::in(['company', 'franchise'])],
            'scope_id' => ['nullable', 'uuid', 'required_with:scope_type'],
            'is_active' => ['boolean'],
            'conditions' => ['nullable', 'array'],
            // Capped. One version with two hundred actions would spend the whole
            // cascade budget in a single run — the budget is enforced per action
            // now, but a rule nobody could read is its own problem.
            'actions' => [$forUpdate ? 'sometimes' : 'required', 'array', 'min:1', 'max:25'],
            'actions.*.type' => ['required', 'string'],
        ]);

        /*
         * The actions come back from the request, not from validated().
         *
         * Defining `actions.*.type` makes Laravel treat the array as structured
         * and return ONLY the keys it has rules for — so every action arrived
         * carrying its type and nothing else, and a notify with no message was
         * rejected for having no message it had itself just discarded.
         *
         * Each action's meaningful fields are checked in assertActionUsable
         * below; anything else a client sends is inert, because ActionRunner
         * matches on type and reads only the keys that type defines.
         */
        if ($request->has('actions')) {
            $input['actions'] = $request->input('actions');
        }

        if (isset($input['trigger_key'])) {
            $this->assertTriggerExists($request, $input['trigger_key']);
        }

        $this->assertScheduleMatchesTrigger($input, $request);

        // The scope must be OURS. Nothing checked it, and the column has no
        // foreign key — so a rule could name another organization's franchise
        // id. It would never fire, but it is a tenant boundary stated and not
        // enforced, and those are the ones that stop being true later.
        $scopeType = array_key_exists('scope_type', $input)
            ? $input['scope_type']
            : $existing?->scope_type;
        $scopeId = array_key_exists('scope_id', $input)
            ? $input['scope_id']
            : $existing?->scope_id;
        if (array_key_exists('scope_type', $input) && $input['scope_type'] === null) {
            $scopeId = null;
            $input['scope_id'] = null;
        }
        if ($scopeType === null && $scopeId !== null) {
            throw ValidationException::withMessages([
                'scope_id' => 'Choose company or franchise scope before choosing its location.',
            ]);
        }

        if ($scopeType !== null) {
            $table = $scopeType === 'company' ? 'companies' : 'franchises';

            $exists = DB::table($table)
                ->where('id', $scopeId)
                ->where('organization_id', $this->tenant->idOrFail())
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'scope_id' => "That is not one of this organization's {$table}.",
                ]);
            }

            if (! $this->reach->covers($request->user(), $scopeType, $scopeId)) {
                throw ValidationException::withMessages([
                    'scope_id' => 'That scope is outside where you are assigned.',
                ]);
            }
        } elseif (! $this->reach->covers(
            $request->user(),
            'organization',
            $request->user()->organization_id,
        )) {
            throw ValidationException::withMessages([
                'scope_id' => 'Only someone assigned at organization level can create an organization-wide rule.',
            ]);
        }

        /*
         * The subject comes from the TRIGGER, and on an update the trigger is
         * not in the request — `trigger_key` validates as 'missing' there,
         * because changing it would make every recorded run describe something
         * the rule no longer does. So it is read off the record instead. An
         * edit that replaces the actions is checked against the trigger the rule
         * has always had.
         */
        $trigger = $input['trigger_key'] ?? $existing?->trigger_key;

        foreach ($input['actions'] ?? [] as $i => $action) {
            $this->assertActionUsable($action, $i, $trigger);
        }

        if (array_key_exists('conditions', $input) && $input['conditions'] !== null) {
            $this->assertConditionsUsable($input['conditions']);
        }

        return $input;
    }

    /**
     * An empty condition row would save and then throw on every run.
     *
     * The builder already refuses to POST one; this is the same rule on the
     * wire so a hand-written body cannot sneak past.
     */
    private function assertConditionsUsable(array $node, string $at = 'conditions'): void
    {
        if ($node === []) {
            return;
        }

        if (array_is_list($node)) {
            foreach ($node as $i => $child) {
                if (! is_array($child)) {
                    throw ValidationException::withMessages([
                        "{$at}.{$i}" => 'Each condition has to be an object with a field and an operator.',
                    ]);
                }
                $this->assertConditionsUsable($child, "{$at}.{$i}");
            }

            return;
        }

        foreach (['all', 'any'] as $group) {
            if (array_key_exists($group, $node)) {
                if (! is_array($node[$group])) {
                    throw ValidationException::withMessages([
                        "{$at}.{$group}" => 'A group of conditions has to be a list.',
                    ]);
                }
                $this->assertConditionsUsable($node[$group], "{$at}.{$group}");

                return;
            }
        }

        if (array_key_exists('not', $node)) {
            if (! is_array($node['not'])) {
                throw ValidationException::withMessages([
                    "{$at}.not" => 'A "not" condition has to wrap another condition.',
                ]);
            }
            $this->assertConditionsUsable($node['not'], "{$at}.not");

            return;
        }

        $field = $node['field'] ?? null;
        $op = $node['op'] ?? null;

        if (! is_string($field) || trim($field) === '' || ! is_string($op) || trim($op) === '') {
            throw ValidationException::withMessages([
                $at => 'A condition needs a field and an operator. An empty row would never match anything, so it cannot be saved.',
            ]);
        }
    }

    /**
     * A trigger no module declares would look configured and never fire — the
     * most confusing failure there is, because nothing reports an error.
     */
    /**
     * The trigger exists AND this person may reach the module that emits it.
     *
     * ── Hiding a key from a picker is not gating ─────────────────
     *
     * `catalogue()` filters modules by `{key}.view`, and for a while that was
     * the whole of it. It gated nothing. This method checked the REGISTRY, so a
     * key omitted from somebody's catalogue was still perfectly saveable by
     * anybody who knew it — and the keys are in the module declaration, which
     * any other client reads.
     *
     * Measured, on a role holding `automations.create` and no HR permission at
     * all, refused `GET /hr/invitations` with 403:
     *
     *     POST /automations {trigger_key: 'invitation.sent',
     *                        actions: [{type: 'notify', message: '{{email}}'}]}
     *     → 201, and the next invitation put the invitee's address in their
     *       notifications.
     *
     * `ActionRunner::interpolate` reads attributes straight off the subject, so
     * `{{email}}`, `{{first_name}}` and `{{last_name}}` all come out that way;
     * `webhook` posts the entire payload to a URL the same person chose. The
     * automation engine is a general-purpose reader of any record the system
     * emits, which makes "may build a rule on this module" exactly as strong a
     * permission as "may read this module", and it has to be checked where the
     * rule is SAVED.
     *
     * Both the catalogue and this ask `modulesInReach()`, so the picker and the
     * save path cannot drift — the bug was that they were two rules.
     */
    private function assertTriggerExists(Request $request, string $trigger): void
    {
        $definition = $this->registry->findTrigger($trigger);

        if ($definition === null) {
            throw ValidationException::withMessages([
                'trigger_key' => "Nothing in this system emits '{$trigger}'. Pick one from "
                    .'the catalogue — a rule listening for something that never happens '
                    .'looks configured and simply never runs.',
            ]);
        }

        if (in_array($this->moduleOfTrigger($trigger), $this->modulesInReach($request), true)) {
            return;
        }

        /*
         * R8: what to do, not which permission is missing. Naming the module is
         * the useful half — "ask for access to Human Resources" is actionable,
         * and `hr.view` is not. It also does not confirm the trigger exists,
         * which is why the wording is about reach rather than about the key.
         */
        throw ValidationException::withMessages([
            'trigger_key' => 'A rule can only watch a part of the app you can open yourself, '
                .'because whatever it watches it can also send on. Ask for access to that '
                .'part of the app, then build the rule.',
        ]);
    }

    /** Which module a trigger belongs to — `invitation.sent` is `hr`'s. */
    private function moduleOfTrigger(string $trigger): ?string
    {
        foreach ($this->registry->all() as $key => $module) {
            foreach ($module->triggers as $declared) {
                if ($declared->key === $trigger) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * The modules whose tab this person can open — the one rule the catalogue
     * and the save path both read.
     *
     * `heldBy` and not the role's raw keys: it drops modules the PLATFORM has
     * switched off for this organization as well, which is the other way a
     * module can be unreachable and the one nobody remembers.
     *
     * @return list<string>
     */
    private function modulesInReach(Request $request): array
    {
        $held = $this->permissions->heldBy($request->user());

        return array_values(array_filter(
            array_keys($this->registry->all()),
            fn (string $key) => in_array("{$key}.view", $held, true),
        ));
    }

    /**
     * Checked at SAVE time, not only when it runs.
     *
     * A webhook pointing at an internal address should be refused while somebody
     * is looking at the form, not silently fail hours later in a queue worker
     * where only the run log mentions it.
     */
    private function assertActionUsable(
        array $action,
        int $index,
        ?string $triggerKey,
    ): void {
        $type = $action['type'] ?? null;

        if (! in_array($type, self::IMPLEMENTED_ACTIONS, strict: true)) {
            throw ValidationException::withMessages([
                "actions.{$index}.type" => "Unknown action '{$type}'.",
            ]);
        }

        if ($type === 'webhook') {
            try {
                $this->webhook->assertAllowed($action['url'] ?? '');
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages([
                    "actions.{$index}.url" => $e->getMessage(),
                ]);
            }
        }

        if ($type === 'update_field') {
            $this->assertFieldWritable($action['field'] ?? null, $index, $triggerKey);
        }

        if ($type === 'notify' && blank($action['message'] ?? null)) {
            throw ValidationException::withMessages([
                "actions.{$index}.message" => 'What should the notification say?',
            ]);
        }

        if ($type === 'change_status') {
            if (blank($action['value'] ?? $action['status'] ?? null)) {
                throw ValidationException::withMessages([
                    "actions.{$index}.value" => 'Which status should it move to?',
                ]);
            }

            $this->assertStatusChangeable($index, $triggerKey);
        }
    }

    /** @return list<array{key:string,label:string,module_only:bool,parameters:array}> */
    private function actionTypes(): array
    {
        $parameter = static fn (string $key, string $label, string $kind, bool $required = true, bool $nullable = false): array => [
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'required' => $required,
            'nullable' => $nullable,
        ];
        $type = static fn (string $key, string $label, bool $moduleOnly = false, array $parameters = []): array => [
            'key' => $key,
            'label' => $label,
            'module_only' => $moduleOnly,
            'parameters' => $parameters,
        ];

        return [
            $type('update_field', 'Update a field'),
            $type('change_status', 'Change the status'),
            $type('webhook', 'Call a webhook'),
            // DECLARED, not bespoke. The builder drew a hand-written Message box
            // for this one action and posted {type, message} from a separate
            // controller. That worked and it was a trap: `_payload()` switches to
            // the generic branch the moment an action declares its first
            // parameter, and the generic branch reads the parameter controllers -
            // so declaring anything here without moving the form would have
            // dropped the message the author had just typed and failed the save
            // against a box they had filled in. Both halves are in one commit for
            // that reason; do not split them.
            $type('notify', 'Send a notification', false, [
                $parameter('message', 'Message', 'rich_text'),

                // Phrased as a DECLINE, and required: false so an untouched
                // switch is simply absent. A positive key - "send an email" -
                // would read as false on every rule saved before today and
                // silence all of them on deploy. Unchecked means follow the
                // organization; the organization setting stays the ceiling, so
                // this can only ever narrow. Semantics agreed with Notifications
                // and Settings; the dispatcher honours it at 27527d2f.
                //
                // AND IT IS SAVED EXPLICITLY, even when untouched. Every notify
                // action carries `in_app_only: false` rather than omitting the
                // key, and that is deliberate - do not trim it to narrow the
                // payload. An omitted key is an absence with two causes nothing
                // can tell apart: "authored before this parameter existed" and
                // "authored after, and left alone". Those are the same thing
                // only while the default never moves. The day unchecked is
                // decided to mean something else, rules carrying an explicit
                // false keep doing what their author chose and rules carrying
                // nothing silently change meaning. `actions` is a record of what
                // a rule does, not only an instruction, so it should say what
                // was chosen. Notifications' argument, 2026-09-15.
                $parameter('in_app_only', 'In-app only, do not email', 'boolean', false),
            ]),
            $type('voicemail.mark_dealt', 'Mark a voicemail dealt with'),
            $type('missed.create_job', 'Create a job from a missed call'),
            $type('message.mark_dealt', 'Mark an email dealt with'),

            // The line, the destination and the words are all stated in the
            // rule, because this action is deliberately not bound to the
            // record the event was about — there is nothing to read them from
            // when the trigger is an email arriving.
            /*
             * EzBizi's own mail, carrying words a tenant wrote.
             *
             * Jose reversed the September ruling on 2026-09-16 and was explicit
             * about which mail he meant: "Email ( the Ezbizi SES one, not the
             * company one )". Several docblocks and three memories still argue
             * the other way; they are the history of a decision taken the other
             * direction, not instruction.
             *
             * THE RECIPIENT IS A FIELD OF THE EVENT, never an address typed on
             * the rule, and that is the difference between this and `sms.send`
             * directly below. On SMS the line and the reputation are the
             * tenant's own Twilio number and their own A2P registration, so
             * texting whoever they like is their consequence on their asset. On
             * EzBizi's SES it is everybody's - one business's complaint rate
             * suppresses sign-in and password-reset mail for every tenant. The
             * two actions differ ON PURPOSE. Do not "fix" the inconsistency.
             *
             * No attachment parameter, and deliberately none rather than one
             * that is accepted and ignored: an attachment from ezbizi.com on a
             * message a tenant composed is a phishing vector wearing our domain.
             */
            /*
             * A TEMPLATE, never free text, and that is Meta's rule rather than
             * ours: WhatsAppCloud::sendText's own docblock says free text is
             * accepted only inside the 24-hour window after the customer wrote
             * to us. An automation is by definition a message the business
             * starts - a job booked, a lead arriving - and almost never inside
             * that window. A message box here would work whenever somebody
             * tried it right after a reply and silently fail for everybody else.
             *
             * No declared parameters: the form's SHAPE depends on which template
             * is picked, because each has its own number of blanks. Same reason
             * `update_field` draws its own form - its options depend on the
             * subject - rather than a bespoke form for its own sake.
             */
            $type('whatsapp.send', 'Send a WhatsApp message'),

            $type('email.send', 'Send an email', false, [
                $parameter('to_field', 'Send to the address in', 'trigger_field'),
                $parameter('subject', 'Subject', 'text'),
                $parameter('body', 'Message', 'rich_text'),
            ]),

            $type('sms.send', 'Send a message', true, [
                $parameter('number_id', 'Send from', 'phone_number'),
                $parameter('to', 'Send to', 'text'),
                $parameter('body', 'Message', 'text'),
            ]),
        ];
    }

    /**
     * change_status is generic, the way update_field is. The engine only runs
     * it for a Job or a Vehicle, so a Customer (or HR) rule that saved one
     * would fail on every run. Same gate as assertFieldWritable: the TRIGGER's
     * subject, against ActionRunner's own list.
     */
    private function assertStatusChangeable(int $index, ?string $triggerKey): void
    {
        $subject = $this->registry->subjectOfTrigger((string) $triggerKey);

        if (ActionRunner::canChangeStatus($subject?->model)) {
            return;
        }

        $record = $subject?->label ?? 'the record this rule reacts to';

        throw ValidationException::withMessages([
            "actions.{$index}.type" => sprintf(
                'A rule cannot change the status of %s. Only %s can.',
                $record,
                $this->subjectsThatCanChangeStatus(),
            ),
        ]);
    }

    /** "a Job or a Vehicle" — read off ActionRunner, same as writable fields. */
    private function subjectsThatCanChangeStatus(): string
    {
        $labels = [];

        foreach ($this->registry->all() as $module) {
            if ($module->subject !== null && ActionRunner::canChangeStatus($module->subject->model)) {
                $labels[] = 'a '.$module->subject->label;
            }
        }

        if ($labels === []) {
            return 'nothing';
        }

        $last = array_pop($labels);

        return $labels === [] ? $last : implode(', ', $labels).' or '.$last;
    }

    /**
     * Can a rule on this trigger set this field?
     *
     * Only `blank()` was checked, so `{'type':'update_field','field':
     * 'organization_id'}` on a job trigger was answered 201 CREATED — and then
     * refused by ActionRunner on every run for the life of the rule, in a queue
     * worker, where the only trace is a run log nobody is watching. That is the
     * exact failure the header of this section describes for webhooks, one
     * action along.
     *
     * The question is asked of the TRIGGER, not of the action: update_field
     * writes to the subject of the event, so what is legal depends entirely on
     * what the rule reacts to. Which is also why the two declared actions
     * `customer.update_field` and `job.update_field` need nothing checked
     * against each other — they reduce to the same generic action, and the
     * trigger already decided which record it lands on.
     *
     * Against ActionRunner's own list, so the answer here and the answer at run
     * time cannot drift, and so the sentence a person reads while looking at the
     * form is the sentence the run log would have shown them hours later.
     */
    private function assertFieldWritable(mixed $field, int $index, ?string $triggerKey): void
    {
        $key = "actions.{$index}.field";

        if (blank($field)) {
            throw ValidationException::withMessages([$key => 'Which field should it set?']);
        }

        $subject = $this->registry->subjectOfTrigger((string) $triggerKey);
        $writable = ActionRunner::writableOn($subject?->model);

        if (array_key_exists($field, $writable)) {
            return;
        }

        /*
         * A module may declare no subject at all, and Human Resources is the
         * first that does — its events are about three different kinds of record
         * and a ModuleDefinition holds one, so it holds none rather than the
         * wrong one. The refusal has to work anyway, and it must not fall back
         * to naming the trigger key: a wire key is not an explanation, and R8
         * says a refusal is a sentence.
         */
        $record = $subject?->label ?? 'the record this rule reacts to';

        // Named, so nobody has to guess which of the twenty-five actions is
        // wrong or what they were supposed to put there.
        throw ValidationException::withMessages([$key => $writable === []
            ? sprintf(
                "A rule cannot set any field on %s, so it cannot set '%s'. Only %s can be "
                .'changed by a rule; anything else needs a different kind of action.',
                $record,
                $field,
                $this->subjectsWithWritableFields(),
            )
            // Word for word what the run log would have said, because the point
            // of moving the check forward is that the person sees the same
            // answer at the form that they would otherwise have found hours
            // later in a queue worker's output.
            : sprintf(
                "'%s' is not a field an automation may set on %s. Allowed: %s. Saved as it "
                .'is, this rule would be refused on every single run.',
                $field,
                $subject->label,
                implode(', ', array_keys($writable)),
            ),
        ]);
    }

    /**
     * "a Job or a Customer" — the records a rule can actually change.
     *
     * Read off the registry rather than written into the sentence, so the day a
     * third model becomes writable the refusal starts mentioning it without
     * anybody remembering to come back here.
     */
    private function subjectsWithWritableFields(): string
    {
        $labels = [];

        foreach ($this->registry->all() as $module) {
            if ($module->subject !== null && ActionRunner::writableOn($module->subject->model) !== []) {
                $labels[] = 'a '.$module->subject->label;
            }
        }

        if ($labels === []) {
            return 'nothing';   // unreachable today; not worth an empty sentence if it ever is
        }

        $last = array_pop($labels);

        return $labels === [] ? $last : implode(', ', $labels).' or '.$last;
    }

    /**
     * @param  array<int,array>  $actions
     *
     * The mask is SensitiveValues::MASK rather than a literal, so there is one
     * masked-looking string in the whole product. A client that learns to
     * recognise one — and to refuse to save it back over a real value, which
     * this screen had to learn the hard way — learns it once.
     */
    private function maskSecrets(array $actions): array
    {
        return array_map(function (array $action) {
            if (isset($action['secret'])) {
                $action['secret'] = SensitiveValues::MASK;
            }

            return $action;
        }, $actions);
    }

    private function present(Automation $automation, bool $detailed = false): array
    {
        $base = [
            'id' => $automation->id,
            'name' => $automation->name,
            'description' => $automation->description,
            'trigger_key' => $automation->trigger_key,
            'is_active' => $automation->is_active,
            'is_live' => $automation->isLive(),
            'scope' => [
                'type' => $automation->scope_type,
                'id' => $automation->scope_id,
            ],
            // Surfaced rather than buried: a rule that switched itself off after
            // repeated failure must say so where somebody will see it.
            'disabled_at' => $automation->disabled_at?->toIso8601String(),
            'disabled_reason' => $automation->disabled_reason,
            'runs_count' => $automation->runs_count ?? $automation->runs()->count(),
            'version' => $automation->activeVersion?->version,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'conditions' => $automation->activeVersion?->conditions,
            // Secrets are masked on the way out. §5.5 says webhook secrets are
            // stored encrypted and never in the settings table; returning them
            // in clear from the API is the same exposure by another route —
            // anyone who can read an automation could forge signed calls to the
            // tenant's own endpoint.
            'actions' => $this->maskSecrets($automation->activeVersion?->actions ?? []),
            'versions' => $automation->versions->map(fn (AutomationVersion $v) => [
                'id' => $v->id,
                'version' => $v->version,
                'is_active' => $v->id === $automation->active_version_id,
                'created_at' => $v->created_at?->toIso8601String(),
            ]),
        ];
    }
}
