<?php

namespace App\Http\Controllers\Api;

use App\Models\InspectionCode;
use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Support\Access\ScopeReach;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Inspection\BindableFields;
use App\Support\Inspection\FieldSet;
use App\Support\Inspection\FieldType;
use App\Support\Inspection\InspectionScope;
use App\Support\Inspection\LayoutPreset;
use App\Support\Inspection\WorkingTitle;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builder: the working copy of a named inspection, and the act of publishing it.
 *
 * PATCH writes the draft. POST publish copies that draft onto a new
 * written-once version and points the name at it. Unpublish only clears
 * published_at — versions and open runs stay where they are. DELETE is
 * only for a draft that has never been published.
 */
class InspectionDefinitionsController extends Controller
{
    private const MAX_COVER_KILOBYTES = 1024;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $tenant,
        private readonly FileStore $files,
        private readonly InspectionScope $scope,
        private readonly ScopeReach $reach,
    ) {}

    public function catalogue(): JsonResponse
    {
        $this->authorize('viewAny', InspectionDefinition::class);

        $types = [];
        foreach (FieldType::ALL as $key => $label) {
            $types[] = [
                'key' => $key,
                'label' => $label,
                'takes_options' => FieldType::takesOptions($key),
                'allows_multiple' => FieldType::allowsMultiple($key),
            ];
        }

        $presets = [];
        foreach (LayoutPreset::LABELS as $key => $label) {
            $presets[] = ['key' => $key, 'label' => $label];
        }

        return response()->json(['data' => [
            'field_types' => $types,
            'layout_presets' => $presets,
            'bindable' => BindableFields::catalogue(),
            'scopes' => $this->scopeOptions(),
            // The library, so a field can cite without a second round trip.
            // Small by nature — A1's whole tool has eight codes.
            'codes' => InspectionCode::query()->orderBy('code')->get()
                ->map(fn (InspectionCode $c) => [
                    'id' => $c->id, 'code' => $c->code, 'title' => $c->title,
                ])->values(),
        ]]);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', InspectionDefinition::class);

        $query = InspectionDefinition::query()
            ->with('currentVersion')
            ->orderBy('name');

        // The Builder lists what exists HERE, the same as the picker does.
        // Without this a franchise manager browses every company's inspections
        // and can open one they may not run - a disclosure, not an annoyance.
        $this->scope->constrain($query, request()->user());

        $rows = $query->get()
            ->map(fn (InspectionDefinition $d) => $this->present($d))
            ->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', InspectionDefinition::class);

        $definition = DB::transaction(function () use ($request) {
            $definition = InspectionDefinition::create([
                'name' => WorkingTitle::next(),
                'layout_preset' => LayoutPreset::DEFAULT,
                'screens' => [],
                'created_by' => $request->user()->id,
            ]);

            $this->audit->log('inspection.created', $definition);

            return $definition;
        });

        return response()->json(
            ['data' => $this->present($definition->load('currentVersion'), detailed: true)],
            Response::HTTP_CREATED,
        );
    }

    public function show(InspectionDefinition $inspectionDefinition): JsonResponse
    {
        $this->authorize('view', $inspectionDefinition);

        return response()->json([
            'data' => $this->present($inspectionDefinition->load('currentVersion'), detailed: true),
        ]);
    }

    public function update(Request $request, InspectionDefinition $inspectionDefinition): JsonResponse
    {
        $this->authorize('update', $inspectionDefinition);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150', $this->uniqueName($inspectionDefinition)],
            'intro' => ['sometimes', 'nullable', 'string'],
            'layout_preset' => ['sometimes', 'string', Rule::in(LayoutPreset::keys())],
            'screens' => ['sometimes', 'array'],
        ]);

        if (array_key_exists('screens', $validated)) {
            $validated['screens'] = FieldSet::fromInput($validated['screens'])->toArray();
        }

        $validated = [...$validated, ...$this->scopeFrom($request, $inspectionDefinition)];

        $inspectionDefinition->fill($validated)->save();

        $this->audit->log('inspection.updated', $inspectionDefinition);

        return response()->json([
            'data' => $this->present($inspectionDefinition->fresh()->load('currentVersion'), detailed: true),
        ]);
    }

    /**
     * The place this inspection belongs to, checked before it is written.
     *
     * TWO separate checks, because they refuse for different reasons and
     * collapsing them into one would lose the second:
     *
     *   1. The place must be THIS organization's. `scope_id` has no foreign key
     *      - it names a row in one of two tables depending on `scope_type` - so
     *      nothing else would catch an id from elsewhere on the platform. It
     *      would never match anything, but a tenant boundary that is stated and
     *      not enforced is one that stops being true later.
     *      AutomationsController makes the same check on the same column.
     *
     *   2. The author must REACH the place. The first check PASSES for a
     *      sibling franchise - it is this organization's - so without this a
     *      franchise manager could scope the group's inspection to a franchise
     *      they have nothing to do with and take it from everybody else.
     *
     * Reach here, and deliberately NOT InspectionScope: authoring asks "may I
     * act on that place", which is what ScopeReach answers. Visibility asks a
     * different question and gets a different answer - see InspectionScope.
     *
     * @return array{scope_type: ?string, scope_id: ?string}
     */
    /**
     * Forget citations whose code no longer exists.
     *
     * Only at publish — see the call site. One query, not one per field.
     *
     * @param  list<array<string, mixed>>  $screens
     * @return list<array<string, mixed>>
     */
    private static function dropMissingCitations(array $screens): array
    {
        $ids = [];
        foreach ($screens as $screen) {
            foreach (($screen['fields'] ?? []) as $field) {
                if (is_array($field) && ($field['code_id'] ?? null) !== null) {
                    $ids[] = $field['code_id'];
                }
            }
        }

        if ($ids === []) {
            return $screens;
        }

        $alive = InspectionCode::query()
            ->whereIn('id', array_unique($ids))
            ->pluck('id')
            ->all();

        foreach ($screens as $si => $screen) {
            foreach (($screen['fields'] ?? []) as $fi => $field) {
                $codeId = is_array($field) ? ($field['code_id'] ?? null) : null;

                if ($codeId !== null && ! in_array($codeId, $alive, true)) {
                    $screens[$si]['fields'][$fi]['code_id'] = null;
                }
            }
        }

        return $screens;
    }

    /**
     * Copy each cited code's WORDING into the version being written.
     *
     * The draft carries `code_id`, a pointer. A version carries the words,
     * because a version is written once and a report pins one — so correcting a
     * typo in the library in September cannot change what an August report told
     * a customer the violation was. Exactly the reason a bound customer name is
     * frozen at submit.
     *
     * A code that has since been DELETED publishes without a citation rather
     * than refusing. Refusing would let removing one library row block every
     * form that ever mentioned it, and the field keeps its question either way —
     * losing the reference is the smaller loss, and the visible one.
     *
     * One query for the whole definition rather than one per field: the A1
     * inspection cites the same handful of codes from a hundred and twenty
     * fields, and a lookup per field is a hundred and twenty round trips to
     * publish one form.
     *
     * @param  list<array<string, mixed>>  $screens
     * @return list<array<string, mixed>>
     */
    private static function freezeCitations(array $screens): array
    {
        $ids = [];
        foreach ($screens as $screen) {
            foreach (($screen['fields'] ?? []) as $field) {
                if (is_array($field) && ($field['code_id'] ?? null) !== null) {
                    $ids[] = $field['code_id'];
                }
            }
        }

        if ($ids === []) {
            return $screens;
        }

        $codes = InspectionCode::query()
            ->whereIn('id', array_unique($ids))
            ->get()
            ->keyBy('id');

        foreach ($screens as $si => $screen) {
            foreach (($screen['fields'] ?? []) as $fi => $field) {
                $codeId = is_array($field) ? ($field['code_id'] ?? null) : null;
                $code = $codeId === null ? null : $codes->get($codeId);

                if ($code === null) {
                    continue;
                }

                $screens[$si]['fields'][$fi]['code'] = [
                    'code' => $code->code,
                    'title' => $code->title,
                    'body' => $code->body,
                ];
            }
        }

        return $screens;
    }

    private function scopeFrom(Request $request, ?InspectionDefinition $existing): array
    {
        $input = $request->validate([
            'scope_type' => ['sometimes', 'nullable', Rule::in([
                InspectionDefinition::SCOPE_COMPANY,
                InspectionDefinition::SCOPE_FRANCHISE,
            ])],
            'scope_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        $type = array_key_exists('scope_type', $input)
            ? $input['scope_type']
            : $existing?->scope_type;
        $id = array_key_exists('scope_id', $input)
            ? $input['scope_id']
            : $existing?->scope_id;

        // Clearing the type clears the id with it, or the row keeps a franchise
        // id under a null type and the next reader has to guess which one won.
        if (array_key_exists('scope_type', $input) && $input['scope_type'] === null) {
            $id = null;
        }

        if ($type === null && $id !== null) {
            throw ValidationException::withMessages([
                'scope_id' => 'Choose a company or a franchise before choosing which one.',
            ]);
        }

        if ($type !== null && $id === null) {
            throw ValidationException::withMessages([
                'scope_id' => "Choose which {$type} this inspection belongs to.",
            ]);
        }

        if ($type !== null) {
            $table = $type === InspectionDefinition::SCOPE_COMPANY ? 'companies' : 'franchises';

            $exists = DB::table($table)
                ->where('id', $id)
                ->where('organization_id', $this->tenant->idOrFail())
                ->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'scope_id' => "That is not one of this organization's {$table}.",
                ]);
            }

            $this->reach->assertCovers($request->user(), $type, $id, 'scope_id');
        }

        return ['scope_type' => $type, 'scope_id' => $id];
    }

    /**
     * The places this author may give an inspection to, flat and ordered.
     *
     * `ScopeReach::optionsFor` returns three separate lists; a dropdown wants
     * one. The organization comes first and ONLY when this person may act on it
     * - `optionsFor` answers null otherwise, and offering "Whole organization"
     * to somebody the save would then refuse is worse than not offering it.
     *
     * A company and its only franchise are very often called the same thing, so
     * the label is not unique and the client keys on type:id rather than on it.
     *
     * @return list<array{type: ?string, id: ?string, label: string}>
     */
    private function scopeOptions(): array
    {
        $options = $this->reach->optionsFor(request()->user());
        $scopes = [];

        if ($options['organization'] !== null) {
            $scopes[] = ['type' => null, 'id' => null, 'label' => 'Whole organization'];
        }

        foreach ($options['companies'] as $company) {
            $scopes[] = [
                'type' => InspectionDefinition::SCOPE_COMPANY,
                'id' => $company['id'],
                'label' => $company['name'],
            ];
        }

        foreach ($options['franchises'] as $franchise) {
            $scopes[] = [
                'type' => InspectionDefinition::SCOPE_FRANCHISE,
                'id' => $franchise['id'],
                'label' => $franchise['name'],
            ];
        }

        return $scopes;
    }

    public function publish(InspectionDefinition $inspectionDefinition): JsonResponse
    {
        $this->authorize('update', $inspectionDefinition);

        if (WorkingTitle::isPlaceholder($inspectionDefinition->name)
            || trim($inspectionDefinition->name) === '') {
            throw ValidationException::withMessages([
                'name' => 'Give this inspection a name before publishing, so the picker is not a blank row.',
            ]);
        }

        /*
         * A citation whose code has since been deleted is DROPPED here, before
         * validation, rather than refusing the publish.
         *
         * FieldSet is strict about `code_id` on the way IN, which is right: an
         * author picking a code should be told immediately if it is not one of
         * theirs. It is the wrong answer at publish, where the pointer was valid
         * when it was written and somebody has since tidied the library —
         * refusing would let removing one row block every form that ever
         * mentioned it, and the field keeps its question either way.
         */
        $screens = FieldSet::fromInput(
            self::dropMissingCitations($inspectionDefinition->screens ?? []),
        )->toArray();
        $preset = $inspectionDefinition->layout_preset ?: LayoutPreset::DEFAULT;

        if (! in_array($preset, LayoutPreset::keys(), true)) {
            throw ValidationException::withMessages([
                'layout_preset' => 'Choose how this inspection should look: '
                    .implode(', ', LayoutPreset::keys()).'.',
            ]);
        }

        DB::transaction(function () use ($inspectionDefinition, $screens, $preset) {
            $locked = InspectionDefinition::query()
                ->lockForUpdate()
                ->findOrFail($inspectionDefinition->id);

            $next = (int) $locked->versions()->max('version') + 1;

            [$coverPath, $coverDisk] = $this->copyCoverOntoVersion($locked);

            $version = InspectionDefinitionVersion::create([
                'inspection_definition_id' => $locked->id,
                'version' => $next,
                'layout_preset' => $preset,
                'screens' => self::freezeCitations($screens),
                'intro' => $locked->intro,
                'cover_path' => $coverPath,
                'cover_disk' => $coverDisk,
                'published_by' => request()->user()->id,
            ]);

            $locked->forceFill([
                'current_version_id' => $version->id,
                'published_at' => now(),
            ])->save();

            $this->audit->log('inspection.published', $locked, metadata: [
                'version' => $next,
            ]);
        });

        return response()->json([
            'data' => $this->present(
                $inspectionDefinition->fresh()->load('currentVersion'),
                detailed: true,
            ),
        ]);
    }

    public function unpublish(InspectionDefinition $inspectionDefinition): JsonResponse
    {
        $this->authorize('update', $inspectionDefinition);

        $inspectionDefinition->forceFill(['published_at' => null])->save();

        $this->audit->log('inspection.unpublished', $inspectionDefinition);

        return response()->json([
            'data' => $this->present(
                $inspectionDefinition->fresh()->load('currentVersion'),
                detailed: true,
            ),
        ]);
    }

    /**
     * A never-published draft. Once a version exists, unpublish is the way
     * off the picker — deleting would take reports and in-progress work.
     */
    public function destroy(InspectionDefinition $inspectionDefinition): JsonResponse
    {
        $this->authorize('delete', $inspectionDefinition);

        if ($inspectionDefinition->published_at !== null
            || $inspectionDefinition->current_version_id !== null
            || $inspectionDefinition->versions()->exists()
            || $inspectionDefinition->runs()->exists()
            || $inspectionDefinition->reports()->exists()) {
            return response()->json([
                'message' => 'This inspection has been published. Unpublish it to take it off the picker — deleting it would take the history of reports and in-progress work with it.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        DB::transaction(function () use ($inspectionDefinition) {
            $cover = $inspectionDefinition->cover_path;
            $disk = $inspectionDefinition->cover_disk;

            $this->audit->log('inspection.deleted', $inspectionDefinition, metadata: [
                'name' => $inspectionDefinition->name,
            ]);

            $inspectionDefinition->grants()->delete();
            $inspectionDefinition->delete();

            if ($cover) {
                $this->files->delete($cover, $disk);
            }
        });

        return response()->json(['message' => 'Draft deleted.']);
    }

    public function storeCover(Request $request, InspectionDefinition $inspectionDefinition): JsonResponse
    {
        $this->authorize('update', $inspectionDefinition);

        $request->validate([
            'cover' => [
                'required', 'file', 'mimes:png,jpg,jpeg,webp',
                'max:'.self::MAX_COVER_KILOBYTES,
            ],
        ]);

        $previous = $inspectionDefinition->cover_path;
        $previousDisk = $inspectionDefinition->cover_disk;

        $path = $this->files->put(
            $request->file('cover'),
            $this->files->directoryFor('inspection-covers', $request->user()->organization_id),
        );

        $inspectionDefinition->forceFill([
            'cover_path' => $path,
            'cover_disk' => $this->files->defaultDisk(),
        ])->save();

        if ($previous !== null && $previous !== $path) {
            $this->files->delete($previous, $previousDisk);
        }

        $this->audit->log('inspection.cover_changed', $inspectionDefinition);

        return response()->json([
            'data' => $this->present($inspectionDefinition->fresh()->load('currentVersion'), detailed: true),
        ]);
    }

    public function showCover(InspectionDefinition $inspectionDefinition): Response
    {
        $this->authorize('view', $inspectionDefinition);

        abort_if(
            $inspectionDefinition->cover_path === null,
            404,
            'This inspection has no cover.',
        );

        return $this->files->serve(
            $inspectionDefinition->cover_path,
            $inspectionDefinition->cover_disk,
            disposition: 'inline',
        );
    }

    /**
     * @return array{0: ?string, 1: ?string} path, disk
     */
    private function copyCoverOntoVersion(InspectionDefinition $definition): array
    {
        if ($definition->cover_path === null) {
            return [null, null];
        }

        $bytes = $this->files->contents($definition->cover_path, $definition->cover_disk);
        if ($bytes === null) {
            return [null, null];
        }

        $extension = pathinfo($definition->cover_path, PATHINFO_EXTENSION) ?: 'png';
        $path = $this->files->putContents(
            $bytes,
            $this->files->directoryFor('inspection-covers', $definition->organization_id),
            $extension,
            $definition->cover_disk,
        );

        return [$path, $definition->cover_disk ?: $this->files->defaultDisk()];
    }

    private function uniqueName(?InspectionDefinition $ignoring = null): Unique
    {
        $rule = Rule::unique('inspection_definitions', 'name')
            ->where('organization_id', $this->tenant->id());

        if ($ignoring !== null) {
            $rule->ignore($ignoring->id);
        }

        return $rule;
    }

    /** @return array<string, mixed> */
    private function present(InspectionDefinition $definition, bool $detailed = false): array
    {
        $row = [
            'id' => $definition->id,
            'name' => $definition->name,
            // Always present, both keys, nulls and all. A client made to tell
            // "the organization" from "the server did not say" would guess.
            'scope' => [
                'type' => $definition->scope_type,
                'id' => $definition->scope_id,
            ],
            'intro' => $definition->intro,
            'layout_preset' => $definition->layout_preset ?: LayoutPreset::DEFAULT,
            'screens' => $definition->screens ?? [],
            'published' => $definition->published_at !== null,
            'published_at' => $definition->published_at?->toIso8601String(),
            'current_version' => $definition->currentVersion?->version,
            'has_cover' => $definition->cover_path !== null && $definition->cover_path !== '',
        ];

        return $row;
    }
}
