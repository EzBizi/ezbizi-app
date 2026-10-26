<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `/training/tests` (task-5 brief) — the five builder options that decide
 * how a test is passed (the brief's own four, plus `count_attempts_after_pass`
 * from the fifth-option brief, 2026-09-09), the trust boundary on `questions`
 * (exactly one correct choice per question), the `guide_id` reachability
 * rule this task had to decide, and the same place-scope/authoring-gate
 * rules TrainingGuideScopeTest already proved for guides, proved again here
 * for tests because a correctly-scoped list beside a `show` that route-model
 * binding resolves by id is the exact bug this module is watching for.
 *
 * `/training/tests` is the AUTHORING surface (Settings tab), gated on
 * `training.manage` throughout — see TrainingTestPolicy's docblock. Every
 * `userWithRole(...)` below therefore grants `training.manage`, except where
 * the test is specifically about the gate itself.
 */
class TrainingTestAuthoringTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── The five options (decision 4, plus the fifth-option brief) ───

    public function test_the_five_options_are_stored_as_written(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        // requires_guide: true needs a guide_id to point at — see
        // assertGuideGateHasATarget — so this test attaches one, unlike the
        // brief's own illustrative snippet (which predates that rule).
        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => null,
            'title' => 'Ladder Safety Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'guide_id' => $guide->id,
            'title' => 'Ladder Safety',
            'requires_guide' => true,
            'pass_mark' => 80,
            'max_attempts' => 3,
            'valid_for_days' => 365,
            'count_attempts_after_pass' => true,
            'questions' => [[
                'prompt' => 'A ladder rests at 16 feet. How far out does the base go?',
                'choices' => [
                    ['text' => 'Two feet', 'correct' => false],
                    ['text' => 'Four feet', 'correct' => true],
                ],
            ]],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.requires_guide', true);
        $response->assertJsonPath('data.pass_mark', 80);
        $response->assertJsonPath('data.max_attempts', 3);
        $response->assertJsonPath('data.valid_for_days', 365);
        $response->assertJsonPath('data.count_attempts_after_pass', true);
    }

    /**
     * The fifth option's own default — ON since the owner's ruling of
     * 2026-09-09 — proved separately from the "stored as written" test
     * above, which always writes an explicit value and so can never show
     * what an author gets by saying nothing.
     *
     * The column's own `default` cannot be what this pins: `store()` writes
     * an explicit value on every create, so an API-created test never
     * reaches the database default at all. This asserts the line that
     * actually decides it.
     */
    public function test_count_attempts_after_pass_defaults_to_true(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety',
            'pass_mark' => 80,
            'questions' => [],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.count_attempts_after_pass', true);
    }

    /**
     * The control that makes the test above readable: a create that ignored
     * the input and always wrote `true` would pass it. An author who asks
     * for the lenient behaviour explicitly still gets it.
     */
    public function test_an_author_can_still_choose_the_lenient_setting_explicitly(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety',
            'pass_mark' => 80,
            'questions' => [],
            'count_attempts_after_pass' => false,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.count_attempts_after_pass', false);
    }

    public function test_a_pass_mark_outside_one_to_a_hundred_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Nonsense',
            'pass_mark' => 140,
            'questions' => [],
        ])
            ->assertStatus(422)
            // Keyed to `pass_mark` specifically, not merely a 422 — otherwise
            // this test cannot tell the pass-mark rule from any other reason
            // the request might be refused.
            ->assertJsonValidationErrors(['pass_mark']);
    }

    public function test_update_changes_the_five_options(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        // requires_guide: true needs a guide_id already attached (or sent in
        // the same request) — see assertGuideGateHasATarget — so this test's
        // test row is seeded with one.
        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => null,
            'title' => 'Original Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => null,
            'guide_id' => $guide->id,
            'title' => 'Original', 'status' => TrainingTest::DRAFT,
            'questions' => [], 'pass_mark' => 80,
        ]));

        $this->signedInAs($author)->patchJson('/api/v1/training/tests/'.$test->id, [
            'requires_guide' => true,
            'pass_mark' => 90,
            'max_attempts' => 2,
            'valid_for_days' => 30,
            'count_attempts_after_pass' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.requires_guide', true)
            ->assertJsonPath('data.pass_mark', 90)
            ->assertJsonPath('data.max_attempts', 2)
            ->assertJsonPath('data.valid_for_days', 30)
            ->assertJsonPath('data.count_attempts_after_pass', true);
    }

    public function test_destroy_removes_a_test_you_may_reach(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $tenant['admin'];

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'To delete', 'status' => TrainingTest::PUBLISHED, 'questions' => [],
        ]));

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/training/tests/'.$test->id)
            ->assertNoContent();

        $this->inTenant($tenant['org'], function () use ($test) {
            $this->assertNull(TrainingTest::query()->find($test->id));
        });
    }

    // ── The trust boundary: exactly one correct choice per question ──

    public function test_a_question_with_no_correct_choice_is_refused_with_a_named_reason(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Bad Test',
            'pass_mark' => 80,
            'questions' => [
                ['prompt' => 'Q1', 'choices' => [
                    ['text' => 'A', 'correct' => true],
                    ['text' => 'B', 'correct' => false],
                ]],
                ['prompt' => 'Q2', 'choices' => [
                    ['text' => 'A', 'correct' => true],
                    ['text' => 'B', 'correct' => false],
                ]],
                ['prompt' => 'Q3', 'choices' => [
                    ['text' => 'A', 'correct' => false],
                    ['text' => 'B', 'correct' => false],
                ]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['questions']);
        $this->assertSame(
            'Every question needs exactly one right answer. Question 3 has none.',
            $response->json('errors.questions.0'),
        );
    }

    public function test_a_question_with_two_correct_choices_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Ambiguous',
            'pass_mark' => 80,
            'questions' => [
                ['prompt' => 'Q1', 'choices' => [
                    ['text' => 'A', 'correct' => true],
                    ['text' => 'B', 'correct' => true],
                ]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['questions']);
        $this->assertSame(
            'Every question needs exactly one right answer. Question 1 has 2.',
            $response->json('errors.questions.0'),
        );
    }

    public function test_a_correct_flag_sent_as_one_alongside_true_is_refused_not_silently_counted_as_one(): void
    {
        // Laravel's `boolean` rule accepts 1 as well as true, but does not
        // cast — so a naive `=== true` count would see only the `true`
        // choice, call this "exactly one", and store `1` and `true` side by
        // side. Both are meant as "correct"; a caller who sent two right
        // answers must be told so, not have one of them silently ignored by
        // the count.
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Loose Boolean',
            'pass_mark' => 80,
            'questions' => [
                ['prompt' => 'Q1', 'choices' => [
                    ['text' => 'A', 'correct' => 1],
                    ['text' => 'B', 'correct' => true],
                ]],
            ],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['questions']);
        $this->assertSame(
            'Every question needs exactly one right answer. Question 1 has 2.',
            $response->json('errors.questions.0'),
        );
    }

    public function test_a_correct_flag_sent_as_zero_and_one_is_accepted_and_stored_as_real_booleans(): void
    {
        // The other half of the same bug: a naive `=== true` count sees
        // neither `0` nor `1` as `true`, so a caller who correctly marked
        // exactly one right answer (`1`) would be refused with "...has
        // none." — false. Normalizing before counting fixes the refusal;
        // asserting the stored shape below proves normalizing happens
        // before storage too, not only before the count.
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Loose Boolean, Valid',
            'pass_mark' => 80,
            'questions' => [
                ['prompt' => 'Q1', 'choices' => [
                    ['text' => 'A', 'correct' => 0],
                    ['text' => 'B', 'correct' => 1],
                ]],
            ],
        ]);

        $response->assertCreated();
        $this->assertSame(false, $response->json('data.questions.0.choices.0.correct'));
        $this->assertSame(true, $response->json('data.questions.0.choices.1.correct'));
    }

    public function test_a_smuggled_key_inside_a_question_or_choice_does_not_survive_storage(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Clean Storage',
            'pass_mark' => 80,
            'questions' => [[
                'prompt' => 'Q1',
                'smuggled' => 'should not survive',
                'choices' => [
                    ['text' => 'A', 'correct' => true, 'smuggled_choice' => 'nope'],
                    ['text' => 'B', 'correct' => false],
                ],
            ]],
        ]);

        $response->assertCreated();
        $this->assertArrayNotHasKey('smuggled', $response->json('data.questions.0'));
        $this->assertArrayNotHasKey('smuggled_choice', $response->json('data.questions.0.choices.0'));
    }

    public function test_questions_array_is_bounded(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $questions = array_fill(0, 201, [
            'prompt' => 'Q',
            'choices' => [
                ['text' => 'A', 'correct' => true],
                ['text' => 'B', 'correct' => false],
            ],
        ]);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Too many questions',
            'pass_mark' => 80,
            'questions' => $questions,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['questions']);

        // Every one of the 201 questions has exactly one correct choice, so
        // the ONLY rule this payload can trip is the size cap — but the
        // exactly-one-correct-answer rule also keys its refusal to
        // `questions`, so `assertJsonValidationErrors` alone cannot tell the
        // two apart. Laravel's own array `max` message names the number;
        // the hand-written one-correct-answer sentence never does.
        $this->assertStringContainsString('200', $response->json('errors.questions.0'));
        $this->assertStringNotContainsString('right answer', $response->json('errors.questions.0'));
    }

    // ── guide_id reachability: the guide must be at or above the test's place ──

    public function test_a_guide_filed_at_the_same_franchise_may_be_attached(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'title' => 'Riverside Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $riverside->id,
            'guide_id' => $guide->id,
            'title' => 'Riverside Test',
            'pass_mark' => 80,
            'questions' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.guide_id', $guide->id);
    }

    public function test_an_organization_wide_guide_may_be_attached_to_a_franchise_test(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => null, 'franchise_id' => null,
            'title' => 'Org Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $riverside->id,
            'guide_id' => $guide->id,
            'title' => 'Riverside Test',
            'pass_mark' => 80,
            'questions' => [],
        ])->assertCreated();
    }

    public function test_a_siblings_guide_is_refused_on_a_franchise_test(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'franchise_id' => $riverside->id,
            'guide_id' => $guide->id,
            'title' => 'Riverside Test',
            'pass_mark' => 80,
            'questions' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guide_id']);
    }

    public function test_a_franchise_guide_is_refused_on_an_organization_wide_test(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'title' => 'Riverside Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'guide_id' => $guide->id,
            'title' => 'Org Wide Test',
            'pass_mark' => 80,
            'questions' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guide_id']);
    }

    public function test_moving_a_test_up_to_company_general_invalidates_a_franchise_only_guide(): void
    {
        // The branch the store-only tests above cannot reach: nobody touched
        // `guide_id` at all. The test moves from the franchise its guide was
        // filed on up to the company's General, and that move ALONE turns a
        // previously-compatible guide incompatible — proving
        // `assertGuideCompatible` really is re-checked on a bare place move,
        // not only when `guide_id` itself changes.
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'title' => 'Riverside Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'guide_id' => $guide->id,
            'title' => 'Riverside Test', 'status' => TrainingTest::DRAFT,
            'questions' => [], 'pass_mark' => 80,
        ]));

        // Only `franchise_id` is sent — `guide_id` is untouched.
        $this->signedInAs($author)->patchJson('/api/v1/training/tests/'.$test->id, [
            'franchise_id' => null,
        ])
            // Keyed to `franchise_id`, the field the caller actually sent,
            // not `guide_id` — the minor fix alongside this test.
            ->assertStatus(422)
            ->assertJsonValidationErrors(['franchise_id']);
    }

    public function test_patching_a_siblings_guide_onto_a_franchise_test_is_refused(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
            'title' => 'Riverside Test', 'status' => TrainingTest::DRAFT,
            'questions' => [], 'pass_mark' => 80,
        ]));

        $this->signedInAs($author)->patchJson('/api/v1/training/tests/'.$test->id, [
            'guide_id' => $guide->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guide_id']);
    }

    // ── requires_guide must point at something (Important 3) ──────

    public function test_requiring_a_guide_with_none_attached_is_refused_at_creation(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Dangling Gate',
            'pass_mark' => 80,
            'requires_guide' => true,
            'questions' => [],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requires_guide']);
    }

    public function test_turning_on_requires_guide_with_no_guide_attached_is_refused_on_update(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => null,
            'title' => 'No Guide Yet', 'status' => TrainingTest::DRAFT,
            'questions' => [], 'pass_mark' => 80,
        ]));

        // Neither `guide_id` nor any place field is sent — only
        // `requires_guide`, against a test whose `guide_id` was already
        // null. Proves the check runs unconditionally, not only inside the
        // touches-place-or-guide branch.
        $this->signedInAs($author)->patchJson('/api/v1/training/tests/'.$test->id, [
            'requires_guide' => true,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['requires_guide']);
    }

    // ── Place-scope and the authoring gate (mirrors TrainingGuideScopeTest) ──

    public function test_a_franchise_sees_organization_tests_but_never_a_siblings(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $this->inTenant($tenant['org'], function () use ($tenant, $riverside, $northgate) {
            TrainingTest::create([
                'company_id' => null, 'franchise_id' => null,
                'title' => 'Org wide', 'status' => TrainingTest::PUBLISHED, 'questions' => [],
            ]);
            TrainingTest::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
                'title' => 'Riverside only', 'status' => TrainingTest::PUBLISHED, 'questions' => [],
            ]);
            TrainingTest::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
                'title' => 'Northgate only', 'status' => TrainingTest::PUBLISHED, 'questions' => [],
            ]);
        });

        $local = $this->userWithRole($tenant, 'Local', ['training.manage'], onlyAt: $riverside);

        $titles = collect($this->signedInAs($local)
            ->getJson('/api/v1/training/tests')->json('data'))->pluck('title');

        $this->assertContains('Org wide', $titles, 'An organization test must reach a franchise.');
        $this->assertContains('Riverside only', $titles);
        $this->assertNotContains('Northgate only', $titles, 'A sibling franchise must never leak.');
    }

    public function test_a_franchise_cannot_fetch_a_siblings_test_by_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate only', 'status' => TrainingTest::PUBLISHED, 'questions' => [],
        ]));

        $local = $this->userWithRole($tenant, 'Local', ['training.manage'], onlyAt: $riverside);

        $this->signedInAs($local)
            ->getJson('/api/v1/training/tests/'.$test->id)
            ->assertForbidden();
    }

    public function test_index_requires_manage_and_refuses_view_only(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        // Proves the re-gate: /training/tests is the authoring surface, so a
        // learner-only grant must not reach it, and an author grant must.
        $viewOnly = $this->userWithRole($tenant, 'ViewOnly', ['training.view'], onlyAt: $tenant['franchise']);
        $manager = $this->userWithRole($tenant, 'Manager', ['training.manage'], onlyAt: $tenant['franchise']);

        $this->signedInAs($viewOnly)
            ->getJson('/api/v1/training/tests')
            ->assertForbidden();

        $this->signedInAs($manager)
            ->getJson('/api/v1/training/tests')
            ->assertOk();
    }

    /** The search bar: `q` narrows by title; a blank `q` is no filter. */
    public function test_q_narrows_the_list_and_a_blank_q_changes_nothing(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $this->inTenant($tenant['org'], function () {
            foreach (['Ladder Safety Test', 'Booking Test'] as $title) {
                TrainingTest::create(['title' => $title, 'status' => TrainingTest::PUBLISHED, 'questions' => [], 'pass_mark' => 80]);
            }
        });
        $as = $this->signedInAs($tenant['admin']);

        $as->getJson('/api/v1/training/tests?q=ladder')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.title', 'Ladder Safety Test');
        $as->getJson('/api/v1/training/tests?q=')->assertOk()->assertJsonCount(2, 'data');
        $as->getJson('/api/v1/training/tests')->assertOk()->assertJsonCount(2, 'data');
    }
}
