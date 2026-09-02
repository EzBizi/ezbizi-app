<?php

namespace Tests\Feature\Tasks;

use App\Models\Customer;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskImportSession;
use App\Models\TaskUpdate;
use App\Models\TaskView;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\StatusCategory;
use App\Support\Tasks\TaskColumnValueStore;
use App\Support\Tasks\TaskViewCodec;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
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

        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'session_token' => $token, 'active_view_id' => $this->viewId,
        ])
            ->assertCreated()->assertJsonPath('data.created', 2);
        $this->enterTenant();
        $parent = Task::where('title', 'Parent')->firstOrFail();
        $child = Task::where('title', 'Child')->firstOrFail();
        $this->assertSame($parent->id, $child->parent_task_id);
        $this->assertSame($this->tenant['admin']->id, $parent->owner_user_id);
        $this->assertSame('2026-09-03', $parent->due_on?->format('Y-m-d'));
        $this->assertNotNull(TaskImportSession::where('token_hash', hash('sha256', $token))->value('consumed_at'));
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'session_token' => $token, 'active_view_id' => $this->viewId,
        ])
            ->assertUnprocessable();
    }

    /**
     * Whole-branch review finding 6: `start_on`/`due_on` used to be
     * validated independently during preview, so a 400-row import with one
     * inverted pair previewed clean and only failed at COMMIT, inside
     * `TaskWriter::create()`, with an error shaped for a single-task PATCH
     * (no row number) rather than this import surface's own reconciliation
     * list. Checked here during PREVIEW instead, reported with its row
     * number like every other row error, so `counts.ready` already
     * excludes it and commit is refused before ever reaching TaskWriter.
     */
    public function test_a_preview_with_an_inverted_start_and_due_date_pair_is_flagged_by_row_number(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $csv = "Task,Status,Group,Start Date,Due Date\n"
            ."Fine row,To do,To do,2026-09-01,2026-09-05\n"
            ."Inverted row,To do,To do,2026-09-10,2026-09-03\n";
        $mapping = [
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
            'start_on' => 'Start Date', 'due_on' => 'Due Date',
            'decisions' => [
                'statuses' => ['To do' => $status->id],
                'groups' => ['To do' => $group->id],
            ],
        ];

        $preview = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId,
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('dates.csv', $csv),
        ])->assertOk();

        // Row 2 is the header; "Fine row" is row 2, "Inverted row" is row 3.
        $this->assertSame(1, $preview->json('data.counts.ready'));
        $this->assertSame(1, $preview->json('data.counts.errors'));
        $entry = collect($preview->json('data.reconciliation'))
            ->firstWhere(fn (array $row) => $row['code'] === 'start_after_due');
        $this->assertNotNull($entry, 'The inverted pair must be reported.');
        $this->assertSame(3, $entry['row']);
        $this->assertSame('start_on', $entry['field']);
        $this->assertStringContainsString('start date cannot be after the due date', $entry['message']);
        $token = $preview->json('data.session_token');

        // Commit is refused entirely — the error is a preview-time row
        // flag, not something that lets 'Fine row' through while silently
        // dropping the other.
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'session_token' => $token, 'active_view_id' => $this->viewId,
        ])->assertUnprocessable();
        $this->enterTenant();
        $this->assertSame(0, Task::where('board_id', $this->board->id)->count());
    }

    public function test_csv_export_is_private_formula_safe_and_json_is_an_archive_manifest(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $taskId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => '=2+2', 'description' => '@customer',
            'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $updateId = $this->postJson("/api/v1/tasks/{$taskId}/updates", [
            'body' => '<p>First revision</p>', 'mention_user_ids' => [], 'idempotency_key' => 'archive-1',
        ])->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/tasks/{$taskId}/updates/{$updateId}", [
            'expected_update_version' => 1, 'body' => '<p>Current update</p>', 'mention_user_ids' => [],
        ])->assertOk();
        $this->enterTenant();
        $customer = Customer::factory()->create([
            'owning_franchise_id' => $this->tenant['franchise']->id, 'number' => 'C-EXPORT', 'name' => 'Manifest Customer',
        ]);
        $this->postJson("/api/v1/tasks/{$taskId}/record-links", [
            'expected_task_version' => Task::findOrFail($taskId)->version,
            'record_type' => 'customer', 'record_id' => $customer->id,
        ])->assertCreated();
        $this->enterTenant();
        DB::table('attachments')->insert([
            'id' => (string) Str::uuid7(), 'organization_id' => $this->tenant['org']->id,
            'attachable_type' => (new TaskUpdate)->getMorphClass(), 'attachable_id' => $updateId,
            'disk' => 'local', 'path' => 'private/never-export.txt', 'original_name' => 'proof.txt',
            'mime' => 'text/plain', 'size_bytes' => 5, 'checksum' => str_repeat('c', 64),
            'uploaded_by_user_id' => $this->tenant['admin']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $csv = $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/csv?active_view_id={$this->viewId}")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringContainsString("'=2+2", $csv->streamedContent());
        $manifest = $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/json?active_view_id={$this->viewId}")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $decoded = json_decode($manifest->streamedContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame('ezbizi.tasks.archive-manifest.v1', $decoded['format']);
        $this->assertFalse($decoded['includes_attachment_bytes']);
        $this->assertSame($this->viewId, $decoded['active_view_id']);
        $this->enterTenant();
        $archivedTask = collect($decoded['tasks'])->firstWhere('source_row', Task::findOrFail($taskId)->number);
        $this->assertSame('First revision', $archivedTask['updates'][0]['revisions'][0]['body_plain']);
        $this->assertSame('Current update', $archivedTask['updates'][0]['body_plain']);
        $this->assertSame('Manifest Customer', $archivedTask['record_links'][0]['label']);
        $this->assertSame('proof.txt', $archivedTask['updates'][0]['files'][0]['name']);
        $this->assertArrayNotHasKey('path', $archivedTask['updates'][0]['files'][0]);
        $this->assertStringNotContainsString('private/never-export.txt', $manifest->streamedContent());

        $viewer = $this->userWithRole($this->tenant, 'Exporter', ['tasks.view', 'tasks.export']);
        $this->actingAs($viewer);
        $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/json?active_view_id={$this->viewId}")
            ->assertForbidden();
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

    public function test_mappingless_xlsx_preflight_exposes_only_bounded_source_metadata_without_staging(): void
    {
        $long = str_repeat('x', 300);
        $rows = [['Task', 'Monday only'], [$long, 'discard me']];
        for ($index = 0; $index < 6; $index++) {
            $rows[] = ["Task {$index}", "extra {$index}"];
        }

        $response = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId,
            'file' => $this->xlsxUpload($rows),
        ])->assertOk()
            ->assertJsonPath('data.format', 'xlsx')
            ->assertJsonPath('data.headings', ['Task', 'Monday only'])
            ->assertJsonPath('data.requires_mapping', true)
            ->assertJsonPath('data.counts.rows', 7)
            ->assertJsonPath('data.counts.ready', 0)
            ->assertJsonPath('data.counts.errors', 0)
            ->assertJsonPath('data.reconciliation', [])
            ->assertJsonCount(5, 'data.sample')
            ->assertJsonPath('data.unsupported_fields.0', [
                'heading' => 'Task', 'reason' => 'not_mapped',
            ]);

        $this->assertLessThanOrEqual(200, strlen($response->json('data.sample.0.Task')));
        $this->assertArrayNotHasKey('session_token', $response->json('data'));
        $this->enterTenant();
        $this->assertSame(0, TaskImportSession::count());
    }

    public function test_preflight_rejects_unmappable_or_invalid_utf8_headings(): void
    {
        $oversized = str_repeat('é', 96);
        $url = '/api/v1/tasks/boards/'.$this->board->id.'/imports/preview';

        $this->post($url, [
            'active_view_id' => $this->viewId,
            'file' => UploadedFile::fake()->createWithContent('long.csv', "{$oversized}\nvalue\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->post($url, [
            'active_view_id' => $this->viewId,
            'file' => $this->xlsxUpload([[$oversized], ['value']]),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->post($url, [
            'active_view_id' => $this->viewId,
            'file' => UploadedFile::fake()->createWithContent('invalid.csv', "Task\xC3\x28\nvalue\n"),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_csv_and_xlsx_reconciliation_expose_bounded_decision_source_values_beyond_the_sample(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $mapping = json_encode([
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
            'decisions' => [
                'statuses' => ['To do' => $status->id],
                'groups' => ['To do' => $group->id],
            ],
        ], JSON_THROW_ON_ERROR);
        $decision = "Director's Review";
        $rows = [['Task', 'Status', 'Group']];
        for ($index = 1; $index <= 5; $index++) {
            $rows[] = ["Known {$index}", 'To do', 'To do'];
        }
        $rows[] = ['Needs decision', $decision, 'To do'];
        $csv = collect($rows)->map(fn (array $row) => implode(',', $row))->implode("\n")."\n";

        foreach ([
            'csv' => UploadedFile::fake()->createWithContent('decisions.csv', $csv),
            'xlsx' => $this->xlsxUpload($rows),
        ] as $format => $file) {
            $response = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
                'active_view_id' => $this->viewId,
                'mapping' => $mapping,
                'file' => $file,
            ])->assertOk()->assertJsonPath('data.format', $format);
            $entry = collect($response->json('data.reconciliation'))
                ->firstWhere(fn (array $row) => $row['code'] === 'decision_required' && $row['field'] === 'status');
            $this->assertSame(7, $entry['row']);
            $this->assertSame($decision, $entry['source_value']);
            $this->assertLessThanOrEqual(191, strlen($entry['source_value']));
            $this->assertStringNotContainsString($decision, json_encode($response->json('data.sample'), JSON_THROW_ON_ERROR));
        }

        $overlong = str_repeat('é', 96);
        $response = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId,
            'mapping' => $mapping,
            'file' => UploadedFile::fake()->createWithContent(
                'overlong-decision.csv', "Task,Status,Group\nToo long,{$overlong},To do\n",
            ),
        ])->assertOk()->assertJsonPath('data.reconciliation.0.code', 'source_value_too_long');
        $this->assertArrayNotHasKey('source_value', $response->json('data.reconciliation.0'));
        $this->assertStringNotContainsString($overlong, $response->getContent());
    }

    public function test_xlsx_relationships_find_nonstandard_sheets_and_namespace_prefixed_formulas(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $mapping = json_encode([
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
            'decisions' => ['statuses' => ['To do' => $status->id], 'groups' => ['To do' => $group->id]],
        ], JSON_THROW_ON_ERROR);
        $nonstandard = $this->mutatedXlsxUpload(function (\ZipArchive $zip): void {
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
            $zip->deleteName('xl/worksheets/sheet1.xml');
            $zip->addFromString('xl/custom/data.xml', $sheet);
            $rels = str_replace('worksheets/sheet1.xml', 'custom/data.xml', $zip->getFromName('xl/_rels/workbook.xml.rels'));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
            $types = str_replace('/xl/worksheets/sheet1.xml', '/xl/custom/data.xml', $zip->getFromName('[Content_Types].xml'));
            $zip->addFromString('[Content_Types].xml', $types);
        });
        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => $mapping, 'file' => $nonstandard,
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);

        $prefixedFormula = $this->mutatedXlsxUpload(function (\ZipArchive $zip): void {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('/(<c[^>]*r="A2"[^>]*>)/', '$1<x:f xmlns:x="urn:formula">2+2</x:f>', $xml, 1);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        });
        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => $mapping, 'file' => $prefixedFormula,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $missingDimension = $this->mutatedXlsxUpload(function (\ZipArchive $zip): void {
            $xml = preg_replace('/<dimension[^>]*\/>/', '', $zip->getFromName('xl/worksheets/sheet1.xml'), 1);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        });
        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => $mapping, 'file' => $missingDimension,
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);

        $falseDimension = $this->mutatedXlsxUpload(function (\ZipArchive $zip): void {
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            $xml = preg_replace('/<dimension[^>]*\/>/', '<dimension ref="A1"/>', $xml, 1);
            $xml = preg_replace('/(<row[^>]*r="2"[^>]*>)/', '$1<c r="CW2" t="inlineStr"><is><t>x</t></is></c>', $xml, 1);
            $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        });
        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => $mapping, 'file' => $falseDimension,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
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
        $seedId = $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
            'title' => 'File seed', 'group_id' => $group->id, 'status_option_id' => $status->id,
        ])->assertCreated()->json('data.id');
        $this->enterTenant();
        $attachmentId = (string) Str::uuid7();
        DB::table('attachments')->insert([
            'id' => $attachmentId, 'organization_id' => $this->tenant['org']->id,
            'attachable_type' => (new Task)->getMorphClass(), 'attachable_id' => $seedId, 'disk' => 'local', 'path' => 'tests/round-trip.txt',
            'original_name' => 'round-trip.txt', 'mime' => 'text/plain', 'size_bytes' => 4,
            'checksum' => str_repeat('b', 64), 'uploaded_by_user_id' => $this->tenant['admin']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $unavailableId = (string) Str::uuid();
        $csv = "Task,Status,Group,Text,Number,Checkbox,Date,People,Label,Link,File\n"
            ."Typed,To do,To do,hello,90071992547409.123456,true,2026-10-01,{$this->tenant['admin']->email},Install,https://example.test/path|Reference,{$unavailableId}\n";
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
        $csv = str_replace($unavailableId, $attachmentId, $csv);
        $preview = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('typed.csv', $csv),
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'session_token' => $preview->json('data.session_token'), 'active_view_id' => $this->viewId,
        ])->assertCreated();
        $this->enterTenant();
        $task = Task::where('title', 'Typed')->firstOrFail();
        $this->assertSame('hello', app(TaskColumnValueStore::class)->value($task, $columns['text']));
        $this->assertSame('90071992547409.123456', app(TaskColumnValueStore::class)->value($task, $columns['number']));
        $this->assertTrue(app(TaskColumnValueStore::class)->value($task, $columns['checkbox']));
        $this->assertSame('2026-10-01', app(TaskColumnValueStore::class)->value($task, $columns['date']));
        $this->assertSame([$this->tenant['admin']->id], app(TaskColumnValueStore::class)->value($task, $columns['people']));
        $this->assertSame([$optionId], app(TaskColumnValueStore::class)->value($task, $columns['label']));
        $this->assertSame(['url' => 'https://example.test/path', 'label' => 'Reference'], app(TaskColumnValueStore::class)->value($task, $columns['link']));
        $this->assertSame([$attachmentId], app(TaskColumnValueStore::class)->value($task, $columns['file']));

        $configuration = app(TaskViewCodec::class)->defaults($this->board);
        $configuration['filters'] = [['field' => 'task', 'operator' => 'equals', 'value' => 'Typed']];
        TaskView::findOrFail($this->viewId)->forceFill(['configuration' => $configuration])->save();
        $export = $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/csv?active_view_id={$this->viewId}")
            ->assertOk()->streamedContent();
        $roundTripMapping = [
            'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
            'custom' => collect($columns)->mapWithKeys(fn ($column) => [
                $column->id => "Custom [{$column->id}] {$column->name}",
            ])->all(),
            'decisions' => [
                'statuses' => [$status->name => $status->id], 'groups' => [$group->name => $group->id],
                'column_options' => [$columns['label']->id => ['Install' => $optionId]],
            ],
        ];
        $roundTrip = $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => $this->viewId, 'mapping' => json_encode($roundTripMapping, JSON_THROW_ON_ERROR),
            'file' => UploadedFile::fake()->createWithContent('round-trip.csv', $export),
        ])->assertOk()->assertJsonPath('data.counts.ready', 1);
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'session_token' => $roundTrip->json('data.session_token'), 'active_view_id' => $this->viewId,
        ])->assertCreated();
        $this->enterTenant();
        $copy = Task::where('title', 'Typed')->latest('created_at')->firstOrFail();
        foreach ($columns as $type => $column) {
            $this->assertSame(app(TaskColumnValueStore::class)->value($task, $column), app(TaskColumnValueStore::class)->value($copy, $column), $type);
        }
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

    public function test_board_and_active_view_refusal_happens_before_attacker_controlled_validation(): void
    {
        $outsider = $this->userWithRole($this->tenant, 'No task access', []);
        $this->actingAs($outsider);

        $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
            'active_view_id' => 'not-a-uuid', 'mapping' => '{',
        ])->assertForbidden()->assertJsonMissingValidationErrors(['active_view_id', 'mapping', 'file']);
        $this->postJson('/api/v1/tasks/boards/'.$this->board->id.'/imports/commit', [
            'active_view_id' => 'not-a-uuid', 'session_token' => 'bad',
        ])->assertForbidden()->assertJsonMissingValidationErrors(['active_view_id', 'session_token']);
        $this->get('/api/v1/tasks/boards/'.$this->board->id.'/exports/csv?active_view_id=not-a-uuid&include_archived=bad')
            ->assertForbidden()->assertJsonMissingValidationErrors(['active_view_id', 'include_archived']);
    }

    public function test_spreadsheet_export_uses_the_authorized_saved_view_query_and_visible_columns(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        foreach (['Keep', 'Drop'] as $title) {
            $this->postJson("/api/v1/tasks/boards/{$this->board->id}/tasks", [
                'title' => $title, 'group_id' => $group->id, 'status_option_id' => $status->id,
                'due_on' => '2026-10-01',
            ])->assertCreated();
        }
        $this->enterTenant();
        $view = TaskView::findOrFail($this->viewId);
        $configuration = app(TaskViewCodec::class)->defaults($this->board);
        $configuration['filters'] = [['field' => 'task', 'operator' => 'equals', 'value' => 'Keep']];
        $configuration['hidden_columns'] = ['due_on'];
        $view->forceFill(['configuration' => $configuration])->save();

        $response = $this->get("/api/v1/tasks/boards/{$this->board->id}/exports/csv?active_view_id={$this->viewId}")
            ->assertOk()->assertHeader('X-EzBizi-Task-View-Id', $this->viewId);
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Keep', $csv);
        $this->assertStringNotContainsString('Drop', $csv);
        $this->assertStringNotContainsString('Due Date', $csv);
    }

    public function test_non_owner_exporter_can_omit_or_disable_archived_rows_but_cannot_enable_them_or_export_json(): void
    {
        $viewer = $this->userWithRole($this->tenant, 'Scoped exporter', ['tasks.view', 'tasks.export']);
        $this->actingAs($viewer);
        $url = "/api/v1/tasks/boards/{$this->board->id}/exports";

        $this->get("{$url}/csv?active_view_id={$this->viewId}")->assertOk();
        $this->get("{$url}/csv?active_view_id={$this->viewId}&include_archived=0")->assertOk();
        $this->get("{$url}/csv?active_view_id={$this->viewId}&include_archived=1")->assertForbidden();
        $this->get("{$url}/json?active_view_id={$this->viewId}")->assertForbidden();
    }

    public function test_preview_compensates_private_bytes_when_session_creation_fails(): void
    {
        $status = $this->board->statusOptions()->active()->where('category', StatusCategory::NotStarted)->firstOrFail();
        $group = $this->board->groups()->active()->firstOrFail();
        $fail = true;
        TaskImportSession::creating(function () use (&$fail): void {
            if ($fail) {
                $fail = false;
                throw new \RuntimeException('forced session failure');
            }
        });
        $this->withoutExceptionHandling();
        try {
            $this->post('/api/v1/tasks/boards/'.$this->board->id.'/imports/preview', [
                'active_view_id' => $this->viewId,
                'mapping' => json_encode([
                    'title' => 'Task', 'status' => 'Status', 'group' => 'Group',
                    'decisions' => ['statuses' => ['To do' => $status->id], 'groups' => ['To do' => $group->id]],
                ], JSON_THROW_ON_ERROR),
                'file' => UploadedFile::fake()->createWithContent('tasks.csv', "Task,Status,Group\nOne,To do,To do\n"),
            ]);
            $this->fail('The forced session write failure did not escape.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('forced session failure', $exception->getMessage());
        } finally {
            $this->withExceptionHandling();
        }
        Storage::disk('local')->assertDirectoryEmpty('task-imports/'.$this->board->organization_id);
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

    private function mutatedXlsxUpload(callable $mutate): UploadedFile
    {
        $upload = $this->xlsxUpload([['Task', 'Status', 'Group'], ['Plain task', 'To do', 'To do']]);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($upload->getPathname()) === true);
        $mutate($zip);
        $zip->close();

        return $upload;
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
