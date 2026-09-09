<?php

namespace Tests\Feature\Training;

use App\Models\TrainingGuide;
use App\Models\TrainingTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Audit finding: Training wrote zero audit rows across
 * `app/Http/Controllers/Api/Training/`, `app/Support/Training/` and
 * `ExpireTrainingPasses.php`, and `ModuleRegistryTest`'s
 * `writesRecords()` check never caught it — Training's actions are
 * `['view', 'monitor', 'manage', 'assign']`, none of which is the literal
 * `create`/`edit`/`delete` the check looks for, even though `manage` in
 * fact creates, edits and deletes guides and tests. That predicate is
 * fleet-wide (`ModuleDefinition::writesRecords()`) and is NOT touched here
 * — see the audit-fixes report. This file proves the actual writes exist,
 * which is the half of the finding that is this module's to fix.
 *
 * `AuditEvent` carries no tenant scope of its own (see its own docblock),
 * so every assertion below queries it directly rather than through
 * `inTenant`.
 */
class TrainingAuditTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    // ── guides ──────────────────────────────────────────────────

    public function test_creating_a_guide_writes_an_audit_row_naming_the_actor(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $guideId = $this->signedInAs($author)
            ->postJson('/api/v1/training/guides', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder safety',
                'status' => TrainingGuide::DRAFT,
                'screens' => [],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'guide.created',
            'actor_id' => $author->id,
            'target_type' => TrainingGuide::class,
            'target_id' => $guideId,
            'target_label' => 'Ladder safety',
        ]);
    }

    public function test_updating_a_guide_writes_an_audit_row_with_the_change(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Old title', 'status' => TrainingGuide::DRAFT, 'screens' => [],
        ]));

        $this->signedInAs($author)
            ->patchJson('/api/v1/training/guides/'.$guide->id, ['title' => 'New title'])
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'guide.updated',
            'actor_id' => $author->id,
            'target_id' => $guide->id,
        ]);

        $row = \App\Models\AuditEvent::query()->where('action', 'guide.updated')->firstOrFail();
        $this->assertSame('Old title', $row->changes['before']['title'] ?? null);
        $this->assertSame('New title', $row->changes['after']['title'] ?? null);
    }

    /**
     * `screens` is a whole guide's worth of authored content — bulk, not
     * §9-sensitive, and unread by anything (`AuditEvent` has no reader
     * today). Writing it in full into `audit_events` — append-only,
     * outside erasure's reach — would be pure liability for no benefit.
     * `AuditLogger::logChange` must log that it changed and by how many
     * screens, never the screens themselves.
     */
    public function test_updating_a_guides_screens_records_a_count_not_the_content(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Ladder safety', 'status' => TrainingGuide::DRAFT,
            'screens' => [['title' => 'Screen 1', 'body' => 'Confidential wording.', 'media_kind' => 'none', 'media_url' => null]],
        ]));

        $newScreens = [
            ['title' => 'Screen 1', 'body' => 'Rewritten confidential wording.', 'media_kind' => 'none', 'media_url' => null],
            ['title' => 'Screen 2', 'body' => 'Second confidential screen.', 'media_kind' => 'none', 'media_url' => null],
        ];

        $this->signedInAs($author)
            ->patchJson('/api/v1/training/guides/'.$guide->id, ['screens' => $newScreens])
            ->assertOk();

        $row = \App\Models\AuditEvent::query()->where('action', 'guide.updated')->firstOrFail();

        $this->assertSame(['count' => 1], $row->changes['before']['screens'] ?? null);
        $this->assertSame(['count' => 2], $row->changes['after']['screens'] ?? null);

        $encoded = json_encode($row->changes);
        $this->assertStringNotContainsString('Confidential wording.', $encoded);
        $this->assertStringNotContainsString('Rewritten confidential wording.', $encoded);
        $this->assertStringNotContainsString('Second confidential screen.', $encoded);
    }

    public function test_an_update_that_changes_nothing_writes_no_audit_row(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Same title', 'status' => TrainingGuide::DRAFT, 'screens' => [],
        ]));

        $this->signedInAs($author)
            ->patchJson('/api/v1/training/guides/'.$guide->id, ['title' => 'Same title'])
            ->assertOk();

        $this->assertDatabaseMissing('audit_events', ['action' => 'guide.updated']);
    }

    /**
     * Required by the task: an audit row for a guide delete that names the
     * actor — the auditor's own failure scenario is an admin deleting a
     * published guide with recorded completions behind it, and nothing
     * saying who did it.
     */
    public function test_deleting_a_guide_writes_an_audit_row_naming_the_actor(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Admin', ['training.manage'], onlyAt: $tenant['franchise']);

        $guide = $this->inTenant($tenant['org'], fn () => TrainingGuide::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Ladder safety', 'status' => TrainingGuide::PUBLISHED, 'screens' => [],
        ]));

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/training/guides/'.$guide->id)
            ->assertNoContent();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'guide.deleted',
            'actor_id' => $admin->id,
            'target_id' => $guide->id,
            'target_type' => TrainingGuide::class,
        ]);

        $row = \App\Models\AuditEvent::query()->where('action', 'guide.deleted')->firstOrFail();
        $this->assertStringContainsString($admin->name, $row->actor_label);
        $this->assertSame('Ladder safety', $row->metadata['title'] ?? null);
    }

    // ── tests ───────────────────────────────────────────────────

    public function test_creating_a_test_writes_an_audit_row_naming_the_actor(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $testId = $this->signedInAs($author)
            ->postJson('/api/v1/training/tests', [
                'company_id' => $tenant['company']->id,
                'franchise_id' => $tenant['franchise']->id,
                'title' => 'Ladder safety quiz',
                'status' => TrainingTest::DRAFT,
                'questions' => [
                    ['prompt' => 'Q1', 'choices' => [
                        ['text' => 'A', 'correct' => true],
                        ['text' => 'B', 'correct' => false],
                    ]],
                ],
            ])
            ->assertCreated()
            ->json('data.id');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'test.created',
            'actor_id' => $author->id,
            'target_id' => $testId,
            'target_type' => TrainingTest::class,
        ]);
    }

    public function test_updating_a_test_writes_an_audit_row_with_the_change(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Old quiz', 'status' => TrainingTest::DRAFT,
            'questions' => [], 'requires_guide' => false, 'pass_mark' => 80,
        ]));

        $this->signedInAs($author)
            ->patchJson('/api/v1/training/tests/'.$test->id, ['title' => 'New quiz'])
            ->assertOk();

        $row = \App\Models\AuditEvent::query()->where('action', 'test.updated')->firstOrFail();
        $this->assertSame($author->id, $row->actor_id);
        $this->assertSame('Old quiz', $row->changes['before']['title'] ?? null);
        $this->assertSame('New quiz', $row->changes['after']['title'] ?? null);
    }

    /**
     * `questions` carries the answer key (`correct` lives inside the same
     * JSON — `TrainingTest`'s own docblock). Jose's own ruling on this
     * finding: the answer key must never be written into an append-only,
     * unerasable table that nothing reads. `AuditLogger::logChange` must
     * log that `questions` changed and by how many, never the questions
     * or their answers.
     */
    public function test_updating_a_tests_questions_records_a_count_not_the_answer_key(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $author = $this->userWithRole($tenant, 'Author', ['training.manage'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Ladder safety quiz', 'status' => TrainingTest::DRAFT,
            'questions' => [
                ['prompt' => 'Q1', 'choices' => [
                    ['text' => 'The-Secret-Answer', 'correct' => true],
                    ['text' => 'Wrong', 'correct' => false],
                ]],
            ],
            'requires_guide' => false, 'pass_mark' => 80,
        ]));

        $newQuestions = [
            ['prompt' => 'Q1', 'choices' => [
                ['text' => 'A-New-Secret-Answer', 'correct' => true],
                ['text' => 'Wrong', 'correct' => false],
            ]],
            ['prompt' => 'Q2', 'choices' => [
                ['text' => 'Another-Secret-Answer', 'correct' => true],
                ['text' => 'Wrong', 'correct' => false],
            ]],
        ];

        $this->signedInAs($author)
            ->patchJson('/api/v1/training/tests/'.$test->id, ['questions' => $newQuestions])
            ->assertOk();

        $row = \App\Models\AuditEvent::query()->where('action', 'test.updated')->firstOrFail();

        $this->assertSame(['count' => 1], $row->changes['before']['questions'] ?? null);
        $this->assertSame(['count' => 2], $row->changes['after']['questions'] ?? null);

        $encoded = json_encode($row->changes);
        $this->assertStringNotContainsString('Secret-Answer', $encoded);
    }

    public function test_deleting_a_test_writes_an_audit_row_naming_the_actor(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $admin = $this->userWithRole($tenant, 'Admin', ['training.manage'], onlyAt: $tenant['franchise']);

        $test = $this->inTenant($tenant['org'], fn () => TrainingTest::create([
            'company_id' => $tenant['company']->id, 'franchise_id' => $tenant['franchise']->id,
            'title' => 'Ladder safety quiz', 'status' => TrainingTest::PUBLISHED,
            'questions' => [], 'requires_guide' => false, 'pass_mark' => 80,
        ]));

        $this->signedInAs($admin)
            ->deleteJson('/api/v1/training/tests/'.$test->id)
            ->assertNoContent();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'test.deleted',
            'actor_id' => $admin->id,
            'target_id' => $test->id,
            'target_type' => TrainingTest::class,
        ]);
    }
}
