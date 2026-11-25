<?php

namespace App\Support\Tasks;

use App\Models\Complaint;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskStatus;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Models\WorkizJob;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One customer issue, one source (Jose 11-25, CX): the Complaint. Its card on
 * "Reviews & Complaints" (ComplaintBoardMirror) and the branch-board task
 * Adeline or Natalie works are joined by the branch task's "Complaint" connect
 * column (Monday's connect-boards), matched by lead, phone or job #. A status,
 * owner or update on either side is copied to the other; the card writes the
 * Complaint itself (ComplaintBoardMirror::heard).
 */
final class ComplaintLink
{
    public const COLUMN = 'Complaint';

    private const CARD = 'complaint:';

    /** Days either side of the complaint in which a branch task can be its copy. */
    private const WINDOW_DAYS = 30;

    private static int $writing = 0;

    /** From TaskWriter, beside ModuleBoard::changed: copy what a person changed to the other side. */
    public static function changed(Task $task, array $kinds, ?TaskUpdate $update = null): void
    {
        if (self::$writing > 0 || array_intersect($kinds, ['status', 'owners', 'update']) === []) {
            return;
        }
        DB::afterCommit(function () use ($task, $kinds, $update) {
            try {
                $task = $task->fresh() ?? $task;
                $others = str_starts_with((string) $task->external_ref, self::CARD) ? self::branchTasksOf($task) : self::cardsOf($task);
                foreach ($others as $other) {
                    self::copy($task, $other, $kinds, $update);
                }
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /** The card(s) a branch task is joined to. */
    public static function cardsOf(Task $task): array
    {
        $column = TaskColumn::query()->where('board_id', $task->board_id)->where('name', self::COLUMN)->where('type', 'connect')->first();
        $ids = $column ? (array) json_decode((string) TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $column->id)->value('value_text'), true) : [];

        return $ids === [] ? [] : Task::query()->whereIn('id', $ids)->where('external_ref', 'like', self::CARD.'%')->get()->all();
    }

    /** The branch tasks joined to a card. */
    public static function branchTasksOf(Task $card): array
    {
        $columns = TaskColumn::query()->where('name', self::COLUMN)->where('type', 'connect')->pluck('id');
        $ids = TaskColumnValue::query()->whereIn('column_id', $columns)->where('value_text', 'like', '%"'.$card->id.'"%')->pluck('task_id');

        return Task::query()->whereIn('id', $ids)->get()->all();
    }

    private static function copy(Task $from, Task $to, array $kinds, ?TaskUpdate $update): void
    {
        $writer = app(TaskWriter::class);
        $toCard = str_starts_with((string) $to->external_ref, self::CARD);
        $actor = $update?->user_id ? User::query()->find($update->user_id) : null;
        self::$writing++;
        try {
            $input = [];
            if (in_array('status', $kinds, true)) {
                $done = (bool) TaskStatus::query()->whereKey($from->status_id)->value('is_done');
                if ($toCard) {
                    // Done on the branch board: Solved; any other label: being worked. A closed complaint (Solved,
                    // Cannot be solved) is never reopened from a branch, and "Cannot be solved" only a person sets (CX 11-25).
                    $closed = (bool) TaskStatus::query()->whereKey($to->status_id)->value('is_done');
                    if ($done && TaskStatus::query()->whereKey($to->status_id)->value('name') !== 'Solved') {
                        $input['status'] = 'Solved';
                    } elseif (! $done && ! $closed) {
                        $input['status'] = 'In progress';
                    }
                } elseif ($done) {
                    $doneId = TaskStatus::query()->where('board_id', $to->board_id)->where('is_done', true)->orderBy('position')->value('id');
                    $doneId === null || $input['status_id'] = $doneId;
                }
            }
            if (in_array('owners', $kinds, true)) {
                $input['assignee_user_ids'] = $from->owners()->pluck('user_id')->map(fn ($id) => (string) $id)->values()->all();
            }
            if ($input !== []) {
                $writer->update($to->load('board'), $input, $actor);
            }
            if (in_array('update', $kinds, true) && $update !== null && trim((string) $update->body) !== '' && $actor !== null) {
                $board = TaskBoard::query()->whereKey($from->board_id)->value('name');
                $writer->comment($to, $actor, 'From '.$board.': '.$update->body);
            }
        } finally {
            self::$writing--;
        }
    }

    /**
     * Joins a complaint's card to the branch tasks that are the same issue: the same lead, phone, EzBizi job # or
     * Workiz job id, on a branch (franchise) board, made within WINDOW_DAYS of the complaint. Returns how many joined.
     */
    public static function link(Complaint $complaint, Task $card): int
    {
        $keys = self::keysOf($complaint);
        if ($keys === []) {
            return 0;
        }
        $at = $complaint->raised_at ?? $complaint->created_at ?? now();
        $boards = TaskBoard::query()->where('kind', TaskBoard::KIND_FRANCHISE)->pluck('id');
        $columns = TaskColumn::query()->whereIn('board_id', $boards)->whereIn('name', ['Phone number', 'EzBizi Job #', 'Workiz Job ID'])->get(['id', 'name']);
        $taskIds = collect();
        foreach ($columns as $c) {
            $want = $c->name === 'Phone number' ? $keys['phones'] : $keys['jobs'];
            if ($want === []) {
                continue;
            }
            $taskIds = $taskIds->merge(TaskColumnValue::query()->where('column_id', $c->id)->get(['task_id', 'value_text'])
                ->filter(fn ($v) => in_array(self::key($c->name, (string) $v->value_text), $want, true))->pluck('task_id'));
        }
        if ($keys['lead'] !== null) {
            $taskIds = $taskIds->merge(Task::query()->whereIn('board_id', $boards)->where('about_type', 'lead')->where('about_id', $keys['lead'])->pluck('id'));
        }
        $n = 0;
        foreach (Task::query()->whereIn('id', $taskIds->unique())->whereBetween('created_at', [$at->copy()->subDays(self::WINDOW_DAYS), $at->copy()->addDays(self::WINDOW_DAYS)])->get() as $task) {
            $n += self::join($task, $card) ? 1 : 0;
        }

        return $n;
    }

    /**
     * The other way: a branch task got a phone, job # or lead; join it to the complaint that is the same issue
     * (its card), when there is one within WINDOW_DAYS.
     */
    public static function linkTask(Task $task): int
    {
        if (self::$writing > 0 || ! TaskBoard::query()->whereKey($task->board_id)->where('kind', TaskBoard::KIND_FRANCHISE)->exists()) {
            return 0;
        }
        $values = TaskColumnValue::query()->join('task_columns as c', 'c.id', '=', 'task_column_values.column_id')
            ->where('task_column_values.task_id', $task->id)->whereIn('c.name', ['Phone number', 'EzBizi Job #', 'Workiz Job ID'])
            ->get(['c.name', 'task_column_values.value_text']);
        $phones = $values->where('name', 'Phone number')->map(fn ($v) => self::key('Phone number', (string) $v->value_text))->filter()->values()->all();
        $jobs = $values->where('name', '!=', 'Phone number')->map(fn ($v) => mb_strtolower(trim((string) $v->value_text)))->filter()->values()->all();
        $leads = Lead::query()->where('created_at', '>=', $task->created_at->copy()->subDays(self::WINDOW_DAYS))
            ->where(function ($q) use ($task, $phones, $jobs) {
                $q->whereRaw('1 = 0');
                if ($task->about_type === 'lead' && $task->about_id) {
                    $q->orWhere('id', $task->about_id);
                }
                foreach ($phones as $p) {
                    $q->orWhere('phone', 'like', '%'.$p);
                }
                if ($jobs !== []) {
                    $q->orWhereIn('job_id', Job::query()->whereIn(DB::raw('LOWER(number)'), $jobs)->select('id'))
                        ->orWhereIn('job_id', WorkizJob::query()->whereIn('serial_id', $jobs)->select('job_id'));
                }
            })->pluck('id');
        $n = 0;
        foreach (Complaint::query()->where('source_type', 'lead')->whereIn('source_id', $leads)->get() as $complaint) {
            $card = Task::query()->where('external_ref', self::CARD.$complaint->id)->first();
            $n += $card !== null && self::join($task, $card) ? 1 : 0;
        }

        return $n;
    }

    /** Puts the card in the branch task's Complaint column (made on its board the first time). */
    public static function join(Task $task, Task $card): bool
    {
        $column = TaskColumn::query()->firstOrCreate(['board_id' => $task->board_id, 'name' => self::COLUMN, 'type' => 'connect'], [
            'organization_id' => $task->organization_id,
            'position' => (int) TaskColumn::query()->where('board_id', $task->board_id)->max('position') + 1,
        ]);
        $have = (array) json_decode((string) TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $column->id)->value('value_text'), true);
        if (in_array($card->id, $have, true)) {
            return false;
        }
        self::$writing++;
        try {
            app(TaskWriter::class)->update($task->load('board'), ['values' => [$column->id => [...$have, (string) $card->id]]], null);
        } finally {
            self::$writing--;
        }

        return true;
    }

    /** @return array{lead: ?string, phones: list<string>, jobs: list<string>} */
    private static function keysOf(Complaint $complaint): array
    {
        if ($complaint->source_type !== 'lead' || ($lead = Lead::query()->find($complaint->source_id)) === null) {
            return [];
        }
        $phones = array_values(array_filter([self::key('Phone number', (string) $lead->phone)]));
        $jobs = [];
        if ($lead->job_id && ($job = Job::query()->find($lead->job_id)) !== null) {
            $jobs[] = self::key('EzBizi Job #', (string) $job->number);
            if ($serial = WorkizJob::query()->where('job_id', $job->id)->value('serial_id')) {
                $jobs[] = self::key('Workiz Job ID', (string) $serial);
            }
        }

        return ['lead' => (string) $lead->id, 'phones' => $phones, 'jobs' => array_values(array_filter($jobs))];
    }

    private static function key(string $column, string $value): string
    {
        if ($column === 'Phone number') {
            $digits = preg_replace('/\D/', '', $value);

            return strlen($digits) >= 10 ? substr($digits, -10) : '';
        }

        return mb_strtolower(trim($value));
    }
}
