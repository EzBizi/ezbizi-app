<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\AutomationEvent;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Files\AttachmentUploader;
use App\Support\Files\FileStore;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskUpdateAttachmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tenant = $this->provisionTenant('Attachment Alpha');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->signedInAs($this->tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Attachment work', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $this->task = $this->createTask('Attach evidence');
    }

    public function test_upload_persistence_failure_removes_the_stored_bytes(): void
    {
        $audit = new class(app(TenantContext::class)) extends AuditLogger
        {
            public function log(
                string $action,
                ?Model $target = null,
                array $changes = [],
                array $metadata = [],
                ?User $actor = null,
                ?string $causationId = null,
                ?string $scopeType = null,
                ?string $scopeId = null,
            ): ?AuditEvent {
                throw new RuntimeException('audit persistence failed');
            }
        };

        try {
            (new AttachmentUploader(app(FileStore::class), $audit))->upload(
                $this->tenant['admin'],
                $this->task,
                UploadedFile::fake()->createWithContent('proof.pdf', '%PDF failed audit'),
                'tasks',
            );
            $this->fail('Expected persistence failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit persistence failed', $exception->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, Attachment::count());
    }

    public function test_candidate_upload_requires_comment_before_validation_and_is_private_to_its_actor(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Attachment commenter', ['tasks.view', 'tasks.comment']);
        $editor = $this->userWithRole($this->tenant, 'Attachment editor', ['tasks.view', 'tasks.edit', 'tasks.manage']);
        $this->scope($commenter);
        $this->scope($editor);

        $this->signedInAs($editor)->post("/api/v1/tasks/{$this->task->id}/update-attachments", [], [
            'Accept' => 'application/json',
        ])->assertForbidden()->assertJsonMissingValidationErrors('file');

        $created = $this->uploadCandidate($commenter, 'private.txt', 'private candidate');
        $attachmentId = $created->json('data.id');
        $created->assertJsonMissingPath('data.path')->assertJsonMissingPath('data.attachable');

        $this->signedInAs($editor)->get("/api/v1/attachments/{$attachmentId}")->assertNotFound();
        $this->signedInAs($commenter)->get("/api/v1/attachments/{$attachmentId}")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->signedInAs($commenter)->deleteJson("/api/v1/attachments/{$attachmentId}")->assertNotFound();
        $this->signedInAs($commenter)->getJson(
            "/api/v1/attachments?attachable_type=tasks&attachable_id={$this->task->id}",
        )->assertUnprocessable();
    }

    public function test_file_only_update_claims_candidates_once_and_presents_committed_attachments(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'File only commenter', ['tasks.view', 'tasks.comment']);
        $this->scope($commenter);
        $attachmentId = $this->uploadCandidate($commenter, 'photo.txt', 'evidence')->json('data.id');
        $beforeDetail = $this->task->fresh()->detail_version;
        $payload = [
            'attachment_candidate_ids' => [$attachmentId],
            'idempotency_key' => 'file-only-1',
        ];

        $first = $this->signedInAs($commenter)
            ->postJson("/api/v1/tasks/{$this->task->id}/updates", $payload)->assertCreated();
        $second = $this->signedInAs($commenter)
            ->postJson("/api/v1/tasks/{$this->task->id}/updates", $payload)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $first->assertJsonPath('data.attachments.0.id', $attachmentId);

        $this->enterTenant();
        $attachment = Attachment::findOrFail($attachmentId);
        $this->assertSame('task_updates', $attachment->attachable_type);
        $this->assertSame($first->json('data.id'), $attachment->attachable_id);
        $this->assertFalse(TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->exists());
        $this->assertSame($beforeDetail + 1, $this->task->fresh()->detail_version);
        $this->assertSame(1, TaskActivity::where('task_id', $this->task->id)->where('kind', 'task.update_added')->count());
        $this->assertSame(1, AutomationEvent::where('event_key', 'task.update_added')->count());

        $this->signedInAs($commenter)
            ->getJson("/api/v1/tasks/boards/{$this->board->id}/tasks/{$this->task->id}")
            ->assertOk()->assertJsonPath('data.updates.0.attachments.0.id', $attachmentId);
    }

    public function test_generic_delete_cannot_remove_staged_or_committed_task_attachments(): void
    {
        $author = $this->userWithRole($this->tenant, 'Immutable attachment author', ['tasks.view', 'tasks.comment']);
        $manager = $this->userWithRole($this->tenant, 'Immutable attachment manager', ['tasks.view', 'tasks.edit', 'tasks.manage']);
        $this->scope($author);
        $this->scope($manager);
        $attachmentId = $this->uploadCandidate($author, 'immutable.txt', 'keep me')->json('data.id');

        $this->signedInAs($author)->deleteJson("/api/v1/attachments/{$attachmentId}")->assertNotFound();
        $this->signedInAs($manager)->deleteJson("/api/v1/attachments/{$attachmentId}")->assertNotFound();
        $this->signedInAs($author)->postJson("/api/v1/tasks/{$this->task->id}/updates", [
            'attachment_candidate_ids' => [$attachmentId], 'idempotency_key' => 'immutable-file',
        ])->assertCreated();

        $this->signedInAs($author)->deleteJson("/api/v1/attachments/{$attachmentId}")->assertNotFound();
        $this->signedInAs($manager)->deleteJson("/api/v1/attachments/{$attachmentId}")->assertNotFound();
        $this->enterTenant();
        $attachment = Attachment::findOrFail($attachmentId);
        $this->assertSame('task_updates', $attachment->attachable_type);
        $this->assertTrue(Storage::disk('local')->exists($attachment->path));
    }

    public function test_invalid_claim_rolls_back_every_update_side_effect_and_keeps_candidates_private(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Rollback commenter', ['tasks.view', 'tasks.comment']);
        $this->scope($commenter);
        $attachmentId = $this->uploadCandidate($commenter, 'rollback.txt', 'still private')->json('data.id');
        $beforeDetail = $this->task->fresh()->detail_version;

        $this->signedInAs($commenter)->postJson("/api/v1/tasks/{$this->task->id}/updates", [
            'body' => 'This must roll back',
            'mention_user_ids' => [(string) Str::uuid()],
            'attachment_candidate_ids' => [$attachmentId],
            'idempotency_key' => 'rollback-1',
        ])->assertUnprocessable();

        $this->enterTenant();
        $attachment = Attachment::findOrFail($attachmentId);
        $this->assertSame('tasks', $attachment->attachable_type);
        $this->assertSame($this->task->id, $attachment->attachable_id);
        $this->assertTrue(TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->exists());
        $this->assertSame(0, TaskUpdate::where('task_id', $this->task->id)->count());
        $this->assertSame($beforeDetail, $this->task->fresh()->detail_version);
        $this->assertSame(0, TaskActivity::where('task_id', $this->task->id)->where('kind', 'task.update_added')->count());
        $this->assertSame(0, AutomationEvent::where('event_key', 'task.update_added')->count());
    }

    public function test_claim_rejects_wrong_task_actor_and_expired_candidates_without_rebinding(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Claim commenter', ['tasks.view', 'tasks.comment']);
        $other = $this->userWithRole($this->tenant, 'Other commenter', ['tasks.view', 'tasks.comment']);
        $this->scope($commenter);
        $this->scope($other);
        $otherTask = $this->createTask('Other task');

        foreach (['wrong-task', 'wrong-actor', 'expired'] as $case) {
            $attachmentId = $this->uploadCandidate($commenter, "{$case}.txt", $case)->json('data.id');
            if ($case === 'expired') {
                $this->enterTenant();
                TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)
                    ->update(['expires_at' => now()->subSecond()]);
            }
            $actor = $case === 'wrong-actor' ? $other : $commenter;
            $task = $case === 'wrong-task' ? $otherTask : $this->task;
            $this->signedInAs($actor)->postJson("/api/v1/tasks/{$task->id}/updates", [
                'attachment_candidate_ids' => [$attachmentId],
                'idempotency_key' => "claim-{$case}",
            ])->assertUnprocessable()->assertJsonValidationErrors('attachment_candidate_ids');
            $this->enterTenant();
            $this->assertSame('tasks', Attachment::findOrFail($attachmentId)->attachable_type);
            $this->assertTrue(TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->exists());
        }
    }

    public function test_file_columns_accept_only_committed_files_from_this_task(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Column commenter', ['tasks.view', 'tasks.comment']);
        $editor = $this->userWithRole($this->tenant, 'Column editor', ['tasks.view', 'tasks.edit']);
        $this->scope($commenter);
        $this->scope($editor);
        $column = $this->createFileColumn();
        $candidate = $this->uploadCandidate($commenter, 'candidate.txt', 'candidate')->json('data.id');

        $this->signedInAs($editor)->putJson("/api/v1/tasks/{$this->task->id}/columns/{$column->id}", [
            'expected_version' => $this->task->fresh()->version,
            'value' => [$candidate],
        ])->assertUnprocessable();

        $update = $this->signedInAs($commenter)->postJson("/api/v1/tasks/{$this->task->id}/updates", [
            'attachment_candidate_ids' => [$candidate], 'idempotency_key' => 'column-file-1',
        ])->assertCreated();
        $this->signedInAs($editor)->putJson("/api/v1/tasks/{$this->task->id}/columns/{$column->id}", [
            'expected_version' => $this->task->fresh()->version,
            'value' => [$candidate],
        ])->assertOk()->assertJsonPath('data.value.0', $candidate);

        $otherTask = $this->createTask('Foreign file task');
        $foreign = $this->uploadCandidate($commenter, 'foreign.txt', 'foreign', $otherTask)->json('data.id');
        $this->signedInAs($commenter)->postJson("/api/v1/tasks/{$otherTask->id}/updates", [
            'attachment_candidate_ids' => [$foreign], 'idempotency_key' => 'column-file-foreign',
        ])->assertCreated();
        $this->signedInAs($editor)->putJson("/api/v1/tasks/{$this->task->id}/columns/{$column->id}", [
            'expected_version' => $this->task->fresh()->version,
            'value' => [$foreign],
        ])->assertUnprocessable();

        $this->assertNotNull($update->json('data.id'));
    }

    public function test_expiry_removes_matching_rows_and_bytes_but_only_the_marker_on_ownership_mismatch(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Expiry commenter', ['tasks.view', 'tasks.comment']);
        $this->scope($commenter);
        $matching = $this->uploadCandidate($commenter, 'expired.txt', 'expired')->json('data.id');
        $mismatch = $this->uploadCandidate($commenter, 'mismatch.txt', 'mismatch')->json('data.id');
        $this->enterTenant();
        TaskUpdateAttachmentCandidate::whereIn('attachment_id', [$matching, $mismatch])
            ->update(['expires_at' => now()->subSecond()]);
        $mismatchRow = Attachment::findOrFail($mismatch);
        $mismatchPath = $mismatchRow->path;
        $mismatchRow->forceFill(['attachable_type' => 'task_updates', 'attachable_id' => (string) Str::uuid()])->save();
        $matchingPath = Attachment::findOrFail($matching)->path;

        $this->artisan('ezbizi:expire-task-update-attachment-candidates')->assertSuccessful();
        $this->enterTenant();
        $this->assertNull(Attachment::find($matching));
        $this->assertFalse(Storage::disk('local')->exists($matchingPath));
        $this->assertNotNull(Attachment::find($mismatch));
        $this->assertTrue(Storage::disk('local')->exists($mismatchPath));
        $this->assertFalse(TaskUpdateAttachmentCandidate::whereIn('attachment_id', [$matching, $mismatch])->exists());
    }

    public function test_expiry_transaction_failure_keeps_rows_and_bytes(): void
    {
        $commenter = $this->userWithRole($this->tenant, 'Expiry rollback commenter', ['tasks.view', 'tasks.comment']);
        $this->scope($commenter);
        $attachmentId = $this->uploadCandidate($commenter, 'rollback-expiry.txt', 'rollback')->json('data.id');
        $this->enterTenant();
        TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->update(['expires_at' => now()->subSecond()]);
        $path = Attachment::findOrFail($attachmentId)->path;
        Attachment::deleting(function (Attachment $attachment) use ($attachmentId): void {
            if ($attachment->id === $attachmentId) {
                throw new RuntimeException('forced expiry rollback');
            }
        });

        try {
            $this->artisan('ezbizi:expire-task-update-attachment-candidates');
            $this->fail('Expected the cleanup transaction to roll back.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced expiry rollback', $exception->getMessage());
        }
        $this->enterTenant();
        $this->assertNotNull(Attachment::find($attachmentId));
        $this->assertTrue(TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->exists());
        $this->assertTrue(Storage::disk('local')->exists($path));
    }

    private function uploadCandidate($actor, string $name, string $contents, ?Task $task = null)
    {
        $task ??= $this->task;

        return $this->signedInAs($actor)->post("/api/v1/tasks/{$task->id}/update-attachments", [
            'file' => UploadedFile::fake()->createWithContent($name, $contents),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    private function createTask(string $title): Task
    {
        $this->signedInAs($this->tenant['admin']->fresh());
        $this->enterTenant();
        $group = $this->board->groups()->active()->firstOrFail();
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return Task::findOrFail($id);
    }

    private function createFileColumn(): TaskColumnDefinition
    {
        $this->signedInAs($this->tenant['admin']->fresh());
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => 'Files', 'type' => 'file',
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = $this->board->fresh();

        return TaskColumnDefinition::findOrFail($id);
    }

    private function scope($user): void
    {
        $this->enterTenant();
        $user->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }

    protected function tearDown(): void
    {
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
