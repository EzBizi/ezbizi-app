<?php

namespace App\Http\Controllers\Api\Training;

use App\Http\Controllers\Api\Controller;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use App\Support\Inventory\InventoryVisibility;
use App\Support\Scoping\PlaceInView;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training tests — list, create, show, update, destroy, and the four
 * per-test options (decision 4) that decide how a test is passed.
 *
 * Mirrors GuidesController closely: same place-scope engine, same
 * store/update place-handling shape. Two things this controller adds that
 * the guide one does not need: `questions` carries its own answer-key trust
 * boundary (every question must have exactly one correct choice — see
 * [assertExactlyOneCorrectPerQuestion]), and `guide_id`, when supplied, is
 * checked against the test's own place (see [assertGuideCompatible]) so a
 * test cannot point at a guide fewer of its own takers could ever reach.
 */
class TestsController extends Controller
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

    /** GET /api/v1/training/tests */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TrainingTest::class);

        $user = $request->user();
        $scope = ViewScope::forUser($user);

        // Same shared place-scope engine GuidesController uses — see the
        // comment there for why this is InventoryVisibility directly and not
        // a TrainingVisibility wrapper.
        $query = InventoryVisibility::constrain(TrainingTest::query(), $user, $scope);

        $tests = $query
            ->orderBy('title')
            ->paginate(perPage: min((int) $request->query('per_page', 50), 200));

        return response()->json([
            'data' => collect($tests->items())->map(
                fn (TrainingTest $t) => $this->present($t),
            ),
            'meta' => [
                'total' => $tests->total(),
                'per_page' => $tests->perPage(),
                'current_page' => $tests->currentPage(),
                'last_page' => $tests->lastPage(),
            ],
        ]);
    }

    /** POST /api/v1/training/tests */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        $this->assertExactlyOneCorrectPerQuestion($input['questions'] ?? []);

        $company = isset($input['company_id'])
            ? Company::findOrFail($input['company_id'])
            : null;
        $franchise = isset($input['franchise_id'])
            ? Franchise::findOrFail($input['franchise_id'])
            : null;

        PlaceInView::assert($request->user(), $company, $franchise, 'test');

        if ($company === null) {
            $this->authorize('createOnOrganization', TrainingTest::class);
        } else {
            $this->authorize('create', [TrainingTest::class, $company, $franchise]);
        }

        $guide = isset($input['guide_id'])
            ? TrainingGuide::findOrFail($input['guide_id'])
            : null;

        $this->assertGuideCompatible($guide, $company, $franchise);

        $test = TrainingTest::create([
            'company_id' => $company?->getKey(),
            'franchise_id' => $franchise?->getKey(),
            'guide_id' => $guide?->getKey(),
            'title' => $input['title'],
            'status' => $input['status'] ?? TrainingTest::DRAFT,
            'questions' => $input['questions'] ?? [],
            'requires_guide' => $input['requires_guide'] ?? false,
            'pass_mark' => $input['pass_mark'] ?? 80,
            'max_attempts' => $input['max_attempts'] ?? null,
            'valid_for_days' => $input['valid_for_days'] ?? null,
        ]);

        return response()->json(
            ['data' => $this->present($test->fresh())],
            Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/training/tests/{test} */
    public function show(TrainingTest $test): JsonResponse
    {
        $this->authorize('view', $test);

        return response()->json(['data' => $this->present($test)]);
    }

    /** PATCH /api/v1/training/tests/{test} */
    public function update(Request $request, TrainingTest $test): JsonResponse
    {
        $this->authorize('update', $test);

        $input = $request->validate($this->rules(updating: true));

        if (array_key_exists('questions', $input)) {
            $this->assertExactlyOneCorrectPerQuestion($input['questions']);
        }

        $touchesPlace = array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input);
        $touchesGuide = array_key_exists('guide_id', $input);

        if ($touchesPlace || $touchesGuide) {
            $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $test->company_id;
            $franchiseId = array_key_exists('franchise_id', $input) ? $input['franchise_id'] : $test->franchise_id;
            $guideId = array_key_exists('guide_id', $input) ? $input['guide_id'] : $test->guide_id;

            $company = $companyId === null ? null : Company::findOrFail($companyId);
            $franchise = $franchiseId === null ? null : Franchise::findOrFail($franchiseId);
            $guide = $guideId === null ? null : TrainingGuide::findOrFail($guideId);

            if ($touchesPlace) {
                PlaceInView::assert($request->user(), $company, $franchise, 'test');

                $placeChanged = $companyId !== $test->company_id || $franchiseId !== $test->franchise_id;

                if ($placeChanged) {
                    if ($company === null) {
                        $this->authorize('createOnOrganization', TrainingTest::class);
                    } else {
                        $this->authorize('create', [TrainingTest::class, $company, $franchise]);
                    }
                }
            }

            // Re-checked whenever either side could have changed: a place move
            // with the guide left alone can turn a previously-compatible guide
            // incompatible just as surely as changing the guide itself does.
            $this->assertGuideCompatible($guide, $company, $franchise);
        }

        $test->fill($input);
        $test->save();

        return response()->json(['data' => $this->present($test->fresh())]);
    }

    /** DELETE /api/v1/training/tests/{test} */
    public function destroy(TrainingTest $test): Response
    {
        $this->authorize('delete', $test);

        $test->delete();

        return response()->noContent();
    }

    /**
     * A question with no correct choice can never be passed; one with two is
     * ambiguous. Refuses on the first offending question, naming it, so the
     * author knows exactly which one to fix rather than being told the whole
     * array is wrong.
     *
     * @param  array<int, array{choices?: array<int, array{correct?: bool}>}>  $questions
     */
    private function assertExactlyOneCorrectPerQuestion(array $questions): void
    {
        foreach ($questions as $index => $question) {
            $correctCount = collect($question['choices'] ?? [])
                ->filter(fn (array $choice) => ($choice['correct'] ?? false) === true)
                ->count();

            if ($correctCount === 1) {
                continue;
            }

            $number = $index + 1;
            $detail = $correctCount === 0 ? 'has none' : "has {$correctCount}";

            throw ValidationException::withMessages([
                'questions' => "Every question needs exactly one right answer. Question {$number} {$detail}.",
            ]);
        }
    }

    /**
     * A submitted `guide_id` must reach at least as far as the test does.
     *
     * ── The decision, and why ─────────────────────────────────────
     *
     * A test filed at a place is taken by everyone that place's visibility
     * rule reaches (§4 — the same rule GuidesController's [index] applies).
     * If the guide behind it were filed somewhere NARROWER than the test, some
     * of the test's own takers could be handed a test that requires a guide
     * (`requires_guide`) — or simply references one — they can never open. A
     * franchise pointing at a sibling's guide is the sharpest version of that:
     * a scope leak wearing a foreign key, exactly the shape this task's brief
     * warns about.
     *
     * So the guide's place must be the test's place OR BROADER: organization
     * (`company_id` null) is compatible with anything; a company's General
     * (`company_id` set, `franchise_id` null) is compatible with that same
     * company or any franchise under it; an exact franchise match is
     * compatible only with that same franchise. A narrower guide — e.g. one
     * franchise's guide behind an organization-wide or company-wide test — is
     * refused for the same reason in reverse: some of that test's takers
     * could never reach it.
     *
     * No separate `authorize('view', $guide)` call is needed alongside this:
     * `PlaceInView::assert` has already confirmed the caller's current view
     * covers the test's own place, and a guide that passes this compatibility
     * check is always filed at or above that same place — so if the caller
     * can file the test there, they can already see the guide.
     */
    private function assertGuideCompatible(?TrainingGuide $guide, ?Company $company, ?Franchise $franchise): void
    {
        if ($guide === null) {
            return;
        }

        $compatible = $guide->company_id === null
            || ($guide->company_id === $company?->getKey() && $guide->franchise_id === null)
            || ($guide->company_id === $company?->getKey() && $guide->franchise_id === $franchise?->getKey());

        if (! $compatible) {
            throw ValidationException::withMessages([
                'guide_id' => 'That guide is not filed at or above this test\'s place, so not everyone '
                    .'who takes the test could reach it. Pick a guide filed on this same place, or higher.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:191'],
            'status' => ['sometimes', 'string', Rule::in([TrainingTest::DRAFT, TrainingTest::PUBLISHED])],
            'company_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('companies')],
            'franchise_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('franchises')],
            'guide_id' => ['sometimes', 'nullable', 'uuid', $this->existsInTenant('training_guides')],
            'requires_guide' => ['sometimes', 'boolean'],
            'pass_mark' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'max_attempts' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:99'],
            'valid_for_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'questions' => ['sometimes', 'array', 'max:200'],
            'questions.*.prompt' => ['required', 'string', 'max:1000'],
            'questions.*.choices' => ['required', 'array', 'min:2', 'max:10'],
            'questions.*.choices.*.text' => ['required', 'string', 'max:500'],
            'questions.*.choices.*.correct' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(TrainingTest $test): array
    {
        return [
            'id' => $test->id,
            'title' => $test->title,
            'status' => $test->status,
            'company_id' => $test->company_id,
            'franchise_id' => $test->franchise_id,
            'guide_id' => $test->guide_id,
            'requires_guide' => $test->requires_guide,
            'pass_mark' => $test->pass_mark,
            'max_attempts' => $test->max_attempts,
            'valid_for_days' => $test->valid_for_days,
            'question_count' => $test->questionCount(),
            'questions' => $test->questions,
        ];
    }
}
