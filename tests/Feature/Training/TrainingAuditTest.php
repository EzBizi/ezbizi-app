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
