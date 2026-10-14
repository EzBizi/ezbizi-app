<?php

namespace App\Http\Controllers\Api;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Guideline;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guidelines (Jose, 2026-10-14): situation cards with step-by-step answers.
 * Shaped after AssistantEntriesController - same place engine on the list,
 * same ownership checks on a write, same audit rows - plus one image per card.
 */
class GuidelinesController extends Controller
{
    private const IMAGE_KILOBYTES = 5120;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
        private readonly FileStore $files,
    ) {}

    /** GET /api/v1/guidelines */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Guideline::class);

        $user = $request->user();

        return response()->json([
            'data' => InventoryVisibility::constrain(Guideline::query(), $user, ViewScope::forUser($user))
                ->orderBy('position')->orderBy('title')
                ->get()
                ->map(fn (Guideline $g) => $this->present($g)),
        ]);
    }

    /** POST /api/v1/guidelines */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $company = isset($input['company_id']) ? Company::findOrFail($input['company_id']) : null;
        $franchise = isset($input['franchise_id']) ? Franchise::findOrFail($input['franchise_id']) : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'guideline');

        $company === null
            ? $this->authorize('createOnOrganization', Guideline::class)
            : $this->authorize('create', [Guideline::class, $company, $franchise]);

        $guideline = DB::transaction(function () use ($input) {
            $guideline = Guideline::create($input);
            $this->audit->log('guideline.created', $guideline);

            return $guideline;
        });

        return response()->json(['data' => $this->present($guideline->fresh())], Response::HTTP_CREATED);
    }

    /** PATCH /api/v1/guidelines/{guideline} */
    public function update(Request $request, Guideline $guideline): JsonResponse
    {
        $this->authorize('update', $guideline);

        $input = $request->validate($this->rules(updating: true));

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $guideline->company_id;
            $franchiseId = array_key_exists('franchise_id', $input) ? $input['franchise_id'] : $guideline->franchise_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            PlaceInView::assert($request->user(), $company, $franchise, 'guideline');

            // Moving is writing somewhere new: the new place must be writable too.
            if ($companyId !== $guideline->company_id || $franchiseId !== $guideline->franchise_id) {
                $company === null
                    ? $this->authorize('createOnOrganization', Guideline::class)
                    : $this->authorize('create', [Guideline::class, $company, $franchise]);
            }
        }

        DB::transaction(function () use ($guideline, $input) {
            $guideline->fill($input);

            if (! $guideline->isDirty()) {
                return;
            }

            $this->audit->logChange('guideline.updated', $guideline);
            $guideline->save();
        });

        return response()->json(['data' => $this->present($guideline->fresh())]);
    }

    /** DELETE /api/v1/guidelines/{guideline} */
    public function destroy(Guideline $guideline): Response
    {
        $this->authorize('delete', $guideline);

        $path = $guideline->image_path;

        DB::transaction(function () use ($guideline) {
            $this->audit->log('guideline.deleted', $guideline, metadata: ['title' => $guideline->title]);
            $guideline->delete();
        });

        $this->files->delete($path);

        return response()->noContent();
    }

    /** GET /api/v1/guidelines/{guideline}/image */
    public function image(Guideline $guideline): Response
    {
        $this->authorize('view', $guideline);

        abort_if($guideline->image_path === null, 404);

        // Inline is safe: only validated images are ever stored here.
        return $this->files->serve($guideline->image_path, disposition: 'inline');
    }

    /** POST /api/v1/guidelines/{guideline}/image */
    public function uploadImage(Request $request, Guideline $guideline): JsonResponse
    {
        $this->authorize('update', $guideline);

        $request->validate([
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.self::IMAGE_KILOBYTES],
        ]);

        abort_if(@getimagesize($request->file('image')->getRealPath()) === false, 422, 'That file is not a readable image.');

        $previous = $guideline->image_path;
        $path = $this->files->put(
            $request->file('image'),
            $this->files->directoryFor('guidelines', $guideline->organization_id),
        );

        $guideline->forceFill(['image_path' => $path])->save();
        $this->audit->log('guideline.image_changed', $guideline);

        // Removed only after the new one is stored.
        if ($previous !== null && $previous !== $path) {
            $this->files->delete($previous);
        }

        return response()->json(['data' => $this->present($guideline->fresh())]);
    }

    /** DELETE /api/v1/guidelines/{guideline}/image */
    public function destroyImage(Guideline $guideline): JsonResponse
    {
        $this->authorize('update', $guideline);

        $path = $guideline->image_path;
        $guideline->forceFill(['image_path' => null])->save();
        $this->audit->log('guideline.image_removed', $guideline);
        $this->files->delete($path);

        return response()->json(['data' => $this->present($guideline->fresh())]);
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';
        $inTenant = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $this->tenant->idOrFail());

        return [
            'title' => [$required, 'string', 'max:'.Guideline::MAX_TITLE],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.Guideline::MAX_SUMMARY],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'steps' => [$required, 'array', 'max:'.Guideline::MAX_STEPS],
            'steps.*' => ['string', 'max:'.Guideline::MAX_STEP],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.Guideline::MAX_NOTES],
            'position' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'company_id' => ['sometimes', 'nullable', 'uuid', $inTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $inTenant('franchises')],
        ];
    }

    /** @return array<string, mixed> */
    private function present(Guideline $guideline): array
    {
        return [
            ...$guideline->only([
                'id', 'title', 'summary', 'color', 'steps', 'notes', 'position', 'company_id', 'franchise_id',
            ]),
            'has_image' => $guideline->image_path !== null,
            // Changes with each upload, so the client knows to refetch.
            'image_version' => $guideline->image_path === null ? null : md5($guideline->image_path),
        ];
    }
}
