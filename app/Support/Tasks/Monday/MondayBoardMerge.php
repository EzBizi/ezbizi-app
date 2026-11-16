<?php

namespace App\Support\Tasks\Monday;

use App\Models\Automation;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskBoardFolder;
use App\Models\TaskBoardMember;
use App\Models\TaskBoardView;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\TaskStatus;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An imported Monday branch board folded into its EzBizi branch board (Jose,
 * 2026-11-16: "those should have been moved towards their franchise board
 * instead. like both should just be one").
 *
 * The branch board keeps its own tasks, groups, statuses, columns and
 * settings; where the two disagree (a status of the same name with another
 * done-ness, a column of the same name and another type) the branch board's
 * wins and the conflict is listed. Monday's groups, statuses, columns and
 * label options it lacks are added; the Monday board's tasks (with their
 * subtasks, updates, files, values and Activity), saved views, members and
 * Task Board rules move over, every id they name re-pointed. The branch board
 * then answers for the Monday board (external_ref), so the sync, the webhooks
 * and the outbox follow it, and the emptied Monday board is deleted — with its
 * folder when that is left empty.
 */
final class MondayBoardMerge
{
    /** @var list<string> */
    public array $conflicts = [];

    /** @var array<string, int> */
    public array $counts = [];

    public function __construct(private readonly TaskBoards $boards) {}

