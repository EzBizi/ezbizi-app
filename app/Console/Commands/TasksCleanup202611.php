<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskGroup;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Tasks\TaskWriter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One-off (Jose, 2026-11-02, "2 - yes / 3 - yes / 4 - sure assign them"),
 * from docs/reviews/2026-11-02/task-board.md section 5:
 *
 *  1. Every board whose Customer Experience columns deviate (New York's,
 *     made by the complaint rule as plain text) is normalised: Description
 *     becomes the Complaint / Bad Review status column in place (same id,
 *     text values mapped to option ids), Client name is added and filled
 *     from the linked lead or customer, and an uncoloured group gets red.
 *  2. Duplicates are soft-deleted through TaskWriter: same board, same
 *     about record, same title, created within 10 minutes of the kept one.
 *     The oldest is kept.
 *  3. The unparsed "Complaint: -" task is soft-deleted.
 *  4. Unowned top-level tasks in a Customer Experience group go to Adeline
 *     (primary) plus --also-assign. Without --also-assign the step is
 *     skipped, so a later run with it does not miss these tasks.
 *
 * Idempotent; --dry-run rolls everything back.
 */
class TasksCleanup202611 extends Command
{
    public const ADELINE = '01a08791-8901-70b6-bd1c-ca1367c41bd2';

    public const UNPARSED = '01a145b5-2596-71a7-92d2-026e33e313a8';

    public const COLOUR = '#E5484D';

    public const WINDOW_SECONDS = 600;

    /** Lower-cased text value => option id. */
    public const VALUE_MAP = [
        'complaint' => 'complaint',
        'bad review' => 'bad_review',
        'bad_review' => 'bad_review',
        'sad review' => 'bad_review',
    ];

    protected $signature = 'ezbizi:tasks-cleanup-2026-11 {--dry-run} {--also-assign=}';

    protected $description = 'One-off: normalise Customer Experience boards, delete duplicate and unparsed tasks, assign unowned CX tasks';

    public function handle(TaskWriter $writer, AuditLogger $audit, TenantContext $tenant): int
    {
        $dry = (bool) $this->option('dry-run');
        $also = trim((string) $this->option('also-assign')) ?: null;
        $counts = ['boards normalised' => 0, 'values mapped' => 0, 'names filled' => 0];
        $unmapped = [];
        $duplicates = [];
        $unparsed = [];
        $assigned = [];
        $notAssigned = [];

        DB::beginTransaction();
        try {
            $orgIds = TaskBoard::query()->withoutGlobalScopes()->whereNull('deleted_at')->distinct()->pluck('organization_id');
            foreach ($orgIds as $orgId) {
                $org = Organization::query()->withoutGlobalScopes()->find($orgId);
                if ($org === null) {
                    continue;
                }
                $tenant->run($org, function () use ($writer, $audit, &$counts, &$unmapped) {
                    $groups = TaskGroup::query()->get()->filter(fn (TaskGroup $g) => self::isCx($g->name));
                    foreach ($groups->groupBy('board_id') as $boardId => $cxGroups) {
                        $board = TaskBoard::query()->find($boardId);
                        if ($board !== null) {
                            $this->normalise($board, $cxGroups->all(), $writer, $audit, $counts, $unmapped);
                        }
                    }
                });
                $tenant->run($org, function () use ($writer, &$duplicates) {
                    $this->deleteDuplicates($writer, $duplicates);
                });
            }

            $task = Task::query()->withoutGlobalScopes()->whereNull('deleted_at')->find(self::UNPARSED);
            if ($task !== null) {
                $tenant->run(Organization::query()->withoutGlobalScopes()->findOrFail($task->organization_id), function () use ($writer, &$unparsed) {
                    $writer->delete(Task::query()->findOrFail(self::UNPARSED), null);
                    $unparsed[] = self::UNPARSED;
                });
            }

            if ($also !== null) {
                $adeline = User::query()->withoutGlobalScopes()->find(self::ADELINE);
                if ($adeline === null) {
                    throw new \RuntimeException('Adeline ('.self::ADELINE.') is not a user here.');
                }
                $tenant->run(Organization::query()->withoutGlobalScopes()->findOrFail($adeline->organization_id), function () use ($writer, $also, &$assigned, &$notAssigned) {
                    $this->assign($writer, [self::ADELINE, $also], $assigned, $notAssigned);
                });
            }

            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->line($dry ? '[dry run, nothing saved]' : '[saved]');
        $this->line('boards normalised: '.$counts['boards normalised']);
        $this->line('values mapped: '.$counts['values mapped']);
        $this->line('client names filled: '.$counts['names filled']);
        $this->line('values not mapped: '.count($unmapped).self::ids($unmapped));
        $this->line('duplicates deleted: '.count($duplicates).self::ids($duplicates));
        $this->line('unparsed deleted: '.count($unparsed).self::ids($unparsed));
        if ($also === null) {
            $this->warn('tasks assigned: skipped (pass --also-assign=<Jonathan\'s user id>)');
        } else {
            $this->line('tasks assigned: '.count($assigned));
            $this->line('tasks not assignable: '.count($notAssigned).self::ids($notAssigned));
        }

        return self::SUCCESS;
    }

    public static function isCx(?string $name): bool
    {
        return mb_strtolower(trim((string) $name)) === mb_strtolower(TasksCustomerExperience::GROUP);
    }

    private static function ids(array $ids): string
    {
        return $ids === [] ? '' : ' '.implode(' ', $ids);
    }

    /** @param  list<TaskGroup>  $cxGroups */
    private function normalise(TaskBoard $board, array $cxGroups, TaskWriter $writer, AuditLogger $audit, array &$counts, array &$unmapped): void
    {
        $changed = false;
        $find = fn (string $name) => TaskColumn::query()->where('board_id', $board->id)->get()
            ->first(fn (TaskColumn $c) => mb_strtolower(trim($c->name)) === mb_strtolower($name));
        $wantOptions = array_map(
            fn (string $id) => ['id' => $id, 'label' => TasksCustomerExperience::KINDS[$id][0], 'colour' => TasksCustomerExperience::KINDS[$id][1]],
            array_keys(TasksCustomerExperience::KINDS),
        );

        foreach ($cxGroups as $group) {
            if (blank($group->colour)) {
                $group->colour = self::COLOUR;
                $group->save();
                $audit->log('task_board.group_updated', $group);
                $changed = true;
            }
        }

        $type = $find(TasksCustomerExperience::DEFAULT_TYPE_COLUMN);
        if ($type === null) {
            $type = TaskColumn::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id,
                'name' => TasksCustomerExperience::DEFAULT_TYPE_COLUMN, 'type' => 'status',
                'settings' => ['options' => $wantOptions],
                'position' => (int) $board->columns()->max('position') + 1,
            ]);
            $audit->log('task_board.column_created', $type);
            $changed = true;
        } elseif ($type->type === 'text') {
            // In place: same id, values move from text to option ids.
            $type->type = 'status';
            $type->settings = ['options' => $wantOptions] + ($type->settings ?? []);
            $type->save();
            $audit->log('task_board.column_updated', $type);
            $changed = true;
            foreach (TaskColumnValue::query()->where('column_id', $type->id)->get() as $value) {
                $raw = (string) $value->value_text;
                $id = self::VALUE_MAP[mb_strtolower(trim($raw))] ?? null;
                if ($id === null) {
                    $unmapped[] = $value->task_id;
                } elseif ($id !== $raw) {
                    $value->value_text = $id;
                    $value->save();
                    $counts['values mapped']++;
                }
            }
        } elseif ($type->type === 'status') {
            $options = $type->options();
            $missing = array_diff(array_keys(TasksCustomerExperience::KINDS), array_column($options, 'id'));
            if ($missing !== []) {
                foreach ($wantOptions as $o) {
                    if (in_array($o['id'], $missing, true)) {
                        $options[] = $o;
                    }
                }
                $type->settings = ['options' => $options] + ($type->settings ?? []);
                $type->save();
                $audit->log('task_board.column_updated', $type);
                $changed = true;
            }
        }

