<?php

namespace App\Http\Controllers\Api;

use App\Models\Contract;
use App\Models\ContractVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Contracts\CommonCore;
use App\Support\Contracts\FieldSet;
use App\Support\Contracts\FieldType;
use App\Support\Contracts\FilledBy;
use App\Support\Contracts\VersionDiff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Contracts tab — the template designer
 * (EZBIZI-HR-PLAN.md, "Contracts. The template designer. **This is the schema
 * for everything else.**").
 *
 * A tenant defines the fields; this endpoint must not know what any of them
 * mean. It knows there is a key, a label, a type, who fills it, whether it is
 * required and whether the tenant called it sensitive. It never knows that
 * "bank_account" is a bank account. That is §2.1 applied to the part of the
 * product that varies most between customers, and every refusal below is about
 * the SHAPE of the list rather than about its content.
 *
 * ── Two endpoints, because editing and publishing are different ──
 *
 *   PATCH /hr/contracts/{contract}            its name and description, and
 *                                             nothing about the fields
 *   POST  /hr/contracts/{contract}/versions   publishes a new field list
 *
 * `AutomationsController::update` folds both into one PATCH. This does not, and
 * the plan is the reason: "publishing a new version must be an explicit act with
 * a diff a person can read". An endpoint that also renames things is not an
 * explicit act — somebody correcting a typo in the name would publish a version
 * of the schema as a side effect, and every record from that point on would
 * remember a version nobody meant to create. So PATCH refuses `fields` outright
 * rather than ignoring them, and says where they go.
 *
 * ── There is no rollback endpoint, unlike automations ────────────
 *
 * `POST /automations/{a}/activate/{version}` repoints an automation at an older
 * definition, and that is right for a rule: rollback is incident recovery, and
 * the run log records which version each run used, so nothing is misdescribed.
 *
 * A template is a schema, and the same move would be wrong for it. Repointing
 * `current_version_id` backwards changes what every future onboarding asks while
 * producing no entry in the version list — the one place that is supposed to
 * answer "what changed, and when". Undoing a publish is therefore done by
 * publishing the previous field list again, which costs a version number and
 * buys a diff somebody can read. Records are unaffected either way: each one
 * remembers the version that produced it.
 */
class HrContractsController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * GET /api/v1/hr/contracts/catalogue
     *
     * What a template may be built out of: the field types, who can fill a
     * field, and the keys the common core has already claimed.
     *
     * It exists for the same reason `automations/catalogue` and
     * `roles/catalogue` do. The alternative is the client carrying its own copy
     * of the six types and the eight reserved keys, and a client-side copy of a
     * server rule is a second place to forget: the day a type is added, the
     * picker does not offer it, and the day a reserved key changes, the form
     * accepts a key the server then refuses with an error nobody expected.
     */
    public function catalogue(): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        return response()->json(['data' => [
            // Keys AND words, because the person configuring this runs a chimney
            // business — the same reason the automation catalogue carries labels.
            'field_types' => self::pairs(FieldType::ALL),
            'filled_by'   => self::pairs(FilledBy::ALL),
            /*
             * The common core, published rather than merely enforced.
             *
             * A form that lets somebody type `date_of_birth`, submit, and read a
             * refusal has taught them a rule the hard way. The client can grey
             * these out with the sentence explaining why — and the sentence is
             * the server's, so the two cannot drift.
             */
            'reserved_keys' => array_map(
                fn (string $key) => [
                    'key'    => $key,
                    'is'     => CommonCore::RESERVED[$key],
                    'reason' => CommonCore::refusalFor($key),
                ],
                CommonCore::keys(),
            ),
            'limits' => [
                'max_fields'  => FieldSet::MAX_FIELDS,
                'max_options' => FieldSet::MAX_OPTIONS,
            ],
        ]]);
    }

    /** @param array<string, string> $map */
    private static function pairs(array $map): array
    {
        return array_map(
            fn (string $key, string $label) => ['key' => $key, 'label' => $label],
            array_keys($map),
            array_values($map),
        );
    }

    /** GET /api/v1/hr/contracts */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Contract::class);

        $contracts = Contract::query()
            ->with('currentVersion')
            ->withCount('versions')
            ->orderBy('name')
            ->get()
            ->map(fn (Contract $c) => $this->present($c));

        return response()->json(['data' => $contracts]);
    }

    /**
     * Another tenant's id is NOT FOUND, never forbidden — the same reasoning
     * AutomationsController states: 403 confirms the id is real somewhere on the
     * platform and 404 does not.
     */
    private function assertMine(Contract $contract): void
    {
        abort_unless($contract->organization_id === $this->tenant->idOrFail(), 404);
    }

    /** GET /api/v1/hr/contracts/{contract} */
    public function show(Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('view', $contract);

        return response()->json(['data' => $this->present($contract, detailed: true)]);
    }

    /**
     * POST /api/v1/hr/contracts
     *
     * A contract and its first version, in one transaction. Unlike an
     * automation, it is usable the moment it exists — there is no `is_active`,
     * because a template does nothing on its own. An automation switched on
     * starts acting on records unattended, which is why that one starts off; a
     * template only ever does something when a person chooses it for an
     * onboarding, and that choice is the switch.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Contract::class);

        $input = $request->validate([
            'name'        => ['required', 'string', 'max:150', $this->uniqueName()],
            'description' => ['nullable', 'string', 'max:2000'],
            'notes'       => ['nullable', 'string', 'max:500'],
            'fields'      => ['required', 'array'],
        ]);

        // Validated before anything is written, and by the same class that will
        // validate every later version — so the first field list is held to
        // exactly the rules the second one will be.
        $fields = FieldSet::fromInput($request->input('fields'));

        $contract = DB::transaction(function () use ($input, $fields, $request) {
            $contract = Contract::create([
                'name'        => $input['name'],
                'description' => $input['description'] ?? null,
                'created_by'  => $request->user()->getKey(),
            ]);

            $this->publish($contract, $fields, $input['notes'] ?? null, $request);

            $this->audit->log('contract.created', $contract, metadata: [
                'fields' => count($fields->fields),
            ]);

            return $contract;
        });

        return response()->json(
            ['data' => $this->present($contract->fresh(), detailed: true)],
            Response::HTTP_CREATED
        );
    }

    /**
     * PATCH /api/v1/hr/contracts/{contract}
     *
     * The name and the description. Not the fields — see the class docblock for
     * why publishing is its own act, and why `fields` here is refused rather
     * than quietly dropped.
     */
    public function update(Request $request, Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('update', $contract);

        $input = $request->validate([
            'name'        => ['sometimes', 'string', 'max:150', $this->uniqueName($contract)],
            'description' => ['nullable', 'string', 'max:2000'],
            // `missing` rather than absent-by-omission: a client that sends
            // fields here is doing something it believes will take effect, and
            // the reply has to be the reason rather than silence.
            'fields'      => ['missing'],
        ], [
            'fields.missing' => 'Changing the fields publishes a new version of the template, '
                .'which is a separate, deliberate act — POST to this contract’s /versions '
                .'instead. It answers with a diff of what would change, so nobody publishes a '
                .'new schema while renaming something.',
        ]);

        DB::transaction(function () use ($contract, $input) {
            $contract->fill(array_intersect_key($input, array_flip(['name', 'description'])));

            $this->audit->logChange('contract.updated', $contract);
            $contract->save();
        });

        return response()->json(['data' => $this->present($contract->fresh(), detailed: true)]);
    }

    /** GET /api/v1/hr/contracts/{contract}/versions */
    public function versions(Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('view', $contract);

        // Oldest first here, then reversed, because each version's diff is
        // against the one BEFORE it — walking newest-first would need the list
        // twice over to find each predecessor.
        $ordered = $contract->versions()->reorder('version')->with('createdBy:id,name')->get();
        $out     = [];
        $previous = null;

        foreach ($ordered as $version) {
            $out[] = $this->presentVersion($version, $contract, $previous);
            $previous = $version;
        }

        return response()->json(['data' => array_reverse($out)]);
    }

    /**
     * POST /api/v1/hr/contracts/{contract}/versions — publish.
     *
     * ── `preview` is what makes this an informed act ─────────────
     *
     * The plan asks for "a diff a person can read" at the moment of publishing.
     * A diff returned only in the 201 is a diff read after the fact, so the same
     * body may be sent with `preview: true`: it validates exactly as a real
     * publish would, computes the same diff by the same code path, and writes
     * nothing. The screen shows what would change, and the person publishes.
     *
     * One code path rather than a separate dry-run endpoint, deliberately — two
     * would eventually disagree, and the one that drifts would be the one saying
     * "nothing will be retired" about a publish that retires something.
     */
    public function publishVersion(Request $request, Contract $contract): JsonResponse
    {
        $this->assertMine($contract);
        $this->authorize('update', $contract);

        $input = $request->validate([
            'fields'  => ['required', 'array'],
            'notes'   => ['nullable', 'string', 'max:500'],
            'preview' => ['boolean'],
        ]);

        $fields = FieldSet::fromInput(
            $request->input('fields'),
            // The whole history, not just the current version — see
            // Contract::publishedKeyTypes for why the distinction matters.
            $contract->publishedKeyTypes(),
        );

        $current = $contract->currentVersion;
        $diff    = VersionDiff::between($current?->fieldSet(), $fields);

        if ($request->boolean('preview')) {
            return response()->json(['data' => [
                'preview' => true,
                'diff'    => $diff->toArray(),
                // The version number this WOULD take, so the screen can name it.
                'version' => (int) ($contract->versions()->max('version') ?? 0) + 1,
            ]]);
        }

        /*
         * An identical field list is refused.
         *
         * Not pedantry: every version is a fork in the history of the schema,
         * and records point at them. A version that changed nothing splits the
         * records into two groups for no reason, and leaves a version list where
         * half the entries answer "what changed here?" with "nothing". If the
         * intent was to record a note against the current version, that is not
         * something a new version should be minted for.
         */
        if ($diff->isEmpty() && $current !== null) {
            throw ValidationException::withMessages(['fields' =>
                'That is the field list this contract already has, so there is nothing to '
                .'publish. A version that changes nothing still splits the records that follow '
                .'it away from the ones before, and the version list stops being a record of '
                .'what actually changed.',
            ]);
        }

        $version = DB::transaction(function () use ($contract, $fields, $input, $request, $diff) {
            $version = $this->publish($contract, $fields, $input['notes'] ?? null, $request);

            /*
             * The diff goes in the audit metadata, not in a column.
             *
             * §10 wants the record of the act, and "what changed" is the act.
             * Storing it on the version as well would be a second copy of
             * something derivable from two immutable rows — and the audit entry
             * is where somebody looks to answer "who added the bank account
             * field and when", which is a different question from "what does
             * version 4 contain".
             */
            $this->audit->log('contract.version_published', $contract, metadata: [
                'version' => $version->version,
                'notes'   => $version->notes,
                'diff'    => $diff->toArray(),
            ]);

            return $version;
        });

        return response()->json([
            'data' => [
                'contract' => $this->present($contract->fresh(), detailed: true),
                'diff'     => $diff->toArray(),
                'version'  => $version->version,
            ],
        ], Response::HTTP_CREATED);
    }

    // ── shared ───────────────────────────────────────────────────

    /**
     * Write the version and point the contract at it.
     *
     * Publishing IS activation, which is the one place this follows
     * `AutomationsController::addVersion` exactly: the new version becomes
     * current in the same transaction that creates it, so there is no moment
     * where a contract has a version nobody is using and no way to say so.
     */
    private function publish(Contract $contract, FieldSet $fields, ?string $notes, Request $request): ContractVersion
    {
        $next = (int) ($contract->versions()->max('version') ?? 0) + 1;

        $version = ContractVersion::create([
            'contract_id' => $contract->getKey(),
            'version'     => $next,
            'fields'      => $fields->toArray(),
            'notes'       => $notes,
            'created_by'  => $request->user()->getKey(),
        ]);

        $contract->forceFill(['current_version_id' => $version->getKey()])->save();

        return $version;
    }

    /**
     * Names are unique per organization — checked with the tenant column spelt
     * out rather than trusting the global scope, because `Rule::unique` builds
     * its own query and Eloquent's scope never sees it.
     */
    private function uniqueName(?Contract $ignoring = null): Unique
    {
        $rule = Rule::unique('contracts', 'name')
            ->where('organization_id', $this->tenant->idOrFail());

        return $ignoring === null ? $rule : $rule->ignore($ignoring->getKey());
    }

    private function present(Contract $contract, bool $detailed = false): array
    {
        $current = $contract->currentVersion;

        $base = [
            'id'          => $contract->id,
            'name'        => $contract->name,
            'description' => $contract->description,
            'version'     => $current?->version,
            'versions_count' => $contract->versions_count ?? $contract->versions()->count(),
            // What onboarding would ask today, counted. The number people check
            // at a glance, and it is the LIVE count — a list showing retired
            // fields in the total would overstate every template that has ever
            // been tidied up.
            'field_count' => $current === null ? 0 : count($current->liveFields()),
            'updated_at'  => $contract->updated_at?->toIso8601String(),
        ];

        if (! $detailed) {
            return $base;
        }

        return $base + [
            /*
             * The full stored list, retired entries included.
             *
             * The designer has to show a retired field — it is still part of the
             * template, it is what stops the key being reused, and un-retiring it
             * is a legitimate edit. `retired` is on every entry, so the screen
             * can draw the distinction the plan draws.
             */
            'fields' => $current?->fieldSet()->toArray() ?? [],
            'current_version' => $current === null ? null : [
                'id'         => $current->id,
                'version'    => $current->version,
                'notes'      => $current->notes,
                'created_at' => $current->created_at?->toIso8601String(),
            ],
        ];
    }

    private function presentVersion(ContractVersion $version, Contract $contract, ?ContractVersion $previous): array
    {
        return [
            'id'         => $version->id,
            'version'    => $version->version,
            'is_current' => $version->id === $contract->current_version_id,
            'notes'      => $version->notes,
            'created_at' => $version->created_at?->toIso8601String(),
            'created_by' => $version->createdBy?->name,
            'fields'     => $version->fieldSet()->toArray(),
            /*
             * What this publish changed, against the version before it.
             *
             * Computed on read from two immutable rows, so it cannot go stale —
             * see VersionDiff. Version 1's diff is everything, because a first
             * version genuinely does add every field it has.
             */
            'diff' => VersionDiff::between($previous?->fieldSet(), $version->fieldSet())->toArray(),
        ];
    }
}