    /** @return array{counts: array<string, int>, conflicts: list<string>, into: TaskBoard} */
    public function merge(TaskBoard $from, TaskBoard $into, User $by, bool $dry = false): array
    {
        $this->conflicts = [];
        $this->counts = ['tasks' => 0, 'subtasks' => 0, 'groups added' => 0, 'groups reused' => 0, 'statuses added' => 0,
            'statuses reused' => 0, 'columns added' => 0, 'columns reused' => 0, 'options added' => 0, 'values moved' => 0,
            'views moved' => 0, 'members added' => 0, 'rules re-pointed' => 0, 'folders removed' => 0];
        if ($into->external_ref !== null && $into->external_ref !== $from->external_ref) {
            throw new \RuntimeException("{$into->name} already answers for {$into->external_ref}.");
        }

        DB::beginTransaction();
        try {
            $groups = $this->groups($from, $into);
            $statuses = $this->statuses($from, $into);
            [$columns, $options] = $this->columns($from, $into);
            $this->tasks($from, $into, $groups, $statuses, $columns, $options);
            $this->views($from, $into);
            $this->members($from, $into);
            $this->rules($from, $into, $groups, $statuses, $columns, $by);

            $ref = $from->external_ref;
            $folder = $from->folder_id;
            $from->forceFill(['external_ref' => null])->saveQuietly();
            $into->forceFill(['external_ref' => $ref])->saveQuietly();
            $from->delete();
            if ($folder !== null && ! TaskBoard::query()->where('folder_id', $folder)->exists()
                && ! TaskBoardFolder::query()->where('parent_id', $folder)->exists()) {
                TaskBoardFolder::query()->whereKey($folder)->delete();
                $this->counts['folders removed']++;
            }
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return ['counts' => $this->counts, 'conflicts' => $this->conflicts, 'into' => $into];
    }

    /** @return array<string, string> from group id => into group id */
    private function groups(TaskBoard $from, TaskBoard $into): array
    {
        $map = [];
        $have = TaskGroup::query()->where('board_id', $into->id)->get();
        $next = (int) $have->max('position') + 1;
        foreach (TaskGroup::query()->where('board_id', $from->id)->orderBy('position')->get() as $g) {
            $same = $have->first(fn ($h) => mb_strtolower(trim($h->name)) === mb_strtolower(trim($g->name)));
            if ($same === null) {
                $same = TaskGroup::query()->create(['organization_id' => $into->organization_id, 'board_id' => $into->id,
                    'name' => $g->name, 'colour' => $g->colour, 'position' => $next++]);
                $have->push($same);
                $this->counts['groups added']++;
            } else {
                $this->counts['groups reused']++;
            }
            $map[$g->id] = $same->id;
        }

        return $map;
    }

    /** @return array<string, TaskStatus> from status id => into status */
    private function statuses(TaskBoard $from, TaskBoard $into): array
    {
        $map = [];
        $have = TaskStatus::query()->where('board_id', $into->id)->get();
        $next = (int) $have->max('position') + 1;
        foreach (TaskStatus::query()->where('board_id', $from->id)->orderBy('position')->get() as $s) {
            $same = $have->first(fn ($h) => mb_strtolower(trim($h->name)) === mb_strtolower(trim($s->name)));
            if ($same === null) {
                $same = TaskStatus::query()->create(['organization_id' => $into->organization_id, 'board_id' => $into->id,
                    'name' => $s->name, 'colour' => $s->colour, 'is_done' => $s->is_done, 'position' => $next++]);
                $have->push($same);
                $this->counts['statuses added']++;
            } else {
                if ((bool) $same->is_done !== (bool) $s->is_done) {
                    $this->conflicts[] = "status \"{$s->name}\": ".($same->is_done ? 'done' : 'not done').' on the branch board, '
                        .($s->is_done ? 'done' : 'not done').' on Monday\'s; the branch board\'s kept';
                }
                $this->counts['statuses reused']++;
            }
            $map[$s->id] = $same;
        }

        return $map;
    }

    /**
     * @return array{0: array<string, TaskColumn>, 1: array<string, array<string, string>>}
     *                                                                                      from column id => into column; from column id => [from option id => into option id]
     */
    private function columns(TaskBoard $from, TaskBoard $into): array
    {
        $map = [];
        $optionMap = [];
        $have = TaskColumn::query()->where('board_id', $into->id)->get();
        $next = (int) $have->max('position') + 1;
        foreach (TaskColumn::query()->where('board_id', $from->id)->orderBy('position')->get() as $c) {
            $named = $have->first(fn ($h) => mb_strtolower(trim($h->name)) === mb_strtolower(trim($c->name)));
            if ($named !== null && $named->type === $c->type) {
                $target = $named;
                $this->counts['columns reused']++;
            } else {
                $name = $c->name;
                if ($named !== null) {
                    $name = mb_substr($c->name, 0, 240).' (Monday)';
                    $this->conflicts[] = "column \"{$c->name}\": {$named->type} on the branch board, {$c->type} on Monday's; Monday's added as \"{$name}\"";
                }
                $target = TaskColumn::query()->create(['organization_id' => $into->organization_id, 'board_id' => $into->id,
                    'name' => $name, 'type' => $c->type, 'settings' => $c->settings, 'hidden' => $c->hidden, 'position' => $next++]);
                $have->push($target);
                $this->counts['columns added']++;
            }
            $map[$c->id] = $target;
            if (in_array($c->type, TaskColumn::WITH_OPTIONS, true)) {
                $optionMap[$c->id] = $this->options($c, $target);
            }
        }

        return [$map, $optionMap];
    }

    /** Monday's labels on the branch board's column: matched by name, added when missing. @return array<string, string> */
    private function options(TaskColumn $from, TaskColumn $into): array
    {
        $map = [];
        $options = $into->options();
        $changed = false;
        foreach ($from->options() as $o) {
            $i = collect($options)->search(fn ($h) => mb_strtolower(trim((string) $h['label'])) === mb_strtolower(trim((string) $o['label'])));
            if ($i === false) {
                $id = collect($options)->contains('id', $o['id']) ? 'monday-'.Str::lower(Str::random(8)) : $o['id'];
                $options[] = ['id' => $id, 'label' => $o['label'], 'colour' => $o['colour'] ?? null];
                $changed = true;
                $this->counts['options added']++;
                $map[$o['id']] = $id;
            } else {
                $map[$o['id']] = $options[$i]['id'];
            }
        }
        if ($changed && $into->id !== $from->id) {
            $into->forceFill(['settings' => ['options' => $options] + ($into->settings ?? [])])->save();
        }

        return $map;
    }

    private function tasks(TaskBoard $from, TaskBoard $into, array $groups, array $statuses, array $columns, array $options): void
    {
        $companyId = TaskBoards::companyIdOf($into);
        $tasks = Task::query()->withTrashed()->where('board_id', $from->id)->get();
        foreach ($tasks as $t) {
            $status = $t->status_id !== null ? ($statuses[$t->status_id] ?? null) : null;
            $fields = ['board_id' => $into->id, 'company_id' => $companyId, 'franchise_id' => $into->franchise_id];
            if ($t->group_id !== null && isset($groups[$t->group_id])) {
                $fields['group_id'] = $groups[$t->group_id];
            }
            if ($status !== null) {
                $fields['status_id'] = $status->id;
                $fields['status'] = $status->statusKey();
                if (! $status->is_done) {
                    $fields['completed_at'] = null;
                } elseif ($t->completed_at === null) {
                    $fields['completed_at'] = $t->updated_at;
                }
            }
            DB::table('tasks')->where('id', $t->id)->update($fields);
            $this->counts[$t->parent_task_id === null ? 'tasks' : 'subtasks']++;
        }
        foreach ($columns as $fromId => $target) {
            $values = TaskColumnValue::query()->where('column_id', $fromId)->get();
            foreach ($values as $v) {
                $row = ['column_id' => $target->id];
                if (isset($options[$fromId]) && $v->value_text !== null) {
                    $row['value_text'] = $target->type === 'tags'
                        ? json_encode(array_values(array_map(fn ($id) => $options[$fromId][$id] ?? $id, (array) json_decode((string) $v->value_text, true))))
                        : ($options[$fromId][$v->value_text] ?? $v->value_text);
                }
                DB::table('task_column_values')->where('id', $v->id)->update($row);
                $this->counts['values moved']++;
            }
        }
    }

    private function views(TaskBoard $from, TaskBoard $into): void
    {
        $names = TaskBoardView::query()->where('board_id', $into->id)->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        foreach (TaskBoardView::query()->where('board_id', $from->id)->get() as $v) {
            $name = $v->name;
            for ($n = 2; in_array(mb_strtolower($name), $names, true); $n++) {
                $name = mb_substr($v->name, 0, 96).' '.$n;
            }
            $names[] = mb_strtolower($name);
            $v->forceFill(['board_id' => $into->id, 'name' => $name])->save();
            $this->counts['views moved']++;
        }
    }

    private function members(TaskBoard $from, TaskBoard $into): void
    {
        $have = TaskBoardMember::query()->where('board_id', $into->id)->pluck('user_id')->all();
        foreach (TaskBoardMember::query()->where('board_id', $from->id)->get() as $m) {
            if (! in_array($m->user_id, $have, true)) {
                TaskBoardMember::query()->create(['organization_id' => $into->organization_id, 'board_id' => $into->id,
                    'user_id' => $m->user_id, 'role' => $m->role === TaskBoardMember::OWNER ? TaskBoardMember::MEMBER : $m->role]);
                $have[] = $m->user_id;
                $this->counts['members added']++;
            }
        }
    }

    /** The Monday board's rules, every board, group, column and status id they name re-pointed: a new version, whole. */
    private function rules(TaskBoard $from, TaskBoard $into, array $groups, array $statuses, array $columns, User $by): void
    {
        $ids = [$from->id => $into->id] + $groups + array_map(fn (TaskStatus $s) => $s->id, $statuses)
            + array_map(fn (TaskColumn $c) => $c->id, $columns);
        $swap = function (mixed $node) use (&$swap, $ids): mixed {
            if (is_array($node)) {
                return array_map($swap, $node);
            }

            return is_string($node) && isset($ids[$node]) ? $ids[$node] : $node;
        };
        foreach (Automation::query()->with('activeVersion')->get() as $rule) {
            $version = $rule->activeVersion;
            if ($version === null || ! str_contains(json_encode($version->conditions), $from->id)) {
                continue;
            }
            $creator = User::query()->find($rule->created_by) ?? $by;
            $rule->addVersion($swap($version->conditions), $swap($version->actions ?? []), $creator);
            $this->counts['rules re-pointed']++;
        }
    }
}
