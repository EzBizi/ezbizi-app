<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskImportSession;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskColumnValueStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskTransferTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    private string $viewId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['filesystems.default' => 'local']);
        $this->tenant = $this->provisionTenant('Transfer');
        app(TenantContext::class)->set($this->tenant['org']);
        $this->artisan('ezbizi:sync-permissions')->assertSuccessful();
        $this->tenant['admin']->forceFill([
            'preferences' => ['navigation.scope' => ViewScope::COMPANY.':'.$this->tenant['company']->id],
        ])->save();
        $this->actingAs($this->tenant['admin']->fresh());
        $boardId = $this->postJson('/api/v1/tasks/boards', ['name' => 'Transfer', 'visibility' => 'scope'])
            ->assertCreated()->json('data.id');
        $this->enterTenant();
        $this->board = TaskBoard::findOrFail($boardId);
        $this->viewId = $this->board->views()->where('is_permanent_main', true)->value('id');
    }

    public function test_csv_preview_and_one_use_commit_map_hierarchy_owner_status_and_dates(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $csv = "Source Row,Parent Source Row,Task,Owner Email,Status,Group,Start Date,Due Date\n"
            .'10,,Parent,'.strtoupper($this->tenant['admin']->email).",To do,To do,2026-09-01,2026-09-03\n"
            ."11,10,Child,,To do,,,2026-09-04\n";
        $mapping = [
            'source_row' => 'Source Row', 'parent_source_row' => 'Parent Source Row',
            'title' => 'Task', 'owner_email' => 'Owner Email', 'status' => 'Status',
            'group' => 'Group', 'start_on' => 'Start Date', 'due_on' => 'Due Date',
            'decisions' => [
                'statuses' => ['To do' => $status->id],
                'groups' => ['To do' => $group->id],
            ],
        ];

        $preview = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId,
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('monday.csv', $csv),
        ])->assertOk()->assertJsonPath('data.counts.ready', 2);
        $token = $preview->json('data.session_token');

        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', ['session_token' => $token])
            ->assertCreated()->assertJsonPath('data.created', 2);
        $this->enterTenant();
        $parent = Task::where('title', 'Parent')->firstOrFail();
        $child = Task::where('title', 'Child')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_task_id);
        $this->assertSame($this->tenant['admin']->id, $parent->owner_user_id);
        $this->assertSame('2026-09-03', $parent->due_on?->format('Y-m-d'));
        $this->assertNotNull(TaskImportSession::where('token_hash', hash('sha256', $token))->value('consumed_at'));
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', ['session_token' => $token])
            ->assertUnprocessable();
    }

    public function test_csv_export_is_private_formula_safe_and_json_is_an_archive_manifest(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => '=2+2', 'description' => '@customer',
            'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated();

        $csv = $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/csv")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString("'=2+2", $csv->streamedContent());
        $manifest = $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/json")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $decoded = json_decode($manifest->streamedContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('ezbizi.tasks.archive-manifest.v1', $decoded['format']);
        $this->assertFalse($decoded['includes_attachment_bytes']);
    }

    public function test_xlsx_preflight_rejects_formulas_before_loading_and_accepts_a_bounded_plain_sheet(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $mapping = json_encode([
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
            'decisions' => ['statuses' => ['To do' => $status->id], 'groups' => ['To do' => $group->id]],
        ], JSON_THROW_ON_ERROR);

        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => $mapping,
            'file' => $this->xlsxUpload([['Task', 'Status', 'Group'], ['=2+2', 'To do', 'To do']]),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => $mapping,
            'file' => $this->xlsxUpload([['Task', 'Status', 'Group'], ['Plain task', 'To do', 'To do']]),
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);
    }

    public function test_import_reconciles_all_custom_types_and_refuses_unowned_file_ids(): void
    {
        $columns = [];
        foreach (['text', 'number', 'checkbox', 'date', 'people', 'label', 'link', 'file'] as $type) {
            $columns[$type] = $this->createColumn(ucfirst($type), $type);
        }
        $optionId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns/{$columns['label']->id}/options", [
            'name' => 'Install', 'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $csv = "Task,Status,Group,Text,Number,Checkbox,Date,People,Label,Link,File\n"
            ."Typed,To do,To do,hello,12.5,true,2026-10-01,{$this->tenant['admin']->email},Install,https://example.test,".(string) Str::uuid()."\n";
        $custom = collect($columns)->mapWithKeys(fn ($column, $type) => [$column->id => ucfirst($type)])->all();
        $mapping = [
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group', 'custom' => $custom,
            'decisions' => [
                'statuses' => ['To do' => $status->id], 'groups' => ['To do' => $group->id],
                'column_options' => [$columns['label']->id => ['Install' => $optionId]],
            ],
        ];
        $blocked = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('typed.csv', $csv),
        ])->assertOk();
        $this->assertSame('file_unavailable', $blocked->json('data.reconciliation.0.code'));
        unset($mapping['custom'][$columns['file']->id]);
        $preview = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('typed.csv', $csv),
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'session_token' => $preview->json('data.session_token'),
        ])->assertCreated();
        $this->enterTenant();
        $task = Task::where('title', 'Typed')->firstOrFail();
        $this->assertSame('hello', app(TaskColumnValueStore::class)->value($task, $columns['text']));
        $this->assertSame('12.500000', app(TaskColumnValueStore::class)->value($task, $columns['number']));
        $this->assertTrue(app(TaskColumnValueStore::class)->value($task, $columns['checkbox']));
        $this->assertSame([$this->tenant['admin']->id], app(TaskColumnValueStore::class)->value($task, $columns['people']));
        $this->assertSame([$optionId], app(TaskColumnValueStore::class)->value($task, $columns['label']));
    }

    public function test_expiry_cleaner_deletes_locked_rows_before_staging_bytes_and_leaves_live_sessions(): void
    {
        Storage::disk('local')->put('task-imports/expired.csv', 'old');
        Storage::disk('local')->put('task-imports/live.csv', 'new');
        foreach ([['expired.csv', now()->subMinute()], ['live.csv', now()->addMinute()]] as [$name, $expires]) {
            TaskImportSession::create([
                'board_id' => $this->board->id, 'active_view_id' => $this->viewId,
                'actor_user_id' => $this->tenant['admin']->id,
                'token_hash' => hash('sha256', $name), 'nonce' => (string) Str::uuid7(),
                'disk' => 'local', 'path' => 'task-imports/'.$name, 'bytes' => 3,
                'sha256' => hash('sha256', $name === 'expired.csv' ? 'old' : 'new'),
                'normalized_mapping' => ['title' => 'Task'],
                'structure_version' => $this->board->structure_version, 'expires_at' => $expires,
            ]);
        }
        $this->artisan('ezbizi:expire-task-import-sessions')->assertSuccessful();
        $this->enterTenant();
        $this->assertFalse(TaskImportSession::where('path', 'task-imports/expired.csv')->exists());
        $this->assertTrue(TaskImportSession::where('path', 'task-imports/live.csv')->exists());
        Storage::disk('local')->assertMissing('task-imports/expired.csv');
        Storage::disk('local')->assertExists('task-imports/live.csv');
    }

    private function createColumn(string $name, string $type): TaskColumnDefinition
    {
        $id = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/columns", [
            'name' => $name, 'type' => $type,
            'expected_structure_version' => $this->board->fresh()->structure_version,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();

        return TaskColumnDefinition::findOrFail($id);
    }

    private function xlsxUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'task-xlsx-');
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'tasks.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
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
