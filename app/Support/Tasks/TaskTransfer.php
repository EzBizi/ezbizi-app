<?php

namespace App\Support\Tasks;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskColumnDefinition;
use App\Models\TaskGroup;
use App\Models\TaskImportSession;
use App\Models\TaskPriorityOption;
use App\Models\TaskStatusOption;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Models\TaskView;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Files\FileStore;
use App\Support\Scoping\ViewScope;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as SpreadsheetDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

final class TaskTransfer
{
    private const PREFLIGHT_SAMPLE_ROWS = 5;

    private const PREFLIGHT_SAMPLE_CELL_BYTES = 200;

    private const MAX_HEADING_BYTES = 191;

    private const MAX_DECISION_SOURCE_BYTES = 191;

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_ROWS = 5000;

    private const MAX_COLUMNS = 100;

    private const MAX_CELLS = 500000;

    private const MAX_CELL_BYTES = 65535;

    public function __construct(
        private readonly FileStore $files,
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
        private readonly TaskWriter $tasks,
        private readonly TaskColumnValueStore $values,
        private readonly AuditLogger $audit,
        private readonly TaskViewCodec $viewCodec,
        private readonly TaskListQuery $listQuery,
        private readonly TaskRecordLinkRegistry $recordLinks,
    ) {}

    public function preview(
        User $actor,
        TaskBoard $board,
        ViewScope $scope,
        string $activeViewId,
        UploadedFile $file,
        array $mapping,
    ): array {
        $this->authorizeImport($actor, $board, $scope, $activeViewId);
        $normalizedMapping = $this->normalizeMapping($mapping);
        [$bytes, $format, $rows] = $this->uploadedRows($file);
        $prepared = $this->prepareRows($board, $rows, $normalizedMapping);
        $reconciliation = $prepared['errors'];
        $token = bin2hex(random_bytes(32));
        $disk = $this->files->defaultDisk();
        $path = $this->files->putContents(
            $bytes,
            $this->files->directoryFor('task-imports', $board->organization_id),
            'csv',
            $disk,
        );
        try {
            TaskImportSession::create([
                'board_id' => $board->id,
                'active_view_id' => $activeViewId,
                'actor_user_id' => $actor->id,
                'token_hash' => hash('sha256', $token),
                'nonce' => (string) Str::uuid7(),
                'disk' => $disk,
                'path' => $path,
                'bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'normalized_mapping' => [...$normalizedMapping, '_format' => $format],
                'structure_version' => (int) $board->fresh()->structure_version,
                'expires_at' => now()->addMinutes(30),
            ]);
        } catch (\Throwable $exception) {
            $this->files->delete($path, $disk);

            throw $exception;
        }

        return [
            'session_token' => $token,
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ...$this->sourceMetadata($format, $rows, $normalizedMapping),
            'counts' => [
                'rows' => count($rows),
                'ready' => collect($rows)->keys()->reject(fn ($offset) => collect($reconciliation)
                    ->contains(fn ($error) => $error['row'] === null || $error['row'] === $offset + 2))->count(),
                'errors' => count($reconciliation),
            ],
            'reconciliation' => $reconciliation,
        ];
    }

    public function preflight(
        User $actor,
        TaskBoard $board,
        ViewScope $scope,
        string $activeViewId,
        UploadedFile $file,
    ): array {
        $this->authorizeImport($actor, $board, $scope, $activeViewId);
        [, $format, $rows] = $this->uploadedRows($file);

        return [
            ...$this->sourceMetadata($format, $rows, null),
            'counts' => ['rows' => count($rows), 'ready' => 0, 'errors' => 0],
            'reconciliation' => [],
        ];
    }

    public function commit(User $actor, TaskBoard $board, ViewScope $scope, string $activeViewId, string $token): array
    {
        $path = null;
        $disk = null;
        $result = DB::transaction(function () use ($actor, $board, $scope, $activeViewId, $token, &$path, &$disk): array {
            $session = TaskImportSession::query()->where('token_hash', hash('sha256', $token))
                ->lockForUpdate()->first();
            if ($session === null || $session->board_id !== $board->id || $session->actor_user_id !== $actor->id
                || $session->consumed_at !== null || $session->expires_at->isPast()) {
                throw ValidationException::withMessages(['session_token' => 'This import preview is unavailable, expired, or already used.']);
            }
            abort_unless(hash_equals($session->active_view_id, $activeViewId), 404);
            $this->authorizeImport($actor, $board, $scope, $activeViewId);
            $lockedBoard = TaskBoard::query()->whereKey($board->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedBoard->structure_version !== (int) $session->structure_version) {
                abort(409, 'The board structure changed. Preview this import again.');
            }
            $bytes = $this->files->contents($session->path, $session->disk);
            if (! is_string($bytes) || strlen($bytes) !== (int) $session->bytes
                || ! hash_equals($session->sha256, hash('sha256', $bytes))) {
                throw ValidationException::withMessages(['session_token' => 'The staged import file is unavailable or changed. Preview it again.']);
            }
            [$format, $rows] = $this->parseUpload($bytes);
            $mapping = $session->normalized_mapping;
            unset($mapping['_format']);
            $prepared = $this->prepareRows($lockedBoard, $rows, $mapping);
            if ($prepared['errors'] !== []) {
                throw ValidationException::withMessages(['file' => collect($prepared['errors'])->pluck('message')->all()]);
            }

            $created = [];
            $bySource = [];
            foreach ($prepared['rows'] as $row) {
                $attributes = $row['attributes'];
                if ($row['parent_source'] !== null) {
                    $attributes['parent_task_id'] = $bySource[$row['parent_source']]->id;
                    unset($attributes['group_id']);
                }
                $task = $this->tasks->create($actor, $lockedBoard, $scope, $attributes);
                foreach ($row['custom'] as [$definition, $value]) {
                    $task = $this->values->replace($actor, $task, $definition, $scope, (int) $task->version, $value);
                }
                $created[] = $task;
                $bySource[$row['source']] = $task;
            }
            $place = TaskPlace::auditScope($lockedBoard);
            $audit = $this->audit->log('task.imported', target: $lockedBoard, changes: [], metadata: [
                'created' => count($created), 'source_format' => $format,
            ], actor: $actor, scopeType: $place['type'], scopeId: $place['id']);
            if ($created !== []) {
                TaskActivity::create([
                    'board_id' => $lockedBoard->id,
                    'task_id' => $created[0]->id,
                    'actor_user_id' => $actor->id,
                    'kind' => 'task.imported',
                    'summary' => 'Imported tasks from a spreadsheet',
                    'data' => ['created' => count($created), 'audit_event_id' => $audit?->id],
                ]);
            }
            $session->forceFill(['consumed_at' => now()])->save();
            $path = $session->path;
            $disk = $session->disk;

            return ['created' => count($created), 'task_ids' => collect($created)->pluck('id')->all()];
        });
        $this->files->delete($path, $disk);

        return $result;
    }

