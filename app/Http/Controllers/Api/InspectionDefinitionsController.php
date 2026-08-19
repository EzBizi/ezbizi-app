<?php

namespace App\Http\Controllers\Api;

use App\Models\InspectionDefinition;
use App\Models\InspectionDefinitionVersion;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Inspection\BindableFields;
use App\Support\Inspection\FieldSet;
use App\Support\Inspection\FieldType;
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
        ]]);
    }

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', InspectionDefinition::class);

        $rows = InspectionDefinition::query()
            ->with('currentVersion')
            ->orderBy('name')
            ->get()
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

        $inspectionDefinition->fill($validated)->save();

        $this->audit->log('inspection.updated', $inspectionDefinition);

        return response()->json([
            'data' => $this->present($inspectionDefinition->fresh()->load('currentVersion'), detailed: true),
        ]);
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

        $screens = FieldSet::fromInput($inspectionDefinition->screens ?? [])->toArray();
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
                'screens' => $screens,
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
