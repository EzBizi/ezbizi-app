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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Training tests — list, create, show, update, destroy, and the five
 * per-test options (decision 4, plus the fifth-option brief 2026-09-09)
 * that decide how a test is passed.
 *
 * The fifth, `count_attempts_after_pass`, answers "does a retake taken
 * while somebody already holds a valid pass spend one of their
 * `max_attempts`?" — default `false` (it does not), because the owner's own
 * framing was that the cap is "meant for failures," not for someone
 * improving on a pass they already have. `true` is the old, strict
 * behaviour: every attempt counts, whatever it comes after. Validated and
 * stored exactly like the other four in [rules]/[store]/[update]; the
 * definition of "still holds a valid pass" is never re-derived here — see
 * `LearnController::submit` and `App\Support\Training\LapsedPasses`, the one
 * place that fact is decided.
 *
 * Mirrors GuidesController closely: same place-scope engine, same
 * store/update place-handling shape, same audit shape (see
 * GuidesController's docblock — `logChange` before `save()`, `log` on
 * create and before delete). Three things this controller adds that
 * the guide one does not need: `questions` carries its own answer-key trust
 * boundary (every question must have exactly one correct choice, counted on
 * a NORMALIZED and REBUILT copy of the input — see [normalizeQuestions] and
 * [assertExactlyOneCorrectPerQuestion]); `guide_id`, when supplied, is
 * checked against the test's own place (see [assertGuideCompatible]) so a
 * test cannot point at a guide fewer of its own takers could ever reach;
 * and `requires_guide: true` with no `guide_id` is refused at authoring
 * (see [assertGuideGateHasATarget]) — though that state is still reachable
 * without this controller at all, and must stay readable elsewhere as
 * "no gate" rather than an error, per that method's docblock.
 */