    public function export(User $actor, TaskBoard $board, ViewScope $scope, string $activeViewId, string $format, bool $includeArchived): StreamedResponse
    {
        if (! in_array($format, ['csv', 'xlsx', 'json'], true)) {
            abort(404);
        }
        $view = $this->authorizeExport($actor, $board, $scope, $activeViewId, $includeArchived || $format === 'json');
        $configuration = $this->viewCodec->decode($board, $view->configuration);
        $taskIds = null;
        if ($format !== 'json') {
            $viewRows = $this->listQuery->boardRows($actor, $scope, $board, $configuration, 1, self::MAX_ROWS);
            if ($viewRows['total'] > self::MAX_ROWS) {
                throw ValidationException::withMessages(['active_view_id' => 'This view contains more than 5,000 parent tasks and cannot be exported.']);
            }
            $taskIds = $this->flattenViewTaskIds($viewRows['data']);
        }
        $snapshot = $this->exportSnapshot($actor, $board, $scope, $activeViewId, $includeArchived, $taskIds, $configuration);
        $organization = $actor->organization;
        $filename = Str::slug($board->name ?: 'tasks').($format === 'json' ? '-archive-manifest.json' : ".{$format}");
        $mime = match ($format) {
            'csv' => 'text/csv; charset=UTF-8',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            default => 'application/json',
        };

        return response()->streamDownload(function () use ($format, $snapshot, $organization): void {
            app(TenantContext::class)->run($organization, function () use ($format, $snapshot): void {
                if ($format === 'csv') {
                    $out = fopen('php://output', 'wb');
                    fputcsv($out, array_keys($snapshot['rows'][0] ?? $snapshot['headings']), ',', '"', '');
                    foreach ($snapshot['rows'] as $row) {
                        fputcsv($out, array_map(fn ($value) => $this->csvCell((string) $value), array_values($row)), ',', '"', '');
                    }
                    fclose($out);

                    return;
                }
                if ($format === 'xlsx') {
                    $sheet = (new Spreadsheet)->getActiveSheet();
                    $headings = array_keys($snapshot['rows'][0] ?? $snapshot['headings']);
                    foreach ($headings as $column => $heading) {
                        $sheet->setCellValueExplicit([$column + 1, 1], (string) $heading, DataType::TYPE_STRING);
                    }
                    foreach ($snapshot['rows'] as $rowIndex => $row) {
                        foreach (array_values($row) as $column => $value) {
                            $sheet->setCellValueExplicit([$column + 1, $rowIndex + 2], (string) $value, DataType::TYPE_STRING);
                        }
                    }
                    (new Xlsx($sheet->getParent()))->save('php://output');

                    return;
                }
                echo json_encode([
                    'format' => 'ezbizi.tasks.archive-manifest.v1',
                    'includes_attachment_bytes' => false,
                    'attachment_note' => 'Attachment bytes are not included. Authorized file entries contain identifier, name, size, and SHA-256 only.',
                    'active_view_id' => $snapshot['active_view_id'],
                    'board' => $snapshot['board'],
                    'tasks' => $snapshot['manifest_rows'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            });
        }, $filename, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'X-EzBizi-Task-View-Id' => $view->id,
        ]);
    }

    private function exportSnapshot(User $actor, TaskBoard $board, ViewScope $scope, string $activeViewId, bool $includeArchived, ?array $taskIds, array $configuration): array
    {
        $columns = $board->columnDefinitions()->with('options')->orderBy('position')->get();
        $tasks = Task::query()->where('board_id', $board->id)
            ->when(! $includeArchived, fn ($query) => $query->active())
            ->when($taskIds !== null, fn ($query) => $query->whereIn('id', $taskIds))
            ->with(['group', 'parent', 'owner', 'statusOption', 'priorityOption', 'collaborators.user',
                'columnValues', 'columnPeople.user', 'columnLabels.option', 'columnFiles.attachment',
                'updates.versions', 'updates.attachments.uploadedBy', 'recordLinks'])
            ->get();
        if ($taskIds !== null) {
            $positions = array_flip($taskIds);
            $tasks = $tasks->sortBy(fn (Task $task) => $positions[$task->id] ?? PHP_INT_MAX)->values();
        }
        $headings = array_fill_keys([
            'Source Row', 'Parent Source Row', 'Task', 'Description', 'Owner Email', 'Collaborator Emails',
            'Status', 'Status Category', 'Priority', 'Group', 'Start Date', 'Due Date', 'Archived',
        ], '');
        foreach ($columns as $column) {
            $headings["Custom [{$column->id}] {$column->name}"] = '';
        }
        if ($taskIds !== null) {
            $visible = array_fill_keys(array_diff($configuration['column_order'], $configuration['hidden_columns']), true);
            $coreHeadings = [
                'owner' => ['Owner Email', 'Collaborator Emails'], 'status' => ['Status', 'Status Category'],
                'priority' => ['Priority'], 'start_on' => ['Start Date'], 'due_on' => ['Due Date'],
            ];
            foreach ($coreHeadings as $reference => $names) {
                if (! isset($visible[$reference])) {
                    foreach ($names as $name) {
                        unset($headings[$name]);
                    }
                }
            }
            foreach ($columns as $column) {
                if (! isset($visible['custom:'.$column->id])) {
                    unset($headings["Custom [{$column->id}] {$column->name}"]);
                }
            }
        }
        $rows = [];
        $manifest = [];
        foreach ($tasks as $task) {
            $row = [
                'Source Row' => $task->number,
                'Parent Source Row' => $task->parent?->number ?? '',
                'Task' => $task->title,
                'Description' => $task->description_plain ?? '',
                'Owner Email' => $task->owner?->email ?? '',
                'Collaborator Emails' => $task->collaborators->pluck('user.email')->filter()->implode(';'),
                'Status' => $task->statusOption?->name ?? '',
                'Status Category' => $task->statusOption?->category?->value ?? '',
                'Priority' => $task->priorityOption?->name ?? '',
                'Group' => $task->group?->name ?? $task->parent?->group?->name ?? '',
                'Start Date' => $task->start_on?->format('Y-m-d') ?? '',
                'Due Date' => $task->due_on?->format('Y-m-d') ?? '',
                'Archived' => $task->archived_at === null ? 'false' : 'true',
            ];
            $customManifest = [];
            foreach ($columns as $column) {
                $value = $this->values->value($task, $column);
                if ($column->type === ColumnType::File) {
                    $attachments = $task->columnFiles->where('column_definition_id', $column->id)
                        ->pluck('attachment')->filter()->map(fn ($attachment) => $this->archiveFile($attachment))->values()->all();
                    $row["Custom [{$column->id}] {$column->name}"] = collect($attachments)->pluck('id')->implode(';');
                    $customManifest[$column->id] = $attachments;
                } else {
                    $customManifest[$column->id] = $value;
                    $row["Custom [{$column->id}] {$column->name}"] = $this->canonicalCustomCell($task, $column, $value);
                }
            }
            $rows[] = array_intersect_key($row, $headings);
            $manifest[] = [
                'source_row' => $task->number, 'parent_source_row' => $task->parent?->number,
                'title' => $task->title, 'description' => $task->description_plain,
                'owner_email' => $task->owner?->email, 'status' => $task->statusOption?->name,
                'status_category' => $task->statusOption?->category?->value,
                'priority' => $task->priorityOption?->name, 'group' => $task->group?->name ?? $task->parent?->group?->name,
                'start_on' => $task->start_on?->format('Y-m-d'), 'due_on' => $task->due_on?->format('Y-m-d'),
                'archived' => $task->archived_at !== null, 'custom_values' => $customManifest,
                'updates' => $task->updates->map(fn (TaskUpdate $update) => [
                    'id' => $update->id,
                    'version' => (int) $update->version,
                    'body_plain' => $update->body_plain,
                    'author_user_id' => $update->author_user_id,
                    'archived_at' => $update->archived_at?->toIso8601String(),
                    'revisions' => $update->versions->sortBy('version')->map(fn ($revision) => [
                        'version' => (int) $revision->version,
                        'body_plain' => $revision->body_plain,
                        'edited_by' => $revision->edited_by,
                        'created_at' => $revision->created_at?->toIso8601String(),
                    ])->values()->all(),
                    'files' => $update->attachments->map(fn (Attachment $attachment) => $this->archiveFile($attachment))->values()->all(),
                ])->values()->all(),
                'record_links' => $task->recordLinks->map(function ($link) use ($actor, $board, $scope): ?array {
                    try {
                        $definition = $this->recordLinks->get($link->record_type);
                        $record = $definition->resolve($actor, $board, $scope, $link->record_id);

                        return $record === null ? null : ['link_id' => $link->id, ...$definition->present($record)];
                    } catch (\InvalidArgumentException) {
                        return null;
                    }
                })->filter()->values()->all(),
            ];
        }

        return [
            'headings' => $headings, 'rows' => $rows, 'manifest_rows' => $manifest,
            'board' => ['id' => $board->id, 'name' => $board->name, 'structure_version' => (int) $board->structure_version],
            'active_view_id' => $activeViewId,
        ];
    }

    private function archiveFile(Attachment $attachment): array
    {
        return [
            'id' => $attachment->id,
            'name' => $attachment->original_name,
            'size' => (int) $attachment->size_bytes,
            'sha256' => $attachment->checksum,
        ];
    }

    /** @return list<string> */
    private function flattenViewTaskIds(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = $row['id'];
            foreach ($row['subtasks'] ?? [] as $subtask) {
                $ids[] = $subtask['id'];
            }
        }

        return $ids;
    }

    private function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    private function canonicalCustomCell(Task $task, TaskColumnDefinition $column, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return match ($column->type) {
            ColumnType::Text, ColumnType::Number, ColumnType::Date => (string) $value,
            ColumnType::Checkbox => $value ? 'true' : 'false',
            ColumnType::People => $task->columnPeople->where('column_definition_id', $column->id)
                ->pluck('user.email')->filter()->map(fn (string $email) => strtolower($email))->sort()->implode(';'),
            ColumnType::Label => $task->columnLabels->where('column_definition_id', $column->id)
                ->pluck('option.name')->filter()->sort()->implode(';'),
            ColumnType::Link => $value['url'].(($value['label'] ?? null) === null ? '' : '|'.$value['label']),
            ColumnType::File => $task->columnFiles->where('column_definition_id', $column->id)
                ->pluck('attachment_id')->sort()->implode(';'),
        };
    }

    public function authorizeImport(User $actor, TaskBoard $board, ViewScope $scope, string $activeViewId): void
    {
        $this->access->manageDecision($actor, $board, $scope)->authorize();
        if (! $actor->hasPermission('tasks.create')) {
            Refusal::permission('import tasks')->authorize();
        }
        $visible = TaskView::query()->where('board_id', $board->id)->whereKey($activeViewId)
            ->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('owner_user_id', $actor->id))
            ->exists();
        abort_unless($visible, 404);
    }