        $name = $find(TasksCustomerExperience::NAME_COLUMN);
        if ($name === null) {
            $name = TaskColumn::query()->create([
                'organization_id' => $board->organization_id, 'board_id' => $board->id,
                'name' => TasksCustomerExperience::NAME_COLUMN, 'type' => 'text', 'settings' => null,
                'position' => (int) $board->columns()->max('position') + 1,
            ]);
            $audit->log('task_board.column_created', $name);
            $changed = true;
        }
        if ($name->type === 'text') {
            $tasks = Task::query()->where('board_id', $board->id)
                ->whereIn('group_id', array_map(fn (TaskGroup $g) => $g->id, $cxGroups))
                ->whereNotNull('about_id')->with('values')->get();
            foreach ($tasks as $task) {
                if (filled($task->values->firstWhere('column_id', $name->id)?->value_text)) {
                    continue;
                }
                $client = self::clientName($task);
                if ($client !== null) {
                    $writer->update($task, ['values' => [$name->id => mb_substr($client, 0, 5000)]], null);
                    $counts['names filled']++;
                }
            }
        }

        if ($changed) {
            $counts['boards normalised']++;
        }
    }

    private static function clientName(Task $task): ?string
    {
        $record = $task->aboutRecord();
        if ($record instanceof Job) {
            $record = Customer::query()->find($record->customer_id);
        }
        $name = match (true) {
            $record instanceof Lead => $record->contact_name,
            $record instanceof Customer => $record->name,
            default => null,
        };

        return filled($name) ? trim($name) : null;
    }

    private function deleteDuplicates(TaskWriter $writer, array &$deleted): void
    {
        $keys = Task::query()->whereNotNull('about_id')
            ->select('board_id', 'about_type', 'about_id', 'title')
            ->groupBy('board_id', 'about_type', 'about_id', 'title')
            ->havingRaw('count(*) > 1')->get();

        foreach ($keys as $key) {
            $tasks = Task::query()->where('board_id', $key->board_id)->where('about_type', $key->about_type)
                ->where('about_id', $key->about_id)->where('title', $key->title)
                ->orderBy('created_at')->orderBy('id')->get();
            $kept = null;
            foreach ($tasks as $task) {
                if ($kept !== null && $kept->created_at->diffInSeconds($task->created_at, true) <= self::WINDOW_SECONDS) {
                    $writer->delete($task, null);
                    $deleted[] = $task->id;
                } else {
                    $kept = $task;
                }
            }
        }
    }

    /** @param  list<string>  $owners */
    private function assign(TaskWriter $writer, array $owners, array &$assigned, array &$notAssigned): void
    {
        $groupIds = TaskGroup::query()->get()->filter(fn (TaskGroup $g) => self::isCx($g->name))->pluck('id');
        $tasks = Task::query()->whereIn('group_id', $groupIds)->whereNull('assignee_user_id')
            ->whereNull('parent_task_id')->orderBy('created_at')->get();
        foreach ($tasks as $task) {
            try {
                $writer->update($task, ['assignee_user_ids' => $owners], null);
                $assigned[] = $task->id;
            } catch (ValidationException) {
                $notAssigned[] = $task->id;
            }
        }
    }
}
