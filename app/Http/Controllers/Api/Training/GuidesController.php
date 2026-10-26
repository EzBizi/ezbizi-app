<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Support\Audit\AuditLogger;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\GuidePlaceReach;
use App\Support\Training\GuideScreens;
use App\Support\Training\TrainingMediaLinks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training guides — list, create, show, update, destroy.
 *
 * Screens are one JSON array on the guide row (see the migration and the
 * model's `screenCount()`); there is no separate screen endpoint. List
 * always applies the shared place-scope engine — see the comment in [index].
 *
 * create/update/delete each write an `audit_events` row (§2.2) — the shape
 * copied from CompaniesController/JobsController (`logChange`, called
 * before `save()` so the dirty diff is still there to capture) and
 * InspectionDefinitionsController (`log` on create, and on delete before
 * the row is gone so `target_label` can still read the title).
 */
class GuidesController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * An `exists` rule scoped to this organization.
     *
     * Laravel's plain `exists` runs a raw query with no tenant scope, so naming
     * another organization's id passes validation and only fails later as a 404.
     * Two different answers for two different ids is an existence oracle — it
     * tells a caller which ids are real somewhere else on the platform.
     */
    private function existsInTenant(string $table): Exists
    {
        return Rule::exists($table, 'id')
            ->where('organization_id', $this->tenant->idOrFail());
    }

    /** GET /api/v1/training/guides */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingGuide::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        // Not a new engine, and deliberately not a TrainingVisibility
        // wrapper — a controller ruling decided against that indirection.
        // InventoryVisibility already IS the shared place-scope engine:
        // organization rows reach the whole tenant, a company's General
        // reaches anyone who can see that company, a franchise row reaches
        // only that franchise. Services and Fleet call it for the same
        // reason. It is misnamed for a shared engine, but renaming it
        // would edit Inventory's and Services' files, which are not this
        // module's to change.
        $query = InventoryVisibility::constrain(TrainingGuide::query(), $user, $scope);

        $term = trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:200']])['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('summary', 'like', $like));
        }

        $guides = $query
            ->orderBy('title')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($guides->items())->map(
                fn (TrainingGuide $g) => $this->present($g),
            ),
            'meta' => $this->pageMeta($guides),
        ]);
    }

    /** POST /api/v1/training/guides */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        if (array_key_exists('screens', $input)) {
            $input['screens'] = GuideScreens::normalize($input['screens'], null);
        }

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'guide');

        if ($company === null) {
            $this->authorize('createOnOrganization', TrainingGuide::class);
        } else {
            $this->authorize('create', [TrainingGuide::class, $company, $franchise]);
        }

        $guide = DB::transaction(function () use ($input, $company, $franchise) {
            $guide = TrainingGuide::create([
                'company_id' => $company?->getKey(),
                'franchise_id' => $franchise?->getKey(),
                'title' => $input['title'],
                'summary' => $input['summary'] ?? null,
                'status' => $input['status'] ?? TrainingGuide::DRAFT,
                'screens' => $input['screens'] ?? [],
            ]);

            $this->audit->log('guide.created', $guide);

            return $guide;
        });

        return response()->json(
            ['data' => $this->present($guide->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/training/guides/{guide} */
    public function show(TrainingGuide $guide): JsonResponse
    {
        $this->authorize('view', $guide);

        return response()->json(['data' => $this->present($guide)]);
    }

    /** PATCH /api/v1/training/guides/{guide} */
    public function update(Request $request, TrainingGuide $guide): JsonResponse
    {
        $this->authorize('update', $guide);

        $input = $request->validate($this->rules(updating: true));

        if (array_key_exists('screens', $input)) {
            $input['screens'] = GuideScreens::normalize($input['screens'], $guide);
        }

        if (array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input)) {
            $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $guide->company_id;
            $franchiseId = array_key_exists('franchise_id', $input) ? $input['franchise_id'] : $guide->franchise_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);

            PlaceInView::assert($request->user(), $company, $franchise, 'guide');

            $placeChanged = $companyId !== $guide->company_id || $franchiseId !== $guide->franchise_id;

            if ($placeChanged) {
                if ($company === null) {
                    $this->authorize('createOnOrganization', TrainingGuide::class);
                } else {
                    $this->authorize('create', [TrainingGuide::class, $company, $franchise]);
                }

                $blameField = array_key_exists('company_id', $input) ? 'company_id' : 'franchise_id';
                $this->assertMoveDoesNotStrandTests($guide, $companyId, $franchiseId, $blameField);
            }
        }

        DB::transaction(function () use ($guide, $input) {
            $guide->fill($input);

            // No dirty attributes means nothing actually changed (e.g. a PATCH
            // re-sending the same title) — same as CompaniesController::update:
            // skip both the write and the audit row rather than bumping
            // `updated_at` and logging a change that never happened.
            if (! $guide->isDirty()) {
                return;
            }

            $this->audit->logChange('guide.updated', $guide);
            $guide->save();
        });

        return response()->json(['data' => $this->present($guide->fresh())]);
    }

    /**
     * Refuses a guide move that would strand a test pointing at it.
     *
     * `TestsController::assertGuideCompatible` guards this rule from the
     * TEST's side — a test may only be authored or moved onto a guide that
     * still reaches it. It has no way to stop the GUIDE moving out from
     * under a test that already passed that check, which is exactly the
     * auditor's proven case: an organization guide behind an organization
     * `requires_guide: true` test, the guide moved to a franchise, and a
     * learner elsewhere left with a 404 on the guide and a permanent 403 on
     * the test — `assertGuideGateHasATarget`'s own docblock names a test
     * nobody can ever pass as exactly the dead end this module refuses to
     * create.
     *
     * So the guide's side is guarded here, asking the identical question
     * `GuidePlaceReach` already answers for the test's side — never a
     * second copy of "is this guide reachable from that place" — for every
     * test currently pointing at this guide, in either direction: one that
     * merely references it, or one that requires finishing it first. Not a
     * detach, not a cascade onto the tests: refused, naming what would be
     * stranded, so the author can act instead of the module acting for
     * them.
     */
    private function assertMoveDoesNotStrandTests(
        TrainingGuide $guide,
        ?string $newCompanyId,
        ?string $newFranchiseId,
        string $field,
    ): void {
        $stranded = TrainingTest::query()
            ->where('guide_id', $guide->getKey())
            ->get()
            ->reject(fn (TrainingTest $test) => GuidePlaceReach::covers(
                $newCompanyId, $newFranchiseId,
                $test->company_id, $test->franchise_id,
            ));

        if ($stranded->isEmpty()) {
            return;
        }

        $names = $stranded->pluck('title')->implode(', ');

        throw ValidationException::withMessages([
            $field => 'Moving this guide would leave the following test(s) pointing at a guide some '
                ."of their own takers could no longer reach: {$names}. Move those tests first, or "
                .'leave this guide where it is.',
        ]);
    }

    /** DELETE /api/v1/training/guides/{guide} */
    public function destroy(TrainingGuide $guide): Response
    {
        $this->authorize('delete', $guide);

        DB::transaction(function () use ($guide) {
            // Logged before the delete, same as
            // InspectionDefinitionsController::destroy — target_label reads
            // `title` off the model, which is gone once `delete()` runs, and
            // `training_progress`/`training_assignments` cascade with this row
            // (migration `create_training_tables`), so this is the only record
            // that a guide with completions behind it ever existed.
            $this->audit->log('guide.deleted', $guide, metadata: [
                'title' => $guide->title,
            ]);

            $guide->delete();
        });

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:191'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'string', Rule::in([TrainingGuide::DRAFT, TrainingGuide::PUBLISHED])],
            ...GuideScreens::rules(),
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
        ];
    }

    /** @return array<string, mixed> */
    private function present(TrainingGuide $guide): array
    {
        return [
            'id' => $guide->id,
            'title' => $guide->title,
            'summary' => $guide->summary,
            'status' => $guide->status,
            'company_id' => $guide->company_id,
            'franchise_id' => $guide->franchise_id,
            'screen_count' => $guide->screenCount(),
            // Signed links for images the guide holds, minted per read — the
            // caller of present() has already authorised this read.
            'screens' => TrainingMediaLinks::resolve($guide->screens ?? [], $guide),
        ];
    }
}