    public function authorizeExport(
        User $actor,
        TaskBoard $board,
        ViewScope $scope,
        string $activeViewId,
        bool $includeArchived,
    ): TaskView {
        if (! $actor->hasPermission('tasks.export')) {
            Refusal::permission('export tasks')->authorize();
        }
        $this->access->viewDecision($actor, $board, $scope)->authorize();
        if ($includeArchived) {
            $this->access->manageDecision($actor, $board, $scope)->authorize();
        }

        return TaskView::query()->where('board_id', $board->id)->whereKey($activeViewId)
            ->where(fn ($query) => $query->where('visibility', 'shared')->orWhere('owner_user_id', $actor->id))
            ->firstOrFail();
    }

    private function normalizeMapping(array $mapping): array
    {
        $allowed = [
            'source_row', 'parent_source_row', 'title', 'description', 'group', 'owner_email',
            'status', 'priority', 'start_on', 'due_on', 'custom', 'decisions',
        ];
        if (array_diff(array_keys($mapping), $allowed) !== [] || ! is_string($mapping['title'] ?? null)) {
            throw ValidationException::withMessages(['mapping' => 'Map a source heading to the required Task title field.']);
        }
        foreach ($mapping as $key => $heading) {
            if (in_array($key, ['custom', 'decisions'], true)) {
                if (! is_array($heading)) {
                    throw ValidationException::withMessages(['mapping' => 'Custom fields and decisions must be JSON objects.']);
                }

                continue;
            }
            if (! is_string($heading) || trim($heading) === '' || strlen($heading) > 191) {
                throw ValidationException::withMessages(['mapping' => 'Every mapped source heading must be non-empty and at most 191 bytes.']);
            }
            $mapping[$key] = trim($heading);
        }
        $mapping['custom'] ??= [];
        $mapping['decisions'] ??= [];

        return $mapping;
    }

