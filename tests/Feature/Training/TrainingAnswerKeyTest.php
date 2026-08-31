<?php

namespace Tests\Feature\Training;

use App\Models\TrainingAssignment;
use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * The Learn read (task-6 brief) — the single most security-sensitive read in
 * this module, because the answer key lives in the same JSON column as the
 * question it belongs to (see TrainingTest's docblock and
 * LearnerView::stripAnswerKey).
 *
 * `/training/learn*` is the LEARNER surface, gated on `training.view` — the
 * opposite of TrainingGuideScopeTest and TrainingTestAuthoringTest, which
 * cover `/training/guides` and `/training/tests`, both gated on
 * `training.manage`. Most `userWithRole(...)` calls below grant
 * `training.view`; the two that grant `training.manage` instead are there on
 * purpose — see `test_manage_implies_view_so_an_author_can_learn_too`.
 *
 * Not one fixture prompt or choice text below contains the word "correct" —
 * several tests assert its total absence from the raw response body, and a
 * fixture that happened to contain it as English prose would fail those
 * tests for the wrong reason.
 */
class TrainingAnswerKeyTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── The test that matters ────────────────────────────────────

    public function test_a_learner_never_receives_the_answer_key(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [[
                'prompt' => 'How far out does the base go?',
                'choices' => [
                    ['text' => 'Two feet', 'correct' => false],
                    ['text' => 'Four feet', 'correct' => true],
                ],
            ]],
        ]));

        $body = $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$test->id}")
            ->assertOk()
            ->getContent();

        // Not a field-by-field check: the string must not contain it
        // anywhere, at any depth, under any future reshaping of the
        // response — a relationship eager-loaded upstream, an appended
        // attribute, or a toArray() that widens later would all show up
        // here even though none of them would show up in a narrower,
        // field-by-field assertion.
        $this->assertStringNotContainsString('correct', $body);
        $this->assertStringContainsString('Four feet', $body);
    }

    /**
     * The list endpoint carries the same trust boundary. A test reachable
     * only through `library` (nothing assigns it yet) still must not leak
     * its key — the boundary is "every learner-facing read", not "the one
     * endpoint named in the brief".
     */
    public function test_the_list_endpoint_never_carries_the_answer_key_either(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Ladder Safety', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [[
                'prompt' => 'How far out does the base go?',
                'choices' => [
                    ['text' => 'Two feet', 'correct' => false],
                    ['text' => 'Four feet', 'correct' => true],
                ],
            ]],
        ]));

        $body = $this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('correct', $body);
        $this->assertStringContainsString('Four feet', $body);
        $this->assertStringContainsString('Ladder Safety', $body);
    }

    // ── The gate ──────────────────────────────────────────────────

    /**
     * A learner permission opens the surface; holding NO training permission
     * at all does not.
     *
     * `training.manage` is deliberately not exercised as a "must be
     * forbidden" case here: `PermissionCatalogue::withImpliedView` grants
     * `training.view` alongside every other action a module declares — you
     * cannot manage what you cannot see — so an author always holds the
     * learner permission too, and a test asserting otherwise would be wrong
     * about the permission system, not about this controller. See the
     * `training.manage` case in `test_manage_implies_view_so_an_author_can_learn_too`.
     */
    public function test_learn_requires_view_and_refuses_with_no_training_permission(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $noAccess = $this->userWithRole($tenant, 'NoAccess', []);
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $this->signedInAs($noAccess)
            ->getJson('/api/v1/training/learn')
            ->assertForbidden();

        $this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')
            ->assertOk();
    }

    /**
     * The flip side of the note above, made explicit rather than left as an
     * assumption: an author (`training.manage` only, granted with none of
     * `training.view` named directly) can still open the learner surface,
     * because the permission catalogue implies `view` for them. This is
     * `training.manage`'s implied grant doing its job, not a hole in this
     * controller's gate — the gate itself still checks `training.view` and
     * nothing else; it is simply always held here too.
     */
    public function test_manage_implies_view_so_an_author_can_learn_too(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage']);

        $this->signedInAs($author)
            ->getJson('/api/v1/training/learn')
            ->assertOk();
    }

    public function test_refusal_names_the_missing_permission_not_a_code_detail(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $noAccess = $this->userWithRole($tenant, 'NoAccess', []);

        $message = $this->signedInAs($noAccess)
            ->getJson('/api/v1/training/learn')
            ->assertForbidden()
            ->json('message');

        $this->assertStringContainsString('permission', $message);
        $this->assertStringNotContainsString('§', $message);
        $this->assertStringNotContainsString('LearnController', $message);
        $this->assertStringNotContainsString('immutable', $message);
    }

    // ── Published only (decision 3) ──────────────────────────────

    public function test_a_draft_test_never_reaches_the_library_or_a_direct_fetch(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $draft = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Unfinished Quiz', 'status' => TrainingTest::DRAFT,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [[
                'prompt' => 'Still being written?',
                'choices' => [
                    ['text' => 'Yes', 'correct' => true],
                    ['text' => 'No', 'correct' => false],
                ],
            ]],
        ]));

        $titles = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')->json('library'))->pluck('title');

        $this->assertNotContains('Unfinished Quiz', $titles, 'A draft must never reach the learner catalogue.');

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$draft->id}")
            ->assertNotFound();
    }

    // ── Scope (does the place rule hold on the Learn read?) ──────

    /**
     * The rule TrainingGuideScopeTest already proves for the AUTHORING
     * surface, proved again here for the LEARNER one: a franchise-scoped
     * learner sees an organization-wide guide and their own franchise's, and
     * never a sibling's — via the `library` array, since Task 6 adds no
     * per-guide learner endpoint (only `/training/learn` and
     * `/training/learn/tests/{test}`).
     */
    public function test_a_learner_never_sees_a_sibling_franchises_guide(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $this->inTenant($tenant['org'], function () use ($tenant, $riverside, $northgate) {
            TrainingGuide::create([
                'company_id' => null, 'franchise_id' => null,
                'title' => 'Org Wide Guide', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
            TrainingGuide::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => $riverside->id,
                'title' => 'Riverside Only', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
            TrainingGuide::create([
                'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
                'title' => 'Northgate Only', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
            ]);
        });

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $riverside);

        $titles = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')->json('library'))->pluck('title');

        $this->assertContains('Org Wide Guide', $titles, 'An organization guide must reach a franchise.');
        $this->assertContains('Riverside Only', $titles);
        $this->assertNotContains('Northgate Only', $titles, 'A sibling franchise must never leak.');
    }

    /** Same rule, on a test fetched by id — the sharpest version of a scope leak. */
    public function test_a_learner_cannot_fetch_a_sibling_franchises_test_by_id(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $riverside = $tenant['franchise'];
        $northgate = $this->extraFranchise($tenant, 'Northgate');

        $sibling = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $northgate->id,
            'title' => 'Northgate Quiz', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [[
                'prompt' => 'Which building is this?',
                'choices' => [
                    ['text' => 'Northgate', 'correct' => true],
                    ['text' => 'Riverside', 'correct' => false],
                ],
            ]],
        ]));

        $learner = $this->userWithRole($tenant, 'Learner', ['training.view'], onlyAt: $riverside);

        $this->signedInAs($learner)
            ->getJson("/api/v1/training/learn/tests/{$sibling->id}")
            ->assertNotFound();
    }

    // ── The assigned/library split and the role union (decision 2) ─

    /**
     * `assigned` is a real query against the real `training_assignments`
     * table. Nothing in this module writes to it yet — Task 9 does, with no
     * change to this query — so this test writes the row itself, the way
     * Task 9's controller eventually will, to prove the read side already
     * works.
     */
    public function test_a_test_assigned_directly_to_the_learner_appears_in_assigned_not_library(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Learner', ['training.view']);

        $assignedTest = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Assigned Quiz', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [[
                'prompt' => 'Is this one assigned?',
                'choices' => [
                    ['text' => 'Yes', 'correct' => true],
                    ['text' => 'No', 'correct' => false],
                ],
            ]],
        ]));

        $unassignedTest = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Open Quiz', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [],
        ]));

        $this->inTenant($tenant['org'], fn () => TrainingAssignment::create([
            'company_id' => $tenant['company']->id,
            'test_id' => $assignedTest->id,
            'assignee_type' => TrainingAssignment::USER,
            'assignee_id' => $learner->id,
        ]));

        $payload = $this->signedInAs($learner)->getJson('/api/v1/training/learn')->assertOk();

        $assignedTitles = collect($payload->json('assigned'))->pluck('title');
        $libraryTitles = collect($payload->json('library'))->pluck('title');

        $this->assertContains('Assigned Quiz', $assignedTitles);
        $this->assertContains('Assigned Quiz', $libraryTitles, 'Still published and in reach, so still part of the library too.');
        $this->assertContains('Open Quiz', $libraryTitles);
        $this->assertNotContains('Open Quiz', $assignedTitles, 'Never assigned, so it has no business in `assigned`.');
    }

    /**
     * The half of the union that matters most: a ROLE assignment reaches
     * everyone holding that role with no per-user row and no sync job —
     * decision 2's "adding somebody to a role gives them the training".
     */
    public function test_a_test_assigned_to_the_learners_role_appears_in_assigned(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Crew', ['training.view']);

        $roleAssignedTest = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Role Wide Quiz', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [],
        ]));

        $this->inTenant($tenant['org'], fn () => TrainingAssignment::create([
            'company_id' => $tenant['company']->id,
            'test_id' => $roleAssignedTest->id,
            'assignee_type' => TrainingAssignment::ROLE,
            'assignee_id' => $learner->role_id,
        ]));

        $assignedTitles = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')->json('assigned'))->pluck('title');

        $this->assertContains('Role Wide Quiz', $assignedTitles);
    }

    /**
     * A different role, same organization — the union must not hand
     * everybody every role's training.
     */
    public function test_a_test_assigned_to_a_different_role_does_not_appear(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $learner = $this->userWithRole($tenant, 'Crew', ['training.view']);
        $otherRole = $this->userWithRole($tenant, 'Dispatch', ['training.view'])->role_id;

        $otherTest = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id,
            'title' => 'Dispatch Only Quiz', 'status' => TrainingTest::PUBLISHED,
            'pass_mark' => 80, 'requires_guide' => false,
            'questions' => [],
        ]));

        $this->inTenant($tenant['org'], fn () => TrainingAssignment::create([
            'company_id' => $tenant['company']->id,
            'test_id' => $otherTest->id,
            'assignee_type' => TrainingAssignment::ROLE,
            'assignee_id' => $otherRole,
        ]));

        $assignedTitles = collect($this->signedInAs($learner)
            ->getJson('/api/v1/training/learn')->json('assigned'))->pluck('title');

        $this->assertNotContains('Dispatch Only Quiz', $assignedTitles);
    }
}