class TestsController extends Controller
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
            'meta' => $this->pageMeta($tests),
        ]);
    }

    /** POST /api/v1/training/tests */
    public function store(Request $request): JsonResponse
    {
        $input = $request->validate($this->rules());

        if (array_key_exists('questions', $input)) {
            $input['questions'] = $this->normalizeQuestions($input['questions']);
        }

        $this->assertExactlyOneCorrectPerQuestion($input['questions'] ?? []);
        $this->assertGuideGateHasATarget($input['requires_guide'] ?? false, $input['guide_id'] ?? null);

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

        $test = DB::transaction(function () use ($input, $company, $franchise, $guide) {
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
                // Defaults ON (owner, 2026-09-09) — and it is THIS line that
                // decides it, not the column default, which an API create never
                // reaches because this always writes an explicit value.
                'count_attempts_after_pass' => $input['count_attempts_after_pass'] ?? true,
            ]);

            $this->audit->log('test.created', $test);

            return $test;
        });

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
            $input['questions'] = $this->normalizeQuestions($input['questions']);
            $this->assertExactlyOneCorrectPerQuestion($input['questions']);
        }

        $touchesPlace = array_key_exists('company_id', $input) || array_key_exists('franchise_id', $input);
        $touchesGuide = array_key_exists('guide_id', $input);

        // Runs unconditionally, not only when place or guide are touched:
        // `requires_guide` can be turned on by itself against a test whose
        // `guide_id` was already null, and that is just as much a gate
        // pointing at nothing as clearing `guide_id` while `requires_guide`
        // stays on.
        $effectiveRequiresGuide = array_key_exists('requires_guide', $input)
            ? $input['requires_guide']
            : $test->requires_guide;
        $effectiveGuideId = $touchesGuide ? $input['guide_id'] : $test->guide_id;
        $this->assertGuideGateHasATarget($effectiveRequiresGuide, $effectiveGuideId);

        if ($touchesPlace || $touchesGuide) {
            $companyId = array_key_exists('company_id', $input) ? $input['company_id'] : $test->company_id;
            $franchiseId = array_key_exists('franchise_id', $input) ? $input['franchise_id'] : $test->franchise_id;
            $guideId = $effectiveGuideId;

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
            //
            // Blamed on whichever field the caller actually sent — `guide_id`
            // when they changed the guide, otherwise the place field they
            // moved — so a 422 highlights the input that was in the request
            // rather than one that was not.
            $blameField = $touchesGuide
                ? 'guide_id'
                : (array_key_exists('company_id', $input) ? 'company_id' : 'franchise_id');
            $this->assertGuideCompatible($guide, $company, $franchise, $blameField);
        }

        DB::transaction(function () use ($test, $input) {
            $test->fill($input);

            // Same no-op guard as GuidesController::update.
            if (! $test->isDirty()) {
                return;
            }

            $this->audit->logChange('test.updated', $test);
            $test->save();
        });

        return response()->json(['data' => $this->present($test->fresh())]);
    }

    /** DELETE /api/v1/training/tests/{test} */
    public function destroy(TrainingTest $test): Response
    {
        $this->authorize('delete', $test);

        DB::transaction(function () use ($test) {
            // Logged before the delete — see GuidesController::destroy.
            // `training_attempts` cascade with this row (migration
            // `create_training_tables`), so this is the only record that a
            // test with recorded attempts behind it ever existed.
            $this->audit->log('test.deleted', $test, metadata: [
                'title' => $test->title,
            ]);

            $test->delete();
        });

        return response()->noContent();
    }

    /**
     * Rebuilds each question — and each choice — from its known keys only,
     * with `correct` normalized to a real boolean.
     *
     * Two independent problems, one fix, both closed at the one place this
     * JSON is written rather than downstream in Task 6 or Task 8:
     *
     * 1. **The boolean is not actually a boolean.** Laravel's `boolean`
     *    validation rule accepts `1`, `0`, `"1"`, `"0"` as well as `true` and
     *    `false` — but does not cast, so `$request->validate()` hands back
     *    whatever shape the caller sent. [assertExactlyOneCorrectPerQuestion]
     *    (and Task 8's grading) need a strict `=== true` count, and against
     *    raw input that count is simply wrong in both directions: `1`
     *    alongside `true` strictly counts as one correct choice, so it is
     *    accepted and stored with two different-looking "correct" values
     *    side by side, which a truthy read (the natural way to grade) sees
     *    as two right answers on a question this boundary just certified as
     *    having exactly one. And `0` alongside `1` strictly counts as ZERO
     *    correct, so a caller who marked exactly one right answer is refused
     *    with "...has none." — a sentence that is untrue about what they
     *    sent. `filter_var(..., FILTER_VALIDATE_BOOLEAN)` reads every shape
     *    Laravel's `boolean` rule accepts the same way, so counting and
     *    storage agree with each other and with what was actually meant.
     *
     * 2. **Keys outside the schema ride along.** A `questions.*.choices.*`
     *    wildcard rule validates the keys it names; it does not strip the
     *    ones it doesn't. An extra key on a question object (or a choice)
     *    can survive `$request->validate()` into the array this method is
     *    given, and from there into the stored JSON, forever. Rebuilding
     *    each question from exactly `prompt` and `choices`, and each choice
     *    from exactly `text` and `correct`, means nothing not in the schema
     *    can ever reach the database no matter what a caller adds.
     *
     * @param  array<int, array{prompt?: mixed, choices?: array<int, array{text?: mixed, correct?: mixed}>}>  $questions
     * @return array<int, array{prompt: mixed, choices: array<int, array{text: mixed, correct: bool}>}>
     */
    private function normalizeQuestions(array $questions): array
    {
        return array_map(fn (array $question) => [
            'prompt' => $question['prompt'],
            'choices' => array_map(fn (array $choice) => [
                'text' => $choice['text'],
                'correct' => filter_var($choice['correct'], FILTER_VALIDATE_BOOLEAN),
            ], $question['choices']),
        ], $questions);
    }

    /**
     * A question with no correct choice can never be passed; one with two is
     * ambiguous. Refuses on the first offending question, naming it, so the
     * author knows exactly which one to fix rather than being told the whole
     * array is wrong.
     *
     * Expects [normalizeQuestions]'s output — `correct` already a real bool —
     * so the strict `=== true` count below means what it says.
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
     *
     * @param  string  $field  which request field the 422 is keyed to — the
     *                         guide, or the place, depending on which one the
     *                         caller actually sent (see the call sites: `store`
     *                         always blames `guide_id`, since a fresh create has
     *                         no prior state to move away from; `update` blames
     *                         whichever field changed, so the error highlights
     *                         an input that was actually in the request).
     */
    private function assertGuideCompatible(
        ?TrainingGuide $guide,
        ?Company $company,
        ?Franchise $franchise,
        string $field = 'guide_id',
    ): void {
        if ($guide === null) {
            return;
        }

        // The one shared definition — see GuidePlaceReach's own docblock on
        // why GuidesController::update asks it too rather than a second copy.
        $compatible = GuidePlaceReach::covers(
            $guide->company_id, $guide->franchise_id,
            $company?->getKey(), $franchise?->getKey(),
        );

        if (! $compatible) {
            throw ValidationException::withMessages([
                $field => 'That guide is not filed at or above this test\'s place, so not everyone '
                    .'who takes the test could reach it. Pick a guide filed on this same place, or higher.',
            ]);
        }
    }

    /**
     * `requires_guide: true` with no `guide_id` is a gate pointing at
     * nothing. Refused at authoring, with a sentence, because it is almost
     * always a mistake — an author who meant to gate the test on a guide
     * and forgot to pick one, or who removed the guide without noticing the
     * gate was still on.
     *
     * ── This guard is not the only line of defense ───────────────────
     *
     * `training_tests.guide_id` is `nullOnDelete` (see the migration), so
     * DELETING the guide a test points at reaches this exact state —
     * `requires_guide: true`, `guide_id: null` — with no call through this
     * controller at all. This method cannot close that path; it only
     * catches the authoring mistake. So a test already sitting in that
     * state must not become a dead end nobody can pass: everywhere else in
     * the module that reads `requires_guide` (Task 8's grading, in
     * particular) must treat a null `guide_id` as "no gate" rather than as
     * an error, whatever `requires_guide` says. The house rule is no dead
     * ends, and a state this guard cannot undo is the one place that rule
     * has to be honored by the READER instead of the writer.
     */
    private function assertGuideGateHasATarget(bool $requiresGuide, ?string $guideId): void
    {
        if ($requiresGuide && $guideId === null) {
            throw ValidationException::withMessages([
                'requires_guide' => 'This test requires a guide, but none is attached. Pick a guide, '
                    .'or turn off requiring one.',
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
            'count_attempts_after_pass' => ['sometimes', 'boolean'],
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
            'count_attempts_after_pass' => $test->count_attempts_after_pass,
            'question_count' => $test->questionCount(),
            'questions' => $test->questions,
        ];
    }
}