    /** @return array{0:string,1:string,2:list<array<string,string>>} */
    private function uploadedRows(UploadedFile $file): array
    {
        $bytes = $file->get();
        if (! is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw ValidationException::withMessages(['file' => 'Choose a non-empty CSV or XLSX file no larger than 10 MiB.']);
        }
        [$format, $rows] = $this->parseUpload($bytes);

        return [$bytes, $format, $rows];
    }

    private function sourceMetadata(string $format, array $rows, ?array $mapping): array
    {
        $headings = $rows === [] ? [] : array_keys($rows[0]);
        $mapped = [];
        if ($mapping !== null) {
            foreach ($mapping as $key => $value) {
                if (is_string($value) && ! in_array($key, ['decisions'], true)) {
                    $mapped[] = $value;
                }
            }
            foreach (($mapping['custom'] ?? []) as $heading) {
                if (is_string($heading)) {
                    $mapped[] = $heading;
                }
            }
        }
        $mapped = array_unique($mapped);
        $sample = array_map(
            fn (array $row): array => array_map(
                fn ($value): string => mb_strcut((string) $value, 0, self::PREFLIGHT_SAMPLE_CELL_BYTES, 'UTF-8'),
                $row,
            ),
            array_slice($rows, 0, self::PREFLIGHT_SAMPLE_ROWS),
        );

        return [
            'format' => $format,
            'headings' => $headings,
            'sample' => $sample,
            'unsupported_fields' => collect($headings)
                ->reject(fn (string $heading) => in_array($heading, $mapped, true))
                ->map(fn (string $heading) => ['heading' => $heading, 'reason' => 'not_mapped'])
                ->values()->all(),
            'requires_mapping' => $mapping === null,
        ];
    }

