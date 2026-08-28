<?php

namespace App\Support\Tasks;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskBoard;
use App\Models\TaskBoardMember;
use App\Models\TaskColumnDefinition;
use App\Models\TaskColumnFile;
use App\Models\TaskColumnLabel;
use App\Models\TaskColumnOption;
use App\Models\TaskColumnPerson;
use App\Models\TaskColumnValue;
use App\Models\TaskUpdate;
use App\Models\TaskUpdateAttachmentCandidate;
use App\Models\User;
use App\Support\Access\Refusal;
use App\Support\Audit\AuditLogger;
use App\Support\Scoping\ViewScope;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaskColumnValueStore
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskParticipantEligibility $participants,
        private readonly AuditLogger $audit,
    ) {}

    public function replace(
        User $actor,
        Task $task,
        TaskColumnDefinition $definition,
        ViewScope $scope,
        int $expectedVersion,
        mixed $value,
    ): Task {
        return DB::transaction(function () use ($actor, $task, $definition, $scope, $expectedVersion, $value): Task {
            $board = TaskBoard::query()->lockForUpdate()->findOrFail($task->board_id);
            if ($board->visibility === BoardVisibility::Private) {
                TaskBoardMember::query()->where('board_id', $board->id)
                    ->where('user_id', $actor->id)->lockForUpdate()->first();
            }
            $this->access->viewDecision($actor, $board, $scope)->authorize();
            if (! $actor->hasPermission('tasks.edit')) {
                Refusal::permission('change task fields')->authorize();
            }
            if ($actor->organization?->isReadOnly()) {
                throw ValidationException::withMessages([
                    'organization' => 'This organization is read-only because its subscription has ended.',
                ]);
            }

            $locked = Task::query()->where('board_id', $board->id)->active()
                ->lockForUpdate()->findOrFail($task->id);
            $column = TaskColumnDefinition::query()->where('board_id', $board->id)->active()
                ->lockForUpdate()->findOrFail($definition->id);
            if ((int) $locked->version !== $expectedVersion) {
                throw new TaskConflict((int) $locked->version, [
                    'id' => $locked->id,
                    'version' => (int) $locked->version,
                    'column_definition_id' => $column->id,
                    'value' => $this->value($locked, $column),
                ], ['column:'.$column->id]);
            }

            $normalized = $this->normalize($board, $locked, $column, $value);
            $before = $this->value($locked, $column);
            if ($before === $normalized) {
                return $locked;
            }

            $this->clearRows($locked, $column);
            $this->writeRows($locked, $column, $normalized);
            $locked->forceFill([
                'version' => (int) $locked->version + 1,
                'detail_version' => (int) $locked->detail_version + 1,
                'updated_by' => $actor->id,
            ])->save();

            $scopeData = TaskPlace::auditScope($board);
            $audit = $this->audit->log('task.column_changed', target: $locked, changes: [
                'before' => ['column_definition_id' => $column->id, 'value' => $before],
                'after' => ['column_definition_id' => $column->id, 'value' => $normalized],
            ], metadata: [
                'board_id' => $board->id,
                'task_id' => $locked->id,
                'column_definition_id' => $column->id,
                'column_type' => $column->type->value,
            ], actor: $actor, scopeType: $scopeData['type'], scopeId: $scopeData['id']);
            TaskActivity::create([
                'board_id' => $board->id,
                'task_id' => $locked->id,
                'actor_user_id' => $actor->id,
                'kind' => 'task.column_changed',
                'summary' => 'Custom task field changed',
                'data' => [
                    'column_definition_id' => $column->id,
                    'column_type' => $column->type->value,
                    'before' => $before,
                    'after' => $normalized,
                    'audit_event_id' => $audit?->id,
                ],
            ]);

            return $locked->fresh();
        });
    }

    public function value(Task $task, TaskColumnDefinition $definition): mixed
    {
        return match ($definition->type) {
            ColumnType::Text => TaskColumnValue::query()->where('task_id', $task->id)
                ->where('column_definition_id', $definition->id)->value('text_value'),
            ColumnType::Number => $this->decimalValue($task, $definition),
            ColumnType::Checkbox => $this->booleanValue($task, $definition),
            ColumnType::Date => $this->dateValue($task, $definition),
            ColumnType::Link => $this->linkValue($task, $definition),
            ColumnType::People => TaskColumnPerson::query()->where('task_id', $task->id)
                ->where('column_definition_id', $definition->id)->orderBy('user_id')->pluck('user_id')->all(),
            ColumnType::Label => TaskColumnLabel::query()->where('task_id', $task->id)
                ->where('column_definition_id', $definition->id)->orderBy('column_option_id')->pluck('column_option_id')->all(),
            ColumnType::File => TaskColumnFile::query()->where('task_id', $task->id)
                ->where('column_definition_id', $definition->id)->orderBy('attachment_id')->pluck('attachment_id')->all(),
        };
    }

    private function normalize(TaskBoard $board, Task $task, TaskColumnDefinition $definition, mixed $value): mixed
    {
        if ($value === null) {
            return in_array($definition->type, [ColumnType::People, ColumnType::Label, ColumnType::File], true)
                ? []
                : null;
        }

        return match ($definition->type) {
            ColumnType::Text => $this->text($value),
            ColumnType::Number => $this->number($value),
            ColumnType::Checkbox => is_bool($value) ? $value : $this->invalid('value', 'A Checkbox value must be true or false.'),
            ColumnType::Date => $this->date($value),
            ColumnType::People => $this->people($board, $value),
            ColumnType::Label => $this->labels($board, $definition, $value),
            ColumnType::Link => $this->link($value),
            ColumnType::File => $this->files($task, $value),
        };
    }

    private function text(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 65535) {
            return $this->invalid('value', 'A Text value must be a string of at most 65,535 bytes.');
        }

        return $value;
    }

    private function number(mixed $value): string
    {
        if ($value instanceof ImportedTaskDecimal) {
            return $value->value;
        }
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            return $this->invalid('value', 'A Number value must be a finite JSON number.');
        }
        $normalized = number_format((float) $value, 6, '.', '');
        if (strlen(ltrim(strstr($normalized, '.', true), '-')) > 14) {
            return $this->invalid('value', 'That Number is outside the supported range.');
        }

        return $normalized;
    }

    private function date(mixed $value): string
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return $this->invalid('value', 'A Date value must use YYYY-MM-DD.');
        }

        return $value;
    }

    private function people(TaskBoard $board, mixed $value): array
    {
        $ids = $this->idList($value, 'People');
        $users = User::query()->whereIn('id', $ids)->get();
        if ($users->count() !== count($ids)) {
            return $this->invalid('value', 'One or more People values are unavailable.');
        }
        foreach ($users as $user) {
            if ($this->participants->forTaskParticipation($user, $board)->denied()) {
                return $this->invalid('value', 'One or more People values cannot participate on this board.');
            }
        }
        sort($ids);

        return $ids;
    }

    private function labels(TaskBoard $board, TaskColumnDefinition $definition, mixed $value): array
    {
        $ids = $this->idList($value, 'Label');
        $count = TaskColumnOption::query()->where('board_id', $board->id)
            ->where('column_definition_id', $definition->id)->active()->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            return $this->invalid('value', 'One or more Label values do not belong to this active column.');
        }
        sort($ids);

        return $ids;
    }

    private function link(mixed $value): array
    {
        if (! is_array($value) || array_diff(array_keys($value), ['url', 'label']) !== []
            || ! isset($value['url']) || ! is_string($value['url'])
            || (isset($value['label']) && ! is_string($value['label']))) {
            return $this->invalid('value', 'A Link value requires an HTTPS url and optional text label.');
        }
        $candidate = trim($value['url']);
        $parts = preg_match('/[\x00-\x20\x7f]/', $candidate) ? false : parse_url($candidate);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return $this->invalid('value', 'A Link value must be an absolute HTTPS URL without credentials.');
        }
        $url = 'https://'.strtolower($parts['host']);
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            $url .= ':'.(int) $parts['port'];
        }
        $url .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $url .= '?'.$parts['query'];
        }
        if (isset($parts['fragment'])) {
            $url .= '#'.$parts['fragment'];
        }
        if (strlen($url) > 2048 || strlen((string) ($value['label'] ?? '')) > 191) {
            return $this->invalid('value', 'That Link URL or label is too long.');
        }

        return ['url' => $url, 'label' => isset($value['label']) && $value['label'] !== '' ? $value['label'] : null];
    }

    private function files(Task $task, mixed $value): array
    {
        $imported = $value instanceof ImportedTaskFiles;
        if ($imported) {
            $value = $value->ids;
        }
        $ids = $this->idList($value, 'File');
        if ($ids === []) {
            return [];
        }

        $candidateIds = TaskUpdateAttachmentCandidate::query()
            ->whereIn('attachment_id', $ids)->pluck('attachment_id')->all();
        if ($candidateIds !== []) {
            return $this->invalid('value', 'One or more File values are not committed to this task.');
        }

        $attachments = Attachment::query()->whereIn('id', $ids)->get()->keyBy('id');
        if ($attachments->count() !== count($ids)) {
            return $this->invalid('value', 'One or more File values are unavailable.');
        }

        $updateIds = $attachments->where('attachable_type', 'task_updates')
            ->pluck('attachable_id')->unique()->values()->all();
        $validUpdateIds = TaskUpdate::query()
            ->when($imported,
                fn ($query) => $query->whereHas('task', fn ($taskQuery) => $taskQuery->where('board_id', $task->board_id)),
                fn ($query) => $query->where('task_id', $task->id))
            ->whereIn('id', $updateIds)->pluck('id')->all();
        $validTaskIds = $imported ? Task::query()->where('board_id', $task->board_id)
            ->whereIn('id', $attachments->where('attachable_type', 'tasks')->pluck('attachable_id')->all())
            ->pluck('id')->all() : [];
        foreach ($attachments as $attachment) {
            $taskBound = $attachment->attachable_type === 'tasks'
                && ($attachment->attachable_id === $task->id || ($imported && in_array($attachment->attachable_id, $validTaskIds, true)));
            $updateBound = $attachment->attachable_type === 'task_updates'
                && in_array($attachment->attachable_id, $validUpdateIds, true);
            if (! $taskBound && ! $updateBound) {
                return $this->invalid('value', 'One or more File values are not committed to this task.');
            }
        }
        sort($ids);

        return $ids;
    }

    private function idList(mixed $value, string $type): array
    {
        if (! is_array($value) || count($value) > 100 || array_values($value) !== $value
            || collect($value)->contains(fn ($id) => ! is_string($id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27}$/i', $id))
            || count(array_unique($value)) !== count($value)) {
            return $this->invalid('value', "A {$type} value must be a unique list of at most 100 IDs.");
        }

        return $value;
    }

    private function clearRows(Task $task, TaskColumnDefinition $definition): void
    {
        foreach ([TaskColumnValue::class, TaskColumnPerson::class, TaskColumnLabel::class, TaskColumnFile::class] as $model) {
            $model::query()->where('task_id', $task->id)
                ->where('column_definition_id', $definition->id)->delete();
        }
    }

    private function writeRows(Task $task, TaskColumnDefinition $definition, mixed $value): void
    {
        if ($value === null) {
            return;
        }
        if (in_array($definition->type, [ColumnType::People, ColumnType::Label, ColumnType::File], true)) {
            $model = match ($definition->type) {
                ColumnType::People => TaskColumnPerson::class,
                ColumnType::Label => TaskColumnLabel::class,
                ColumnType::File => TaskColumnFile::class,
                default => throw new \LogicException('Unsupported relationship column.'),
            };
            $key = match ($definition->type) {
                ColumnType::People => 'user_id',
                ColumnType::Label => 'column_option_id',
                ColumnType::File => 'attachment_id',
                default => '',
            };
            foreach ($value as $id) {
                $row = new $model;
                $row->forceFill([
                    'board_id' => $task->board_id,
                    'task_id' => $task->id,
                    'column_definition_id' => $definition->id,
                    $key => $id,
                ])->save();
            }

            return;
        }

        $row = new TaskColumnValue;
        $attributes = match ($definition->type) {
            ColumnType::Text => ['text_value' => $value],
            ColumnType::Number => ['number_value' => $value],
            ColumnType::Checkbox => ['boolean_value' => $value],
            ColumnType::Date => ['date_value' => $value],
            ColumnType::Link => ['url_value' => $value['url'], 'link_label_value' => $value['label']],
            default => throw new \LogicException('Unsupported scalar column.'),
        };
        $row->forceFill([
            'board_id' => $task->board_id,
            'task_id' => $task->id,
            'column_definition_id' => $definition->id,
            ...$attributes,
        ])->save();
    }

    private function decimalValue(Task $task, TaskColumnDefinition $definition): ?string
    {
        return TaskColumnValue::query()->where('task_id', $task->id)
            ->where('column_definition_id', $definition->id)->value('number_value');
    }

    private function booleanValue(Task $task, TaskColumnDefinition $definition): ?bool
    {
        $row = TaskColumnValue::query()->where('task_id', $task->id)
            ->where('column_definition_id', $definition->id)->first();

        return $row?->boolean_value;
    }

    private function dateValue(Task $task, TaskColumnDefinition $definition): ?string
    {
        $row = TaskColumnValue::query()->where('task_id', $task->id)
            ->where('column_definition_id', $definition->id)->first();

        return $row?->date_value?->format('Y-m-d');
    }

    private function linkValue(Task $task, TaskColumnDefinition $definition): ?array
    {
        $row = TaskColumnValue::query()->where('task_id', $task->id)
            ->where('column_definition_id', $definition->id)->first();

        return $row === null ? null : ['url' => $row->url_value, 'label' => $row->link_label_value];
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
