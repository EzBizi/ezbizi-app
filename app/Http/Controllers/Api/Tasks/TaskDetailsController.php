<?php

namespace App\Http\Controllers\Api\Tasks;

use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Scoping\ViewScope;
use App\Support\Tasks\TaskBoardAccess;
use App\Support\Tasks\TaskRecordLinkRegistry;
use App\Support\Tasks\TaskParticipantEligibility;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class TaskDetailsController extends TaskApiController
{
    public function __construct(
        private readonly TaskBoardAccess $access,
        private readonly TaskRecordLinkRegistry $recordLinks,
        private readonly TaskParticipantEligibility $participants,
    ) {}

    public function show(Request $request, TaskBoard $board, string $taskId): Response
    {
        $scope = ViewScope::forUser($request->user());
        $this->access->viewDecision($request->user(), $board, $scope)->authorize();
        $visibleBoards = $this->access->constrainVisible(TaskBoard::query()->active(), $request->user(), $scope)
            ->select('task_boards.id');
        $task = Task::query()->where('board_id', $board->id)->whereIn('board_id', $visibleBoards)->active()->with([
            'board', 'group', 'parent.group', 'owner', 'statusOption', 'priorityOption',
            'collaborators.user', 'columnValues', 'columnPeople', 'columnLabels', 'columnFiles',
            'updates' => fn ($query) => $query->active()->oldest('created_at'),
            'updates.author', 'updates.mentions.user', 'updates.versions.editor',
            'updates.attachments.uploadedBy',
            'recordLinks',
            'watchPreferences' => fn ($query) => $query->where('user_id', $request->user()->id),
            'activities' => fn ($query) => $query->latest('created_at')->limit(200),
            'subtasks' => fn ($query) => $query->active()->orderBy('position'),
        ])->findOrFail($taskId);
        $data = $this->presentTask($task);
        $data['custom_values'] = $this->customValues($task);
        $canComment = $request->user()->can('comment', $task);
        $data['updates'] = $task->updates->map(fn ($update) => [
            'id' => $update->id,
            'author_user_id' => $update->author_user_id,
            'body' => $update->body,
            'body_plain' => $update->body_plain,
            'version' => (int) $update->version,
            'mention_user_ids' => $update->mentions->pluck('user_id')->values()->all(),
            'actions' => $canComment && $update->author_user_id === $request->user()->id
                ? ['edit', 'archive']
                : [],
            'attachments' => $update->attachments->map(fn ($attachment) => $attachment->present())->values(),
            'versions' => $update->versions->map(fn ($version) => [
                'version' => (int) $version->version,
                'body' => $version->body,
                'body_plain' => $version->body_plain,
                'edited_by' => $version->edited_by,
                'created_at' => $version->created_at?->toIso8601String(),
            ])->values(),
            'created_at' => $update->created_at?->toIso8601String(),
            'updated_at' => $update->updated_at?->toIso8601String(),
        ])->values();
        $preference = $task->watchPreferences->first()?->preference;
        $defaultWatched = $task->owner_user_id === $request->user()->id
            || $task->collaborators->contains('user_id', $request->user()->id)
            || $task->updates->contains('author_user_id', $request->user()->id)
            || $task->updates->contains(fn ($update) => $update->mentions->contains('user_id', $request->user()->id));
        $data['watch'] = [
            'preference' => $preference,
            'mandatory' => $task->owner_user_id === $request->user()->id,
            'watched' => $task->owner_user_id === $request->user()->id
                || ($preference !== 'muted' && ($preference === 'watched' || $defaultWatched)),
        ];
        $data['actions'] = array_values(array_filter([
            $canComment ? 'create_update' : null,
            $request->user()->can('update', $task) ? 'manage_collaborators' : null,
            $task->owner_user_id !== $request->user()->id
                && $this->participants->forTaskParticipation($request->user(), $task->board)->allowed()
                    ? 'watch'
                    : null,
            $task->owner_user_id !== $request->user()->id ? 'mute' : null,
            $task->owner_user_id !== $request->user()->id && $preference !== null ? 'clear_watch' : null,
        ]));
        $data['record_links'] = $task->recordLinks->map(function ($link) use ($request, $board, $scope): array {
            $definition = $this->recordLinks->get($link->record_type);
            $record = $definition->resolve($request->user(), $board, $scope, $link->record_id);

            return $record === null
                ? ['link_id' => $link->id, 'available' => false, 'type' => $definition->label()]
                : ['link_id' => $link->id, 'available' => true, ...$definition->present($record)];
        })->values();
        $data['activities'] = $task->activities->map(fn ($activity) => [
            'id' => $activity->id,
            'actor_user_id' => $activity->actor_user_id,
            'kind' => $activity->kind,
            'summary' => $activity->summary,
            'data' => $activity->data,
            'created_at' => $activity->created_at?->toIso8601String(),
        ])->values();

        $etag = hash('sha256', json_encode([
            $task->id, (int) $task->version, (int) $task->detail_version,
            $task->subtasks->map(fn (Task $subtask) => [
                $subtask->id, (int) $subtask->version, (int) $subtask->detail_version,
            ])->values()->all(),
            $data['actions'],
            $data['record_links']->all(),
        ], JSON_THROW_ON_ERROR));

        $response = response()->json(['data' => $data])->setEtag($etag);
        $response->isNotModified($request);

        return $response;
    }

    private function customValues(Task $task): array
    {
        $custom = [];
        foreach ($task->columnValues as $value) {
            $custom[$value->column_definition_id] = $value->text_value
                ?? $value->number_value
                ?? $value->boolean_value
                ?? $value->date_value?->format('Y-m-d')
                ?? ($value->url_value === null ? null : ['url' => $value->url_value, 'label' => $value->link_label_value]);
        }
        foreach ($task->columnPeople->groupBy('column_definition_id') as $id => $values) {
            $custom[$id] = $values->pluck('user_id')->sort()->values()->all();
        }
        foreach ($task->columnLabels->groupBy('column_definition_id') as $id => $values) {
            $custom[$id] = $values->pluck('column_option_id')->sort()->values()->all();
        }
        foreach ($task->columnFiles->groupBy('column_definition_id') as $id => $values) {
            $custom[$id] = $values->pluck('attachment_id')->sort()->values()->all();
        }

        return $custom;
    }
}