    /** @return list<array<string,string>> */
    private function parseCsv(string $bytes): array
    {
        if (str_contains($bytes, "\0") || ! mb_check_encoding($bytes, 'UTF-8')) {
            throw ValidationException::withMessages(['file' => 'CSV files must be UTF-8 text without null bytes.']);
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        $headings = fgetcsv($stream, self::MAX_CELL_BYTES + 1, ',', '"', '');
        if (! is_array($headings) || $headings === [] || count($headings) > self::MAX_COLUMNS) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'CSV must have one header row and no more than 100 columns.']);
        }
        $headings = array_map(fn ($value) => trim((string) $value), $headings);
        if (in_array('', $headings, true)
            || count(array_unique($headings)) !== count($headings)
            || collect($headings)->contains(fn (string $heading) =>
                ! mb_check_encoding($heading, 'UTF-8') || strlen($heading) > self::MAX_HEADING_BYTES)) {
            fclose($stream);
            throw ValidationException::withMessages([
                'file' => 'CSV headings must be UTF-8, non-empty, unique, and no longer than 191 bytes.',
            ]);
        }
        $rows = [];
        while (($values = fgetcsv($stream, self::MAX_CELL_BYTES + 1, ',', '"', '')) !== false) {
            if (count($rows) >= self::MAX_ROWS || ((count($rows) + 1) * count($headings)) > self::MAX_CELLS) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'Imports are limited to 5,000 rows and 500,000 cells.']);
            }
            if (count($values) !== count($headings) || collect($values)->contains(fn ($value) => strlen((string) $value) > self::MAX_CELL_BYTES)) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => 'Every CSV row must match the headings and each cell is limited to 65,535 bytes.']);
            }
            if (collect($values)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }
            $rows[] = array_combine($headings, array_map(fn ($value) => (string) $value, $values));
        }
        fclose($stream);

        return $rows;
    }

    /** @return array{0:string,1:list<array<string,string>>} */
    private function parseUpload(string $bytes): array
    {
        return str_starts_with($bytes, "PK\x03\x04")
            ? ['xlsx', $this->parseXlsx($bytes)]
            : ['csv', $this->parseCsv($bytes)];
    }

    /** @return list<array<string,string>> */
    private function parseXlsx(string $bytes): array
    {
        $started = hrtime(true);
        $path = tempnam(sys_get_temp_dir(), 'ezbizi-task-import-');
        if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw ValidationException::withMessages(['file' => 'The workbook could not be staged for bounded inspection.']);
        }
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->numFiles > 256) {
                throw ValidationException::withMessages(['file' => 'The XLSX ZIP is invalid or contains more than 256 entries.']);
            }
            $expanded = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                $size = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                if ($name === '' || str_starts_with($name, '/') || preg_match('#(^|/)\.\.(/|$)#', $name)
                    || $size > 16 * 1024 * 1024 || ($size > 0 && $compressed === 0)
                    || ($compressed > 0 && ($size / $compressed) > 100)
                    || preg_match('#(^|/)(vbaProject\.bin|externalLinks|connections\.xml|embeddings|oleObjects?|activeX|ctrlProps|pivot|queryTables?)#i', $name)) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'The workbook contains an unsafe, encrypted, external, embedded, or over-expanded ZIP entry.']);
                }
                if ((int) ($stat['encryption_method'] ?? 0) !== 0) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'Encrypted workbooks are not supported.']);
                }
                $expanded += $size;
                if ($expanded > 64 * 1024 * 1024) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'The workbook expands beyond the 64 MiB safety limit.']);
                }
                if ($name === 'xl/sharedStrings.xml' && $size > 8 * 1024 * 1024) {
                    $zip->close();
                    throw ValidationException::withMessages(['file' => 'Workbook shared strings exceed the 8 MiB safety limit.']);
                }
                $this->assertXlsxDeadline($started);
            }

            $sheetPaths = $this->xlsxWorksheetPaths($zip);
            if ($sheetPaths === [] || count($sheetPaths) > 10) {
                throw ValidationException::withMessages(['file' => 'The workbook contains no readable worksheet.']);
            }
            $cellCount = 0;
            foreach ($sheetPaths as $sheetPath) {
                $xml = $zip->getFromName($sheetPath);
                if (! is_string($xml)) {
                    throw ValidationException::withMessages(['file' => 'The workbook references a missing worksheet.']);
                }
                $this->inspectWorksheetXml($xml, $cellCount, $started);
            }
            $this->inspectSharedStrings($zip, $started);
            $zip->close();

            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= 5001 && Coordinate::columnIndexFromString($columnAddress) <= 100;
                }
            });
            $spreadsheet = $reader->load($path);
            $this->assertXlsxDeadline($started);
            if ($spreadsheet->getSheetCount() > 10) {
                throw ValidationException::withMessages(['file' => 'Workbooks are limited to 10 sheets.']);
            }
            $loadedCells = 0;
            foreach ($spreadsheet->getAllSheets() as $loadedSheet) {
                foreach ($loadedSheet->getCoordinates() as $coordinate) {
                    [$column, $row] = Coordinate::coordinateFromString($coordinate);
                    $loadedCells++;
                    if ($row > self::MAX_ROWS + 1 || Coordinate::columnIndexFromString($column) > self::MAX_COLUMNS || $loadedCells > self::MAX_CELLS) {
                        throw ValidationException::withMessages(['file' => 'Workbook contents exceed 5,000 rows, 100 columns, or 500,000 cells.']);
                    }
                    $loadedCell = $loadedSheet->getCell($coordinate);
                    if ($loadedCell->getDataType() === DataType::TYPE_FORMULA || strlen((string) ($loadedCell->getValue() ?? '')) > self::MAX_CELL_BYTES) {
                        throw ValidationException::withMessages(['file' => 'Workbook formulas are not allowed and cells are limited to 65,535 bytes.']);
                    }
                }
                $this->assertXlsxDeadline($started);
            }
            $sheet = $spreadsheet->getSheet(0);
            $maxRow = $sheet->getHighestDataRow();
            $maxColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($maxRow - 1 > self::MAX_ROWS || $maxColumn > self::MAX_COLUMNS || ($maxRow * $maxColumn) > self::MAX_CELLS) {
                throw ValidationException::withMessages(['file' => 'Workbooks are limited to 5,000 rows, 100 columns, and 500,000 cells.']);
            }
            $matrix = [];
            for ($row = 1; $row <= $maxRow; $row++) {
                $values = [];
                for ($column = 1; $column <= $maxColumn; $column++) {
                    $cell = $sheet->getCell([$column, $row]);
                    if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                        throw ValidationException::withMessages(['file' => 'Workbook formulas are not allowed and are never evaluated.']);
                    }
                    $value = SpreadsheetDate::isDateTime($cell) && is_numeric($cell->getValue())
                        ? SpreadsheetDate::excelToDateTimeObject((float) $cell->getValue())->format('Y-m-d')
                        : (string) ($cell->getValue() ?? '');
                    if (strlen($value) > self::MAX_CELL_BYTES) {
                        throw ValidationException::withMessages(['file' => 'Workbook cells are limited to 65,535 bytes.']);
                    }
                    $values[] = $value;
                }
                $matrix[] = $values;
            }
            $spreadsheet->disconnectWorksheets();
            $headings = array_map('trim', array_shift($matrix) ?? []);
            if ($headings === [] || in_array('', $headings, true)
                || count(array_unique($headings)) !== count($headings)
                || collect($headings)->contains(fn (string $heading) =>
                    ! mb_check_encoding($heading, 'UTF-8') || strlen($heading) > self::MAX_HEADING_BYTES)) {
                throw ValidationException::withMessages([
                    'file' => 'Workbook headings must be UTF-8, non-empty, unique, and no longer than 191 bytes.',
                ]);
            }

            return collect($matrix)->reject(fn ($row) => collect($row)->every(fn ($value) => trim($value) === ''))
                ->map(fn ($row) => array_combine($headings, $row))->values()->all();
        } finally {
            @unlink($path);
        }
    }

    /** @return list<string> */
    private function xlsxWorksheetPaths(ZipArchive $zip): array
    {
        foreach (['xl/_rels/workbook.xml.rels', '[Content_Types].xml'] as $required) {
            if ($zip->locateName($required, ZipArchive::FL_NOCASE) === false) {
                throw ValidationException::withMessages(['file' => 'The workbook package is missing required relationship metadata.']);
            }
        }
        $types = $this->xmlDocument((string) $zip->getFromName('[Content_Types].xml'));
        $allowed = [];
        foreach ((new \DOMXPath($types))->query('//*[local-name()="Override"]') ?: [] as $node) {
            $part = ltrim(str_replace('\\', '/', $node->getAttribute('PartName')), '/');
            $contentType = strtolower($node->getAttribute('ContentType'));
            if (str_contains($contentType, 'macroenabled') || str_contains($contentType, 'external')
                || str_contains($contentType, 'connection') || str_contains($contentType, 'oleobject')) {
                throw ValidationException::withMessages(['file' => 'The workbook contains unsupported active or external content.']);
            }
            if (str_ends_with($contentType, 'worksheet+xml')) {
                $allowed[$part] = true;
            }
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (! str_ends_with(strtolower($name), '.rels')) {
                continue;
            }
            $relations = $this->xmlDocument((string) $zip->getFromIndex($index));
            foreach ((new \DOMXPath($relations))->query('//*[local-name()="Relationship"]') ?: [] as $relation) {
                if (strcasecmp($relation->getAttribute('TargetMode'), 'External') === 0) {
                    throw ValidationException::withMessages(['file' => 'External workbook relationships are not allowed.']);
                }
            }
        }

        $relationships = $this->xmlDocument((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
        $paths = [];
        foreach ((new \DOMXPath($relationships))->query('//*[local-name()="Relationship"]') ?: [] as $relation) {
            $type = strtolower($relation->getAttribute('Type'));
            if (! str_ends_with($type, '/worksheet')) {
                continue;
            }
            $path = $this->normalizeXlsxTarget('xl', $relation->getAttribute('Target'));
            if (! isset($allowed[$path]) || $zip->locateName($path) === false) {
                throw ValidationException::withMessages(['file' => 'The workbook references an invalid worksheet target.']);
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    private function normalizeXlsxTarget(string $base, string $target): string
    {
        $target = str_replace('\\', '/', trim($target));
        if ($target === '' || str_starts_with($target, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $target)) {
            throw ValidationException::withMessages(['file' => 'The workbook contains an unsafe relationship target.']);
        }
        if (in_array('..', explode('/', $target), true)) {
            throw ValidationException::withMessages(['file' => 'The workbook contains an unsafe relationship target.']);
        }
        $parts = explode('/', $base.'/'.$target);
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($normalized === []) {
                    throw ValidationException::withMessages(['file' => 'The workbook contains an unsafe relationship target.']);
                }
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return implode('/', $normalized);
    }

    private function xmlDocument(string $xml): \DOMDocument
    {
        if ($xml === '' || stripos($xml, '<!DOCTYPE') !== false) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid XML metadata.']);
        }
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid XML metadata.']);
        }

        return $document;
    }

    private function inspectWorksheetXml(string $xml, int &$cellCount, int $started): void
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid worksheet XML.']);
        }
        $reader = new \XMLReader;
        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid worksheet XML.']);
        }
        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->localName === 'f') {
                $reader->close();
                throw ValidationException::withMessages(['file' => 'Workbook formulas are not allowed and are never evaluated.']);
            }
            if ($reader->localName === 'c') {
                $cellCount++;
                $coordinate = strtoupper($reader->getAttribute('r') ?? '');
                if (! preg_match('/^([A-Z]+)([1-9][0-9]*)$/', $coordinate, $match)
                    || Coordinate::columnIndexFromString($match[1]) > self::MAX_COLUMNS
                    || (int) $match[2] > self::MAX_ROWS + 1 || $cellCount > self::MAX_CELLS) {
                    $reader->close();
                    throw ValidationException::withMessages(['file' => 'Workbook contents exceed 5,000 rows, 100 columns, or 500,000 cells.']);
                }
            }
            if (($reader->localName === 'v' || $reader->localName === 't') && strlen($reader->readString()) > self::MAX_CELL_BYTES) {
                $reader->close();
                throw ValidationException::withMessages(['file' => 'Workbook cells are limited to 65,535 bytes.']);
            }
            $this->assertXlsxDeadline($started);
        }
        $reader->close();
    }

    private function inspectSharedStrings(ZipArchive $zip, int $started): void
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return;
        }
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid shared-string XML.']);
        }
        $reader = new \XMLReader;
        if (! $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw ValidationException::withMessages(['file' => 'The workbook contains invalid shared-string XML.']);
        }
        $strings = 0;
        $bytes = 0;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $strings++;
                $bytes = 0;
                if ($strings > self::MAX_CELLS) {
                    $reader->close();
                    throw ValidationException::withMessages(['file' => 'Workbook shared strings exceed the safety limit.']);
                }
            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 't') {
                $bytes += strlen($reader->readString());
                if ($bytes > self::MAX_CELL_BYTES) {
                    $reader->close();
                    throw ValidationException::withMessages(['file' => 'Workbook cells are limited to 65,535 bytes.']);
                }
            }
            $this->assertXlsxDeadline($started);
        }
        $reader->close();
    }

    private function assertXlsxDeadline(int $started): void
    {
        if ((hrtime(true) - $started) > 10_000_000_000) {
            throw ValidationException::withMessages(['file' => 'Workbook inspection exceeded the 10-second safety limit.']);
        }
    }

    /** @return array{rows:list<array>,errors:list<array>} */
    private function prepareRows(TaskBoard $board, array $rows, array $mapping): array
    {
        $errors = [];
        $headings = $rows === [] ? [] : array_keys($rows[0]);
        foreach (array_filter($mapping, 'is_string') as $field => $heading) {
            if (! in_array($heading, $headings, true)) {
                $errors[] = ['row' => null, 'field' => $field, 'code' => 'missing_heading', 'message' => "The mapped heading '{$heading}' is missing."];
            }
        }
        if ($errors !== []) {
            return ['rows' => [], 'errors' => $errors];
        }
        $decisions = $mapping['decisions'];
        $statusIds = $this->decisionIds($board, TaskStatusOption::class, $decisions['statuses'] ?? [], 'status', $errors);
        $priorityIds = $this->decisionIds($board, TaskPriorityOption::class, $decisions['priorities'] ?? [], 'priority', $errors);
        $groupIds = $this->decisionIds($board, TaskGroup::class, $decisions['groups'] ?? [], 'group', $errors);
        $customIds = array_keys($mapping['custom']);
        $definitions = TaskColumnDefinition::query()->where('board_id', $board->id)->active()
            ->whereIn('id', $customIds)->get()->keyBy('id');
        if ($definitions->count() !== count($customIds)) {
            $errors[] = ['row' => null, 'field' => 'custom', 'code' => 'invalid_column', 'message' => 'One or more mapped custom columns are archived or belong to another board.'];
        }
        foreach ($mapping['custom'] as $definitionId => $heading) {
            if (! is_string($heading) || ! in_array($heading, $headings, true)) {
                $errors[] = ['row' => null, 'field' => 'custom', 'code' => 'missing_heading', 'message' => 'Every custom mapping must name a heading present in the source file.'];
            }
        }
        $owners = User::query()->whereIn('email', collect($rows)->map(fn ($row) => strtolower(trim(
            isset($mapping['owner_email']) ? ($row[$mapping['owner_email']] ?? '') : '',
        )))
            ->filter()->unique()->all())->get()->keyBy(fn (User $user) => strtolower($user->email));
        $sourceRows = [];
        $prepared = [];
        foreach ($rows as $offset => $row) {
            $number = $offset + 2;
            $title = trim($row[$mapping['title']] ?? '');
            if ($title === '' || strlen($title) > 191) {
                $errors[] = ['row' => $offset + 2, 'field' => 'title', 'code' => 'required', 'message' => 'Task title is required.'];
            }
            $source = trim($row[$mapping['source_row'] ?? ''] ?? '') ?: (string) $number;
            if (isset($sourceRows[$source])) {
                $errors[] = ['row' => $number, 'field' => 'source_row', 'code' => 'duplicate', 'message' => 'Source Row values must be unique.'];
            }
            $sourceRows[$source] = true;
            $parent = trim($row[$mapping['parent_source_row'] ?? ''] ?? '') ?: null;
            $ownerEmail = strtolower(trim($row[$mapping['owner_email'] ?? ''] ?? '')) ?: null;
            $owner = $ownerEmail === null ? null : $owners->get($ownerEmail);
            if ($ownerEmail !== null && ($owner === null || $this->participants->forTaskParticipation($owner, $board)->denied())) {
                $errors[] = ['row' => $number, 'field' => 'owner_email', 'code' => 'unavailable', 'message' => 'The mapped owner is not eligible for this board.'];
            }
            $statusLabel = trim($row[$mapping['status'] ?? ''] ?? '');
            $statusId = $statusIds[$statusLabel] ?? null;
            if ($statusId === null) {
                $this->decisionReconciliationError(
                    $errors, $number, 'status', 'decision_required', $statusLabel,
                    "Choose an active status for '{$statusLabel}'.",
                );
            }
            $groupLabel = trim($row[$mapping['group'] ?? ''] ?? '');
            $groupId = $parent === null ? ($groupIds[$groupLabel] ?? null) : null;
            if ($parent === null && $groupId === null) {
                $this->decisionReconciliationError(
                    $errors, $number, 'group', 'decision_required', $groupLabel,
                    "Choose an active group for '{$groupLabel}'.",
                );
            }
            $priorityLabel = trim($row[$mapping['priority'] ?? ''] ?? '');
            $priorityId = $priorityLabel === '' ? null : ($priorityIds[$priorityLabel] ?? null);
            if ($priorityLabel !== '' && $priorityId === null) {
                $this->decisionReconciliationError(
                    $errors, $number, 'priority', 'decision_required', $priorityLabel,
                    "Choose an active priority for '{$priorityLabel}'.",
                );
            }
            $start = $this->dateValue($row[$mapping['start_on'] ?? ''] ?? '', $number, 'start_on', $errors);
            $due = $this->dateValue($row[$mapping['due_on'] ?? ''] ?? '', $number, 'due_on', $errors);
            // Same rule TaskWriter::create() enforces at commit time
            // (whole-branch review finding 6) — checked here too, during
            // PREVIEW, so an inverted pair is reported with its row number
            // like every other row error, rather than previewing clean and
            // then rolling the whole import back with a bare
            // ValidationException the import surface has no row to show.
            // Legacy inverted rows are known to exist, so this is not a
            // theoretical case.
            if ($start !== null && $due !== null && $start > $due) {
                $errors[] = [
                    'row' => $number, 'field' => 'start_on', 'code' => 'start_after_due',
                    'message' => (string) Refusal::startAfterDue()->message(),
                ];
            }
            $custom = [];
            foreach ($mapping['custom'] as $definitionId => $heading) {
                $definition = $definitions->get($definitionId);
                if ($definition !== null && is_string($heading) && array_key_exists($heading, $row)) {
                    $custom[] = [$definition, $this->customValue(
                        $board, $definition, $row[$heading], $number, $decisions, $errors,
                    )];
                }
            }
            $prepared[] = [
                'source' => $source, 'parent_source' => $parent, 'custom' => $custom,
                'attributes' => [
                    'title' => $title,
                    'description' => ($value = trim($row[$mapping['description'] ?? ''] ?? '')) === '' ? null : $value,
                    'group_id' => $groupId,
                    'owner_user_id' => $owner?->id,
                    'status_option_id' => $statusId,
                    'priority_option_id' => $priorityId,
                    'start_on' => $start,
                    'due_on' => $due,
                ],
            ];
        }
        $bySource = collect($prepared)->keyBy('source');
        foreach ($prepared as $row) {
            if ($row['parent_source'] === null) {
                continue;
            }
            $parent = $bySource->get($row['parent_source']);
            if ($parent === null) {
                $errors[] = ['row' => null, 'field' => 'parent_source_row', 'code' => 'missing_parent', 'message' => "Parent Source Row '{$row['parent_source']}' is missing."];
            } elseif ($parent['source'] === $row['source'] || $parent['parent_source'] !== null) {
                $errors[] = ['row' => null, 'field' => 'parent_source_row', 'code' => 'hierarchy', 'message' => 'Imports support one level of subtasks and no cycles.'];
            }
        }
        usort($prepared, fn ($a, $b) => ($a['parent_source'] === null ? 0 : 1) <=> ($b['parent_source'] === null ? 0 : 1));

        return ['rows' => $prepared, 'errors' => $errors];
    }

    private function decisionIds(TaskBoard $board, string $model, array $decisions, string $field, array &$errors): array
    {
        $ids = array_values(array_filter($decisions, 'is_string'));
        $valid = $model::query()->where('board_id', $board->id)->active()->whereIn('id', $ids)->pluck('id')->all();
        if (count($valid) !== count(array_unique($ids))) {
            $errors[] = ['row' => null, 'field' => $field, 'code' => 'invalid_decision', 'message' => "One or more {$field} decisions are archived or belong to another board."];
        }

        return array_filter($decisions, fn ($id) => in_array($id, $valid, true));
    }

    private function dateValue(string $value, int $row, string $field, array &$errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $errors[] = ['row' => $row, 'field' => $field, 'code' => 'invalid_date', 'message' => 'Dates must use a real YYYY-MM-DD value.'];

            return null;
        }

        return $value;
    }

    private function customValue(
        TaskBoard $board,
        TaskColumnDefinition $definition,
        string $raw,
        int $row,
        array $decisions,
        array &$errors,
    ): mixed {
        $value = trim($raw);
        if ($value === '') {
            return in_array($definition->type, [ColumnType::People, ColumnType::Label, ColumnType::File], true) ? [] : null;
        }

        return match ($definition->type) {
            ColumnType::Text => strlen($raw) <= self::MAX_CELL_BYTES ? $raw
                : $this->reconciliationError($errors, $row, $definition->id, 'invalid_text', 'Text values are limited to 65,535 bytes.'),
            ColumnType::Number => ($decimal = $this->canonicalDecimal($value)) === null
                ? $this->reconciliationError($errors, $row, $definition->id, 'invalid_number', 'Number values must contain at most 14 integer and 6 decimal digits.')
                : new ImportedTaskDecimal($decimal),
            ColumnType::Checkbox => match (strtolower($value)) {
                'true', 'yes', '1' => true, 'false', 'no', '0' => false,
                default => $this->reconciliationError($errors, $row, $definition->id, 'invalid_checkbox', 'Checkbox values must be true/false, yes/no, or 1/0.'),
            },
            ColumnType::Date => $this->dateValue($value, $row, $definition->id, $errors),
            ColumnType::People => $this->customPeople($board, $value, $row, $definition->id, $errors),
            ColumnType::Label => $this->customLabels($definition, $value, $row, $decisions, $errors),
            ColumnType::Link => $this->customLink($value, $row, $definition->id, $errors),
            ColumnType::File => ($files = $this->customFiles($board, $value, $row, $definition->id, $errors)) === []
                ? [] : new ImportedTaskFiles($files),
        };
    }

    private function canonicalDecimal(string $value): ?string
    {
        if (! preg_match('/^([+-]?)(\d{1,14})(?:\.(\d{1,6}))?$/', $value, $matches)) {
            return null;
        }
        $integer = ltrim($matches[2], '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = str_pad($matches[3] ?? '', 6, '0');
        $sign = $matches[1] === '-' && ($integer !== '0' || trim($fraction, '0') !== '') ? '-' : '';

        return $sign.$integer.'.'.$fraction;
    }

    private function customPeople(TaskBoard $board, string $value, int $row, string $field, array &$errors): array
    {
        $emails = $this->listValues($value);
        $users = User::query()->whereIn('email', $emails)->get()->keyBy(fn (User $user) => strtolower($user->email));
        $ids = [];
        foreach ($emails as $email) {
            $user = $users->get(strtolower($email));
            if ($user === null || $this->participants->forTaskParticipation($user, $board)->denied()) {
                $this->reconciliationError($errors, $row, $field, 'person_unavailable', "People value '{$email}' is unavailable for this board.");

                continue;
            }
            $ids[] = $user->id;
        }

        return array_values(array_unique($ids));
    }

    private function customLabels(TaskColumnDefinition $definition, string $value, int $row, array $decisions, array &$errors): array
    {
        $choices = $decisions['column_options'][$definition->id] ?? [];
        $ids = [];
        foreach ($this->listValues($value) as $label) {
            $id = $choices[$label] ?? null;
            $valid = is_string($id) && $definition->options()->active()->whereKey($id)->exists();
            if (! $valid) {
                $this->decisionReconciliationError(
                    $errors, $row, $definition->id, 'label_decision_required', $label,
                    "Choose an active option for '{$label}'.",
                );

                continue;
            }
            $ids[] = $id;
        }

        return array_values(array_unique($ids));
    }

    private function customLink(string $value, int $row, string $field, array &$errors): ?array
    {
        $parts = explode('|', $value, 2);
        $url = trim($parts[0]);
        $parsed = preg_match('/[\x00-\x20\x7f]/', $url) ? false : parse_url($url);
        if (! is_array($parsed) || strtolower((string) ($parsed['scheme'] ?? '')) !== 'https' || ($parsed['host'] ?? '') === '') {
            return $this->reconciliationError($errors, $row, $field, 'invalid_link', 'Link values must be absolute HTTPS URLs, optionally followed by |label.');
        }

        return ['url' => $url, 'label' => isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null];
    }

    private function customFiles(TaskBoard $board, string $value, int $row, string $field, array &$errors): array
    {
        $ids = $this->listValues($value);
        if (TaskUpdateAttachmentCandidate::query()->whereIn('attachment_id', $ids)->exists()) {
            $this->reconciliationError($errors, $row, $field, 'file_unavailable', 'File values must be committed attachments from this board.');

            return [];
        }
        $attachments = Attachment::query()->whereIn('id', $ids)->get();
        $taskIds = $attachments->where('attachable_type', (new Task)->getMorphClass())->pluck('attachable_id')->all();
        $updateIds = $attachments->where('attachable_type', (new TaskUpdate)->getMorphClass())->pluck('attachable_id')->all();
        $valid = Task::query()->where('board_id', $board->id)->whereIn('id', $taskIds)->count()
            + TaskUpdate::query()->whereIn('id', $updateIds)->whereHas('task', fn ($query) => $query->where('board_id', $board->id))->count();
        if ($attachments->count() !== count($ids) || $valid !== count($ids)) {
            $this->reconciliationError($errors, $row, $field, 'file_unavailable', 'File values must be committed attachments from this board.');

            return [];
        }

        return $ids;
    }

    private function listValues(string $value): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($part) => trim($part), preg_split('/[;,]/', $value) ?: []))));
    }

    private function reconciliationError(array &$errors, int $row, string $field, string $code, string $message): null
    {
        $errors[] = compact('row', 'field', 'code', 'message');

        return null;
    }

    private function decisionReconciliationError(
        array &$errors,
        int $row,
        string $field,
        string $code,
        string $sourceValue,
        string $message,
    ): null {
        if (! mb_check_encoding($sourceValue, 'UTF-8')
            || strlen($sourceValue) > self::MAX_DECISION_SOURCE_BYTES) {
            $errors[] = [
                'row' => $row,
                'field' => $field,
                'code' => 'source_value_too_long',
                'message' => 'A decision source value must be valid UTF-8 and no longer than 191 bytes.',
            ];

            return null;
        }
        $errors[] = compact('row', 'field', 'code', 'message') + ['source_value' => $sourceValue];

        return null;
    }
}
