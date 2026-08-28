<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskUpdateAttachmentConcurrencyTest extends TestCase
{
    use BuildsTenants;
    use DatabaseTruncation;

    /** Migration-owned singleton required by organization provisioning. */
    protected array $exceptTables = ['platform_subscription_defaults'];

    public function test_expiry_skips_a_candidate_locked_by_a_claim_that_then_commits(): void
    {
        Storage::fake('local');
        Carbon::setTestNow('2026-08-27 12:00:00');
        $tenant = $this->provisionTenant('Attachment Race');
        app(TenantContext::class)->set($tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $commenter = $this->userWithRole($tenant, 'Race commenter', ['tasks.view', 'tasks.comment']);
        foreach ([$tenant['admin'], $commenter] as $user) {
            $user->forceFill([
                'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$tenant['company']->id],
            ])->save();
        }

        $this->signedInAs($tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Race board', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($tenant['org']);
        $board = TaskBoard::findOrFail($boardId);
        $group = $board->groups()->active()->firstOrFail();
        $status = $board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$board->id}/tasks", [
            'title' => 'Race claim', 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($tenant['org']);
        $task = Task::findOrFail($taskId);
        $attachmentId = $this->signedInAs($commenter)->post("/api/v1/tasks/{$task->id}/update-attachments", [
            'file' => UploadedFile::fake()->createWithContent('race.txt', 'claimed bytes'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        app(TenantContext::class)->set($tenant['org']);
        TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)
            ->update(['expires_at' => now()->addSecond()]);
        $path = Attachment::findOrFail($attachmentId)->path;

        $claimName = 'task_attachment_claim';
        config(["database.connections.{$claimName}" => config('database.connections.mysql')]);
        $claim = DB::connection($claimName);
        $claim->beginTransaction();
        try {
            $candidate = $claim->table('task_update_attachment_candidates')
                ->where('attachment_id', $attachmentId)->lockForUpdate()->first();
            $attachment = $claim->table('attachments')
                ->where('id', $attachmentId)->lockForUpdate()->first();
            $this->assertNotNull($candidate);
            $this->assertNotNull($attachment);
            $this->assertGreaterThan(now(), Carbon::parse($candidate->expires_at));

            $updateId = (string) Str::uuid7();
            $claim->table('task_updates')->insert([
                'id' => $updateId,
                'organization_id' => $tenant['org']->id,
                'board_id' => $board->id,
                'task_id' => $task->id,
                'author_user_id' => $commenter->id,
                'body' => '',
                'body_plain' => '',
                'version' => 1,
                'idempotency_key' => 'race-claim',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $claim->table('attachments')->where('id', $attachmentId)->update([
                'attachable_type' => 'task_updates', 'attachable_id' => $updateId,
            ]);
            $claim->table('task_update_attachment_candidates')->where('attachment_id', $attachmentId)->delete();

            Carbon::setTestNow(now()->addSeconds(2));
            $this->artisan('ezbizi:expire-task-update-attachment-candidates')
                ->expectsOutput('Expired 0 staged task attachment(s).')
                ->assertSuccessful();
            app(TenantContext::class)->set($tenant['org']);
            $this->assertTrue(TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->exists());
            $this->assertSame('tasks', Attachment::findOrFail($attachmentId)->attachable_type);
            $this->assertTrue(Storage::disk('local')->exists($path));

            $claim->commit();
            app(TenantContext::class)->set($tenant['org']);
            $this->assertFalse(TaskUpdateAttachmentCandidate::where('attachment_id', $attachmentId)->exists());
            $committed = Attachment::findOrFail($attachmentId);
            $this->assertSame('task_updates', $committed->attachable_type);
            $this->assertSame($updateId, $committed->attachable_id);
            $this->assertTrue(Storage::disk('local')->exists($path));
        } finally {
            if ($claim->transactionLevel() > 0) {
                $claim->rollBack();
            }
            DB::purge($claimName);
            Carbon::setTestNow();
            app(TenantContext::class)->forget();
        }
    }
}
