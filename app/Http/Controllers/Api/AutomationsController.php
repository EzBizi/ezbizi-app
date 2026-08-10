<?php

namespace App\Http\Controllers\Api;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Automations\AutomationEngine;
use App\Support\Automations\OutboundWebhook;
use App\Support\Modules\ActionDefinition;
use App\Support\Modules\ModuleRegistry;
use App\Support\Modules\TriggerDefinition;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
    /** What ActionRunner can actually perform. The validator agrees with this. */
    private const IMPLEMENTED_ACTIONS = ['update_field', 'webhook', 'notify'];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly AutomationEngine $engine,
        private readonly OutboundWebhook $webhook,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
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

        $modules = [];

        foreach ($this->registry->all() as $key => $module) {
            if ($module->triggers === [] && $module->automationActions === []) {
                continue;
            }

            $modules[] = [
                'key'   => $key,
                'label' => $module->label,
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
                    'key'         => $t->key,
                    'label'       => $t->label,
                    'description' => $t->description,
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
                 * `implemented` is the whole of that distinction, so the wording
                 * beside it has to stand on its own: the description says what
                 * the action would do, and never that it is missing. A client
                 * renders "not built yet" from the flag and the sentence still
                 * reads correctly on the day it flips.
                 */
                'declared_actions' => array_map(fn (ActionDefinition $a) => [
                    'key'         => $a->key,
                    'label'       => $a->label,
                    'description' => $a->description,
                    'implemented' => in_array($a->type(), self::IMPLEMENTED_ACTIONS, strict: true),
                ], $module->automationActions),
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
                'action_types' => [
                    ['key' => 'update_field', 'label' => 'Update a field'],
                    ['key' => 'webhook', 'label' => 'Call a webhook'],
                    ['key' => 'notify', 'label' => 'Send a notification'],
                ],
                'limits' => [
                    'max_depth'     => AutomationEvent::MAX_DEPTH,
                    'action_budget' => AutomationEvent::ACTION_BUDGET,
                ],
            ],
        ]);
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
                'name'        => $input['name'],
                'description' => $input['description'] ?? null,
                'trigger_key' => $input['trigger_key'],
                'scope_type'  => $input['scope_type'] ?? null,
                'scope_id'    => $input['scope_id'] ?? null,
                // Off until somebody switches it on. A rule that starts firing
                // the instant it is saved will fire before it has been read back.
                'is_active'   => false,
                'created_by'  => $request->user()->getKey(),
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

        $input = $this->validated($request, forUpdate: true);

        DB::transaction(function () use ($automation, $input, $request) {
            $automation->fill(array_intersect_key($input, array_flip([
                'name', 'description', 'scope_type', 'scope_id',
            ])));

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
            'to'   => $version->getKey(),
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
                'code'    => 'no_event_to_test',
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
            ->filter(fn (AutomationRun $r) => $this->maySeeSubjectOf($r, $reachable))
            ->values()
            ->map(fn (AutomationRun $r) => [
                'id'      => $r->id,
                'status'  => $r->status,
                'is_test' => $r->is_test,
                'event'   => [
                    'key' => $r->event?->event_key,
                    'occurred_at' => $r->event?->occurred_at?->toIso8601String(),
                ],
                // The trace is the point. "It did not match" without saying which
                // clause failed is not an answer to anyone debugging a rule.
                'condition_trace' => $r->condition_trace,
                'action_results'  => $r->action_results,
                'error'           => $r->error,
                'started_at'      => $r->started_at->toIso8601String(),
            ]);

        return response()->json(['data' => $runs]);
    }

    /**
     * Can this caller see the record a run acted on?
     *
     * Customers are shared org-wide so they are always visible; jobs are not.
     * A run whose subject has been deleted is shown — there is nothing left to
     * leak, and hiding it would make the log lie about what happened.
     */
    private function maySeeSubjectOf(AutomationRun $run, array $reachable): bool
    {
        $type = $run->event?->subject_type;

        if ($type !== \App\Models\Job::class) {
            return true;
        }

        $job = \App\Models\Job::query()->find($run->event->subject_id);

        return $job === null || in_array($job->franchise_id, $reachable, strict: true);
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
            'version'     => $next,
            'conditions'  => $input['conditions'] ?? null,
            'actions'     => $input['actions'],
            'created_by'  => $request->user()->getKey(),
            'created_at'  => now(),
        ]);

        $automation->forceFill(['active_version_id' => $version->getKey()])->save();
    }

    private function validated(Request $request, bool $forUpdate = false): array
    {
        $required = $forUpdate ? 'sometimes' : 'required';

        $input = $request->validate([
            'name'        => [$required, 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'trigger_key' => $forUpdate
                // Changing the trigger makes it a different rule, and every run
                // recorded so far would describe something it no longer does.
                ? ['missing']
                : ['required', 'string', 'max:100'],
            // 'company' is accepted because the engine resolves it (see
            // AutomationEngine::scopeOf). It previously validated as legal
            // and then could never match anything, which is worse than
            // refusing it.
            'scope_type'  => ['nullable', Rule::in(['company', 'franchise'])],
            'scope_id'    => ['nullable', 'uuid', 'required_with:scope_type'],
            'is_active'   => ['boolean'],
            'conditions'  => ['nullable', 'array'],
            // Capped. One version with two hundred actions would spend the whole
            // cascade budget in a single run — the budget is enforced per action
            // now, but a rule nobody could read is its own problem.
            'actions'     => [$forUpdate ? 'sometimes' : 'required', 'array', 'min:1', 'max:25'],
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
            $this->assertTriggerExists($input['trigger_key']);
        }

        // The scope must be OURS. Nothing checked it, and the column has no
        // foreign key — so a rule could name another organization's franchise
        // id. It would never fire, but it is a tenant boundary stated and not
        // enforced, and those are the ones that stop being true later.
        if (($input['scope_type'] ?? null) !== null) {
            $table = $input['scope_type'] === 'company' ? 'companies' : 'franchises';

            $exists = DB::table($table)
                ->where('id', $input['scope_id'])
                ->where('organization_id', $this->tenant->idOrFail())
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'scope_id' => "That is not one of this organization's {$table}.",
                ]);
            }
        }

        foreach ($input['actions'] ?? [] as $i => $action) {
            $this->assertActionUsable($action, $i);
        }

        return $input;
    }

    /**
     * A trigger no module declares would look configured and never fire — the
     * most confusing failure there is, because nothing reports an error.
     */
    private function assertTriggerExists(string $trigger): void
    {
        if ($this->registry->findTrigger($trigger) !== null) {
            return;
        }

        throw ValidationException::withMessages([
            'trigger_key' => "Nothing in this system emits '{$trigger}'. Pick one from "
                .'the catalogue — a rule listening for something that never happens '
                .'looks configured and simply never runs.',
        ]);
    }

    /**
     * Checked at SAVE time, not only when it runs.
     *
     * A webhook pointing at an internal address should be refused while somebody
     * is looking at the form, not silently fail hours later in a queue worker
     * where only the run log mentions it.
     */
    private function assertActionUsable(array $action, int $index): void
    {
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

        if ($type === 'update_field' && blank($action['field'] ?? null)) {
            throw ValidationException::withMessages([
                "actions.{$index}.field" => 'Which field should it set?',
            ]);
        }

        if ($type === 'notify' && blank($action['message'] ?? null)) {
            throw ValidationException::withMessages([
                "actions.{$index}.message" => 'What should the notification say?',
            ]);
        }
    }

    /** @param array<int,array> $actions */
    private function maskSecrets(array $actions): array
    {
        return array_map(function (array $action) {
            if (isset($action['secret'])) {
                $action['secret'] = '••••••••';
            }

            return $action;
        }, $actions);
    }

    private function present(Automation $automation, bool $detailed = false): array
    {
        $base = [
            'id'          => $automation->id,
            'name'        => $automation->name,
            'description' => $automation->description,
            'trigger_key' => $automation->trigger_key,
            'is_active'   => $automation->is_active,
            'is_live'     => $automation->isLive(),
            'scope'       => [
                'type' => $automation->scope_type,
                'id'   => $automation->scope_id,
            ],
            // Surfaced rather than buried: a rule that switched itself off after
            // repeated failure must say so where somebody will see it.
            'disabled_at'     => $automation->disabled_at?->toIso8601String(),
            'disabled_reason' => $automation->disabled_reason,
            'runs_count'      => $automation->runs_count ?? $automation->runs()->count(),
            'version'         => $automation->activeVersion?->version,
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
            'actions'    => $this->maskSecrets($automation->activeVersion?->actions ?? []),
            'versions'   => $automation->versions->map(fn (AutomationVersion $v) => [
                'id'         => $v->id,
                'version'    => $v->version,
                'is_active'  => $v->id === $automation->active_version_id,
                'created_at' => $v->created_at?->toIso8601String(),
            ]),
        ];
    }
}
