<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Franchise;
use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnOption;
use App\Models\TaskGroup;
use App\Models\TaskImportSession;
use App\Models\TaskNotificationIntent;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\TaskUpdate;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\BoardMemberRole;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskNotificationIntentDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

final class TaskSecurityTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-28 12:00:00 UTC');
        Queue::fake();
        Storage::fake('local');
        $this->tenant = $this->provisionTenant('Task Security');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->lookAtCompany($this->tenant['admin']);
        $this->signedInAs($this->tenant['admin']->fresh());

        $boardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Protected work',
            'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $group = $this->board->groups()->active()->where('name', 'To do')->firstOrFail();
        $status = $this->board->statusOptions()->active()
            ->where('category', StatusCategory::NotStarted)->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'Protected task',
            'group_id' => $group->id,
            'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->task = Task::findOrFail($taskId);
    }

    public function test_residual_foreign_ids_and_public_task_attachment_targets_leave_no_footprint(): void
    {
        $otherId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Other board',
            'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $other = TaskBoard::findOrFail($otherId);
        $otherGroup = $other->groups()->active()->where('name', 'To do')->firstOrFail();
        $otherStatus = $other->statusOptions()->active()->firstOrFail();
        $otherPriority = $other->priorityOptions()->active()->firstOrFail();
        $otherTaskId = $this->postJson("/api/v1/tasks/boards/{$other->id}/tasks", [
            'title' => 'Other task',
            'group_id' => $otherGroup->id,
            'status_option_id' => $otherStatus->id,
        ])->assertCreated()->json('data.id');
        $columnId = $this->postJson("/api/v1/tasks/boards/{$other->id}/columns", [
            'name' => 'Other label',
            'type' => 'label',
            'expected_structure_version' => $other->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $other = $other->fresh();
        $optionId = $this->postJson("/api/v1/tasks/boards/{$other->id}/columns/{$columnId}/options", [
            'name' => 'Other option',
            'expected_structure_version' => $other->structure_version,
        ])->assertCreated()->json('data.id');

        $foreignTenant = $this->provisionTenant('Foreign Task Security');
        $this->signedInAs($this->tenant['admin']->fresh());
        $this->lookAtCompany($this->tenant['admin']);
        $this->enterTenant();
        $base = [
            'title' => 'Rejected task',
            'group_id' => $this->board->groups()->active()->where('name', 'To do')->value('id'),
            'status_option_id' => $this->board->statusOptions()->active()->value('id'),
        ];
        $attacks = [
            [['group_id' => $otherGroup->id], 404],
            [['status_option_id' => $otherStatus->id], 404],
            [['priority_option_id' => $otherPriority->id], 404],
            [['parent_task_id' => $otherTaskId], 404],
            [['owner_user_id' => $foreignTenant['admin']->id], 404],
            [['collaborator_user_ids' => [$foreignTenant['admin']->id]], 422],
        ];
        foreach ($attacks as [$attack, $status]) {
            $before = $this->footprint();
            $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
                ...$base,
                ...$attack,
            ])->assertStatus($status);
            $this->assertSame($before, $this->footprint());
        }

        $nestedAttacks = [
            fn () => $this->patchJson(
                "/api/v1/tasks/boards/{$this->board->id}/groups/{$otherGroup->id}",
                ['name' => 'Spoofed', 'expected_structure_version' => $this->board->fresh()->structure_version],
            ),
            fn () => $this->patchJson(
                "/api/v1/tasks/boards/{$this->board->id}/statuses/{$otherStatus->id}",
                ['name' => 'Spoofed', 'expected_structure_version' => $this->board->fresh()->structure_version],
            ),
            fn () => $this->patchJson(
                "/api/v1/tasks/boards/{$this->board->id}/priorities/{$otherPriority->id}",
                ['name' => 'Spoofed', 'expected_structure_version' => $this->board->fresh()->structure_version],
            ),
            fn () => $this->patchJson(
                "/api/v1/tasks/boards/{$this->board->id}/columns/{$columnId}/options/{$optionId}",
                ['name' => 'Spoofed', 'expected_structure_version' => $this->board->fresh()->structure_version],
            ),
        ];
        foreach ($nestedAttacks as $attack) {
            $before = $this->footprint();
            $attack()->assertNotFound();
            $this->assertSame($before, $this->footprint());
        }

        $before = $this->footprint();
        $this->post('/api/v1/attachments', [
            'attachable_type' => 'tasks',
            'attachable_id' => $this->task->id,
            'file' => UploadedFile::fake()->create('public-rebind.txt', 1, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('attachable_type');
        $this->assertSame($before, $this->footprint());
    }

    public function test_link_participant_html_and_stale_update_attacks_are_isolated(): void
    {
        $this->enterTenant();
        $siblingCompany = Company::create(['name' => 'Sibling company', 'is_active' => true]);
        $sibling = Franchise::create([
            'company_id' => $siblingCompany->id,
            'name' => 'Sibling branch',
            'is_active' => true,
        ]);
        $hiddenCustomer = Customer::factory()->create([
            'owning_franchise_id' => $sibling->id,
            'number' => 'C-HIDDEN',
            'name' => 'Hidden customer',
        ]);
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/{$this->task->id}/record-links", [
            'expected_task_version' => $this->task->fresh()->version,
            'record_type' => 'customer',
            'record_id' => $hiddenCustomer->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('record_id');
        $this->assertSame($before, $this->footprint());

        $foreignTenant = $this->provisionTenant('Forged Participant');
        $this->signedInAs($this->tenant['admin']->fresh());
        $this->lookAtCompany($this->tenant['admin']);
        $before = $this->footprint();
        $this->patchJson("/api/v1/tasks/{$this->task->id}", [
            'expected_version' => $this->task->fresh()->version,
            'owner_user_id' => $foreignTenant['admin']->id,
            'collaborator_user_ids' => [$foreignTenant['admin']->id],
        ])->assertNotFound();
        $this->assertSame($before, $this->footprint());

        $this->postJson("/api/v1/tasks/{$this->task->id}/updates", [
            'body' => '<p onclick="bad()">Safe <strong>text</strong><script>alert(1)</script> <a href="http://bad.test">bad</a> <a href="https://safe.test">safe</a></p>',
            'mention_user_ids' => [],
            'idempotency_key' => 'unsafe-update',
        ])->assertCreated();
        $this->enterTenant();
        $unsafe = TaskUpdate::where('idempotency_key', 'unsafe-update')->firstOrFail();
        $this->assertStringContainsString('<strong>text</strong>', $unsafe->body);
        $this->assertStringContainsString('https://safe.test', $unsafe->body);
        $this->assertStringNotContainsString('<script', $unsafe->body);
        $this->assertStringNotContainsString('onclick', $unsafe->body);
        $this->assertStringNotContainsString('http://bad.test', $unsafe->body);

        $this->patchJson("/api/v1/tasks/{$this->task->id}/updates/{$unsafe->id}", [
            'expected_update_version' => 1,
            'body' => '<p>Current body.</p>',
            'mention_user_ids' => [],
        ])->assertOk()->assertJsonPath('data.version', 2);
        $before = $this->footprint();
        $this->patchJson("/api/v1/tasks/{$this->task->id}/updates/{$unsafe->id}", [
            'expected_update_version' => 1,
            'body' => '<p>Stale overwrite.</p>',
            'mention_user_ids' => [],
        ])->assertConflict()->assertJsonPath('meta.current_update_version', 2);
        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertSame('<p>Current body.</p>', $unsafe->fresh()->body);
    }

    public function test_hostile_xlsx_packages_are_rejected_before_any_import_session_is_staged(): void
    {
        $viewId = $this->mainViewId();
        $uploads = [
            $this->mutatedXlsxUpload(fn (\ZipArchive $zip) => $zip->addFromString('xl/vbaProject.bin', 'macro')),
            $this->mutatedXlsxUpload(fn (\ZipArchive $zip) => $zip->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>')),
            $this->mutatedXlsxUpload(function (\ZipArchive $zip): void {
                for ($index = $zip->numFiles; $index <= 256; $index++) {
                    $zip->addFromString("padding/{$index}.txt", 'x');
                }
            }),
        ];

        foreach ($uploads as $upload) {
            $before = $this->footprint();
            $this->post("/api/v1/tasks/boards/{$this->board->id}/imports/preview", [
                'active_view_id' => $viewId,
                'file' => $upload,
            ])->assertUnprocessable()->assertJsonValidationErrors('file');
            $this->assertSame($before, $this->footprint());
        }
        Storage::disk('local')->assertDirectoryEmpty('task-imports/'.$this->board->organization_id);
    }

    public function test_import_sessions_are_bound_to_actor_board_view_structure_expiry_and_staged_bytes(): void
    {
        $viewId = $this->mainViewId();
        [$token, $session] = $this->previewCsv($viewId, 'actor-bound.csv');
        $otherActor = $this->userWithRole($this->tenant, 'Import intruder', [
            'tasks.view', 'tasks.create', 'tasks.manage',
        ]);
        $this->enterTenant();
        $otherActor->forceFill(['role_id' => $this->tenant['admin']->role_id])->save();
        TaskBoardMember::create([
            'board_id' => $this->board->id, 'user_id' => $otherActor->id,
            'role' => BoardMemberRole::Owner, 'is_active' => true,
        ]);
        $this->lookAtCompany($otherActor);
        $this->signedInAs($otherActor->fresh());
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/imports/commit", [
            'active_view_id' => $viewId, 'session_token' => $token,
        ])->assertUnprocessable()->assertJsonValidationErrors('session_token');
        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertNull($session->fresh()->consumed_at);

        $this->signedInAs($this->tenant['admin']->fresh());
        $this->lookAtCompany($this->tenant['admin']);
        $otherBoardId = $this->postJson('/api/v1/tasks/boards', [
            'name' => 'Import decoy', 'visibility' => 'scope',
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $otherBoard = TaskBoard::findOrFail($otherBoardId);
        $otherViewId = $otherBoard->views()->where('is_permanent_main', true)->value('id');
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/boards/{$otherBoard->id}/imports/commit", [
            'active_view_id' => $otherViewId, 'session_token' => $token,
        ])->assertUnprocessable()->assertJsonValidationErrors('session_token');
        $this->assertSame($before, $this->footprint());

        $alternateViewId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/views", [
            'name' => 'Alternate import view', 'visibility' => 'shared',
            'configuration' => [
                'schema' => 1, 'presentation' => 'table', 'filters' => [], 'sorts' => [],
                'hidden_columns' => [],
                'column_order' => ['task', 'owner', 'status', 'priority', 'start_on', 'due_on'],
                'column_widths' => (object) [], 'group_by' => null, 'search' => '',
            ],
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/imports/commit", [
            'active_view_id' => $alternateViewId, 'session_token' => $token,
        ])->assertNotFound();
        $this->assertSame($before, $this->footprint());

        [$staleToken, $staleSession] = $this->previewCsv($viewId, 'stale.csv');
        $this->enterTenant();
        $staleSession->forceFill(['structure_version' => $this->board->structure_version - 1])->save();
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/imports/commit", [
            'active_view_id' => $viewId, 'session_token' => $staleToken,
        ])->assertConflict();
        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertNull($staleSession->fresh()->consumed_at);

        [$tamperedToken, $tamperedSession] = $this->previewCsv($viewId, 'tampered.csv');
        Storage::disk($tamperedSession->disk)->put($tamperedSession->path, "Task\nChanged staged bytes\n");
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/imports/commit", [
            'active_view_id' => $viewId, 'session_token' => $tamperedToken,
        ])->assertUnprocessable()->assertJsonValidationErrors('session_token');
        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertNull($tamperedSession->fresh()->consumed_at);

        [$expiredToken, $expiredSession] = $this->previewCsv($viewId, 'expired.csv');
        $this->enterTenant();
        $expiredSession->forceFill(['expires_at' => now()->subSecond()])->save();
        $before = $this->footprint();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/imports/commit", [
            'active_view_id' => $viewId, 'session_token' => $expiredToken,
        ])->assertUnprocessable()->assertJsonValidationErrors('session_token');
        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertNull($expiredSession->fresh()->consumed_at);
    }

    public function test_failed_import_commit_rolls_back_session_and_every_shared_side_effect(): void
    {
        $viewId = $this->mainViewId();
        [$token, $session] = $this->previewCsv($viewId, 'rollback.csv');
        $before = $this->footprint();
        Task::created(static function (Task $created): void {
            if ($created->title === 'Imported safely') {
                throw new \RuntimeException('simulated post-insert failure');
            }
        });

        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/imports/commit", [
            'active_view_id' => $viewId, 'session_token' => $token,
        ])->assertServerError();

        $this->assertSame($before, $this->footprint());
        $this->enterTenant();
        $this->assertNull($session->fresh()->consumed_at);
        Storage::disk($session->disk)->assertExists($session->path);
    }

    public function test_notification_processing_leases_are_respected_reclaimed_and_crash_residue_is_idempotent(): void
    {
        $this->enterTenant();
        $this->task->forceFill(['owner_user_id' => $this->tenant['admin']->id])->save();
        $leased = $this->notificationIntent('unexpired-lease');
        $leased->forceFill([
            'status' => 'processing', 'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->addMinute(), 'attempts' => 1,
        ])->save();
        $dispatcher = app(TaskNotificationIntentDispatcher::class);
        $this->assertFalse($dispatcher->dispatchOne($leased->id));
        $this->enterTenant();
        $this->assertSame('processing', $leased->fresh()->status);
        $this->assertSame(0, Notification::where('delivery_key', $leased->id)->count());

        $reclaimed = $this->notificationIntent('expired-lease');
        $reclaimed->forceFill([
            'status' => 'processing', 'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->subSecond(), 'attempts' => 1,
        ])->save();
        $this->assertTrue($dispatcher->dispatchOne($reclaimed->id));
        $this->enterTenant();
        $this->assertSame('delivered', $reclaimed->fresh()->status);
        $this->assertSame(2, $reclaimed->fresh()->attempts);
        $this->assertNull($reclaimed->fresh()->claim_token);
        $this->assertSame(1, Notification::where('delivery_key', $reclaimed->id)->count());

        $residue = $this->notificationIntent('crash-residue');
        Notification::create([
            'organization_id' => $this->tenant['org']->id,
            'user_id' => $this->tenant['admin']->id,
            'source' => 'task', 'delivery_key' => $residue->id,
            'title' => 'Task assigned', 'body' => 'Existing durable delivery.',
            'subject_type' => Task::class, 'subject_id' => $this->task->id,
            'created_at' => now(),
        ]);
        $residue->forceFill([
            'status' => 'processing', 'claim_token' => (string) Str::uuid(),
            'claimed_until' => now()->subSecond(), 'attempts' => 1,
        ])->save();
        $this->assertTrue($dispatcher->dispatchOne($residue->id));
        $this->enterTenant();
        $this->assertSame('delivered', $residue->fresh()->status);
        $this->assertSame(1, Notification::where('delivery_key', $residue->id)->count());
    }

    private function footprint(): array
    {
        $this->enterTenant();

        return [
            'tasks' => Task::count(),
            'updates' => TaskUpdate::count(),
            'links' => DB::table('task_record_links')->count(),
            'activities' => TaskActivity::count(),
            'audits' => DB::table('audit_events')->where('action', 'like', 'task.%')->count(),
            'events' => AutomationEvent::count(),
            'intents' => TaskNotificationIntent::count(),
            'attachments' => Attachment::count(),
            'imports' => TaskImportSession::count(),
            'board_version' => (int) $this->board->fresh()->structure_version,
            'task_version' => (int) $this->task->fresh()->version,
            'detail_version' => (int) $this->task->fresh()->detail_version,
        ];
    }

    /** @return array{string, TaskImportSession} */
    private function previewCsv(string $viewId, string $name): array
    {
        $this->enterTenant();
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->where('name', 'To do')->firstOrFail();
        $mapping = [
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
            'decisions' => [
                'statuses' => ['To do' => $status->id],
                'groups' => ['To do' => $group->id],
            ],
        ];
        $response = $this->post("/api/v1/tasks/boards/{$this->board->id}/imports/preview", [
            'active_view_id' => $viewId,
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent(
                $name, "Task,Status,Group\nImported safely,To do,To do\n",
            ),
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);
        $token = $response->json('data.session_token');
        $this->enterTenant();

        return [$token, TaskImportSession::where('token_hash', hash('sha256', $token))->firstOrFail()];
    }

    private function mainViewId(): string
    {
        $this->enterTenant();

        return $this->board->views()->where('is_permanent_main', true)->value('id');
    }

    private function mutatedXlsxUpload(callable $mutate): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([['Task'], ['Plain task']]);
        $path = tempnam(sys_get_temp_dir(), 'task-security-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $mutate($zip);
        $zip->close();

        return new UploadedFile(
            $path, 'hostile.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true,
        );
    }

    private function notificationIntent(string $occurrence): TaskNotificationIntent
    {
        return TaskNotificationIntent::create([
            'board_id' => $this->board->id,
            'task_id' => $this->task->id,
            'recipient_user_id' => $this->tenant['admin']->id,
            'kind' => 'task.assigned',
            'source_occurrence_key' => $occurrence,
            'available_at' => now(),
        ]);
    }

    private function lookAtCompany($user): void
    {
        $this->enterTenant();
        $user->forceFill([
            'preferences' => [
                'navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id,
            ],
        ])->save();
    }

    private function enterTenant(): void
    {
        app(TenantContext::class)->set($this->tenant['org']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantContext::class)->forget();
        parent::tearDown();
    }
}
