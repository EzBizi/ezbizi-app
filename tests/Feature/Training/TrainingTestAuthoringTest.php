<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * `/training/tests` (task-5 brief) — the four builder options that decide
 * how a test is passed, the trust boundary on `questions` (exactly one
 * correct choice per question), the `guide_id` reachability rule this task
 * had to decide, and the same place-scope/authoring-gate rules
 * TrainingGuideScopeTest already proved for guides, proved again here for
 * tests because a correctly-scoped list beside a `show` that route-model
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

    // ── The four options (decision 4) ────────────────────────────

    public function test_the_four_options_are_stored_as_written(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $response = $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety',
            'requires_guide' => true,
            'pass_mark' => 80,
            'max_attempts' => 3,
            'valid_for_days' => 365,
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
        ])->assertStatus(422);
    }

    public function test_update_changes_the_four_options(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => null,
            'title' => 'Original', 'status' => TrainingTest::DRAFT,
            'questions' => [], 'pass_mark' => 80,
        ]));

        $this->signedInAs($author)->patchJson('/api/v1/training/tests/'.$test->id, [
            'requires_guide' => true,
            'pass_mark' => 90,
            'max_attempts' => 2,
            'valid_for_days' => 30,
        ])
            ->assertOk()
            ->assertJsonPath('data.requires_guide', true)
            ->assertJsonPath('data.pass_mark', 90)
            ->assertJsonPath('data.max_attempts', 2)
            ->assertJsonPath('data.valid_for_days', 30);
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

        $this->signedInAs($author)->postJson('/api/v1/training/tests', [
            'company_id' => $tenant['company']->id,
            'title' => 'Too many questions',
            'pass_mark' => 80,
            'questions' => $questions,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['questions']);
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
}
