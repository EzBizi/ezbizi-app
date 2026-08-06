<?php

namespace App\Http\Controllers\Api;

use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Models\WorkflowVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use App\Support\Workflows\OutboundWebhook;
use App\Support\Workflows\WorkflowEngine;
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
 * Editing writes a NEW VERSION and repoints the workflow. Nothing here can
 * change a version in place, because every run recorded which one it executed.
 */
class WorkflowsController extends Controller
{
    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly WorkflowEngine $engine,
        private readonly OutboundWebhook $webhook,
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/workflows/catalogue
     *
     * What can be built. Comes from the module registry rather than a list kept
     * here, so a module added next year appears the moment it declares itself —
     * which is the §5.5 standing rule doing its job.
     */
    public function catalogue(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Workflow::class);

        $modules = [];

        foreach ($this->registry->all() as $key => $module) {
            if ($module->triggers === [] && $module->workflowActions === []) {
                continue;
            }

            $modules[] = [
                'key'      => $key,
                'label'    => $module->label,
                'triggers' => $module->triggers,
                'actions'  => $module->workflowActions,
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
                'action_types' => [
                    ['key' => 'update_field', 'label' => 'Update a field'],
                    ['key' => 'webhook', 'label' => 'Call a webhook'],
                    ['key' => 'notify', 'label' => 'Send a notification'],
                ],
                'limits' => [
                    'max_depth'     => WorkflowEvent::MAX_DEPTH,
                    'action_budget' => WorkflowEvent::ACTION_BUDGET,
                ],
            ],
        ]);
    }

    /** GET /api/v1/workflows */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Workflow::class);

        $workflows = Workflow::query()
            ->with('activeVersion')
            ->withCount(['runs'])
            ->orderBy('name')
            ->get()
            ->map(fn (Workflow $w) => $this->present($w));

        return response()->json(['data' => $workflows]);
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
    private function assertMine(Workflow $workflow): void
    {
        abort_unless($workflow->organization_id === $this->tenant->idOrFail(), 404);
    }

    /** GET /api/v1/workflows/{workflow} */
    public function show(Workflow $workflow): JsonResponse
    {
        $this->assertMine($workflow);
        $this->authorize('view', $workflow);

        return response()->json(['data' => $this->present($workflow, detailed: true)]);
    }

    /** POST /api/v1/workflows */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Workflow::class);

        $input = $this->validated($request);

        $workflow = DB::transaction(function () use ($input, $request) {
            $workflow = Workflow::create([
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

            $this->addVersion($workflow, $input, $request);

            $this->audit->log('workflow.created', $workflow, metadata: [
                'trigger' => $workflow->trigger_key,
            ]);

            return $workflow;
        });

        return response()->json(
            ['data' => $this->present($workflow->fresh(), detailed: true)],
            Response::HTTP_CREATED
        );
    }

    /**
     * PATCH /api/v1/workflows/{workflow}
     *
     * Writes a new version rather than editing the live one. The previous
     * version stays exactly as it was, because runs point at it.
     */
    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $this->assertMine($workflow);
        $this->authorize('update', $workflow);

        $input = $this->validated($request, forUpdate: true);

        DB::transaction(function () use ($workflow, $input, $request) {
            $workflow->fill(array_intersect_key($input, array_flip([
                'name', 'description', 'scope_type', 'scope_id',
            ])));

            // Re-enabling clears the auto-disable, which is what makes it a
            // deliberate act rather than something that quietly heals.
            if (($input['is_active'] ?? false) === true) {
                $workflow->forceFill([
                    'is_active' => true,
                    'disabled_at' => null,
                    'disabled_reason' => null,
                    'consecutive_failures' => 0,
                ]);
            } elseif (array_key_exists('is_active', $input)) {
                $workflow->is_active = false;
            }

            $this->audit->logChange('workflow.updated', $workflow);
            $workflow->save();

            if (isset($input['actions'])) {
                $this->addVersion($workflow, $input, $request);
            }
        });

        return response()->json(['data' => $this->present($workflow->fresh(), detailed: true)]);
    }

    /**
     * POST /api/v1/workflows/{workflow}/activate/{version}
     *
     * Rollback, and the reason versions are immutable (§5.5). Pointing back at
     * an older definition is the whole recovery path when a new rule turns out
     * to be wrong.
     */
    public function activateVersion(Request $request, Workflow $workflow, WorkflowVersion $version): JsonResponse
    {
        $this->assertMine($workflow);
        $this->authorize('update', $workflow);

        abort_unless($version->workflow_id === $workflow->getKey(), 404);

        $previous = $workflow->active_version_id;

        $workflow->forceFill(['active_version_id' => $version->getKey()])->save();

        $this->audit->log('workflow.version_activated', $workflow, metadata: [
            'from' => $previous,
            'to'   => $version->getKey(),
            'version' => $version->version,
        ]);

        return response()->json(['data' => $this->present($workflow->fresh(), detailed: true)]);
    }

    /**
     * POST /api/v1/workflows/{workflow}/test
     *
     * Rehearse against a real past event (§5.5 test mode). The conditions are
     * evaluated for real — that is the part people get wrong — and the actions
     * report what they WOULD do without doing it.
     */
    public function test(Request $request, Workflow $workflow): JsonResponse
    {
        $this->assertMine($workflow);
        $this->authorize('update', $workflow);

        $input = $request->validate([
            'event_id' => ['nullable', 'uuid'],
        ]);

        $event = $input['event_id'] ?? null
            ? WorkflowEvent::findOrFail($input['event_id'])
            : WorkflowEvent::where('event_key', $workflow->trigger_key)
                ->latest('occurred_at')
                ->first();

        if ($event === null) {
            return response()->json([
                'message' => "Nothing has triggered '{$workflow->trigger_key}' yet, so there "
                    .'is no real event to try this against. Create or change a record first.',
                'code'    => 'no_event_to_test',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json(['data' => $this->engine->rehearse($workflow, $event)]);
    }

    /** GET /api/v1/workflows/{workflow}/runs */
    public function runs(Request $request, Workflow $workflow): JsonResponse
    {
        $this->assertMine($workflow);
        $this->authorize('view', $workflow);

        // Jobs are never shared (§5.2), so a run log that shows every franchise's
        // work would hand a franchise-scoped user exactly what JobPolicy refuses
        // them — including the field values in the condition trace.
        $reachable = $request->user()->reachableFranchises()->pluck('id')->all();

        $runs = WorkflowRun::query()
            ->where('workflow_id', $workflow->getKey())
            ->with('event:id,event_key,subject_type,subject_id,occurred_at')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->latest('started_at')
            ->limit(100)
            ->get()
            ->filter(fn (WorkflowRun $r) => $this->maySeeSubjectOf($r, $reachable))
            ->values()
            ->map(fn (WorkflowRun $r) => [
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
    private function maySeeSubjectOf(WorkflowRun $run, array $reachable): bool
    {
        $type = $run->event?->subject_type;

        if ($type !== \App\Models\Job::class) {
            return true;
        }

        $job = \App\Models\Job::query()->find($run->event->subject_id);

        return $job === null || in_array($job->franchise_id, $reachable, strict: true);
    }

    /** DELETE /api/v1/workflows/{workflow} */
    public function destroy(Workflow $workflow): JsonResponse
    {
        $this->assertMine($workflow);
        $this->authorize('delete', $workflow);

        DB::transaction(function () use ($workflow) {
            $this->audit->log('workflow.deleted', $workflow);
            // Soft delete: the runs reference it, and a run pointing at a rule
            // that no longer exists is unreadable.
            $workflow->delete();
        });

        return response()->json(['message' => 'Workflow deleted.']);
    }

    // ── shared ───────────────────────────────────────────────────

    private function addVersion(Workflow $workflow, array $input, Request $request): void
    {
        $next = ($workflow->versions()->max('version') ?? 0) + 1;

        $version = WorkflowVersion::create([
            'workflow_id' => $workflow->getKey(),
            'version'     => $next,
            'conditions'  => $input['conditions'] ?? null,
            'actions'     => $input['actions'],
            'created_by'  => $request->user()->getKey(),
            'created_at'  => now(),
        ]);

        $workflow->forceFill(['active_version_id' => $version->getKey()])->save();
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
            // WorkflowEngine::scopeOf). It previously validated as legal and then
            // could never match anything, which is worse than refusing it.
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
        foreach ($this->registry->all() as $module) {
            if (in_array($trigger, $module->triggers, strict: true)) {
                return;
            }
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

        if (! in_array($type, ['update_field', 'webhook', 'notify'], strict: true)) {
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

    private function present(Workflow $workflow, bool $detailed = false): array
    {
        $base = [
            'id'          => $workflow->id,
            'name'        => $workflow->name,
            'description' => $workflow->description,
            'trigger_key' => $workflow->trigger_key,
            'is_active'   => $workflow->is_active,
            'is_live'     => $workflow->isLive(),
            'scope'       => [
                'type' => $workflow->scope_type,
                'id'   => $workflow->scope_id,
            ],
            // Surfaced rather than buried: a rule that switched itself off after
            // repeated failure must say so where somebody will see it.
            'disabled_at'     => $workflow->disabled_at?->toIso8601String(),
            'disabled_reason' => $workflow->disabled_reason,
            'runs_count'      => $workflow->runs_count ?? $workflow->runs()->count(),
            'version'         => $workflow->activeVersion?->version,
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            'conditions' => $workflow->activeVersion?->conditions,
            // Secrets are masked on the way out. §5.5 says webhook secrets are
            // stored encrypted and never in the settings table; returning them
            // in clear from the API is the same exposure by another route —
            // anyone who can read a workflow could forge signed calls to the
            // tenant's own endpoint.
            'actions'    => $this->maskSecrets($workflow->activeVersion?->actions ?? []),
            'versions'   => $workflow->versions->map(fn (WorkflowVersion $v) => [
                'id'         => $v->id,
                'version'    => $v->version,
                'is_active'  => $v->id === $workflow->active_version_id,
                'created_at' => $v->created_at?->toIso8601String(),
            ]),
        ];
    }
}
