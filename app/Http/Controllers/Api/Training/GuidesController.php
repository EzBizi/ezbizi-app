<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\TrainingGuide;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training guides — list, create, show, update, destroy.
 *
 * Screens are one JSON array on the guide row (see the migration and the
 * model's `screenCount()`); there is no separate screen endpoint. List
 * always applies the shared place-scope engine — see the comment in [index].
 */
class GuidesController extends Controller
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * An `exists` rule scoped to this organization.
     *
     * Laravel's plain `exists` runs a raw query with no tenant scope, so naming
     * another organization's id passes validation and only fails later as a 404.
     * Two different answers for two different ids is an existence oracle — it
     * tells a caller which ids are real somewhere else on the platform.
     */
    private function existsInTenant(string $table): \Illuminate\Validation\Rules\Exists
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
        // only that franchise. ServiceVisibility delegates to it for the
        // same reason. It is misnamed for a shared engine, but renaming it
        // would edit Inventory's and Services' files, which are not this
        // module's to change.
        $query = InventoryVisibility::constrain(TrainingGuide::query(), $user, $scope);

        $guides = $query
            ->orderBy('title')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($guides->items())->map(
                fn (TrainingGuide $g) => $this->present($g),
            ),
            'meta' => [
                'total' => $guides->total(),
                'per_page' => $guides->perPage(),
                'current_page' => $guides->currentPage(),
                'last_page' => $guides->lastPage(),
            ],
        ]);
    }

    /** POST /api/v1/training/guides */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        if (array_key_exists('screens', $input)) {
            $input['screens'] = $this->normalizeScreens($input['screens']);
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

        $guide = TrainingGuide::create([
            'company_id' => $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'title' => $input['title'],
            'summary' => $input['summary'] ?? null,
            'status' => $input['status'] ?? TrainingGuide::DRAFT,
            'screens' => $input['screens'] ?? [],
        ]);

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
            $input['screens'] = $this->normalizeScreens($input['screens']);
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
            }
        }

        $guide->fill($input);
        $guide->save();

        return response()->json(['data' => $this->present($guide->fresh())]);
    }

    /** DELETE /api/v1/training/guides/{guide} */
    public function destroy(TrainingGuide $guide): Response
    {
        $this->authorize('delete', $guide);

        $guide->delete();

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
            'screens' => ['sometimes', 'array', 'max:200'],
            'screens.*.title' => ['required', 'string', 'max:200'],
            'screens.*.body' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'screens.*.media_kind' => ['sometimes', 'string', Rule::in(['none', 'image', 'video'])],
            // A bad scheme is refused HERE, at authoring time, rather than
            // merely defended against on every read — see [normalizeScreens]'s
            // own docblock and the client's `isHttpUrl` (guide_player.dart),
            // which does the identical check as a second, independent gate.
            'screens.*.media_url' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    // PHP's parse_url() does not lowercase the scheme it
                    // returns, so an otherwise-legitimate `HTTPS://…` URL was
                    // refused here while Dart's `Uri` (which DOES lowercase)
                    // accepted the identical string client-side — task-13
                    // review's carry. `strtolower` closes that gap; see
                    // `test_an_uppercase_https_media_url_is_accepted_on_create`.
                    $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

                    if (! in_array($scheme, ['http', 'https'], true)) {
                        $fail('The media link must be a plain http or https URL.');
                    }
                },
            ],
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
        ];
    }

    /**
     * Rebuilds each screen from its known keys only — `title`, `body`,
     * `media_kind`, `media_url` — the same reason
     * `TestsController::normalizeQuestions` rebuilds a question: a wildcard
     * rule (`screens.*.media_url` and its siblings, above) validates the
     * keys it names, it does not strip the ones it doesn't, so an extra key
     * on a screen object can otherwise ride `$request->validate()` straight
     * into stored JSON forever.
     *
     * The scheme check on `media_url` lives in [rules] rather than here —
     * it must REFUSE a bad value with a 422, not silently drop it, so a
     * caller finds out immediately rather than having their video quietly
     * vanish.
     *
     * @param  array<int, array{title?: mixed, body?: mixed, media_kind?: mixed, media_url?: mixed}>  $screens
     * @return array<int, array{title: mixed, body: mixed, media_kind: mixed, media_url: mixed}>
     */
    private function normalizeScreens(array $screens): array
    {
        return array_map(fn (array $screen) => [
            'title' => $screen['title'] ?? '',
            'body' => $screen['body'] ?? '',
            'media_kind' => $screen['media_kind'] ?? 'none',
            'media_url' => $screen['media_url'] ?? null,
        ], $screens);
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
            'screens' => $guide->screens,
        ];
    }
}
