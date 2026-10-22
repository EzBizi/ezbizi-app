<?php

namespace App\Support\Jobs;

use App\Models\Job;
use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\User;
use App\Models\WorkizTeamMember;
use App\Support\Audit\AuditLogger;
use App\Support\Workiz\WorkizJobWriter;
use Illuminate\Validation\ValidationException;

/**
 * A card's Technician and its job's technician are one value (Jose,
 * 2026-10-22: "yes and viceversa"). Setting it on a card about a job assigns
 * the job, through Workiz first when the job is Workiz's; the job's technician
 * changing, here or by the Workiz sync, rewrites its cards' Technician.
 */
final class JobTechnicianSync
{
    /** The card column that is the job's technician, by name, any case. */
    public const COLUMN = 'technician';

    public static function isTechnicianColumn(TaskColumn $column): bool
    {
        return $column->type === 'person' && mb_strtolower(trim($column->name)) === self::COLUMN;
    }

    /** Card → job. Throws, with Workiz's own sentence, when the job cannot take that person; nothing is written then. */
    public static function toJob(Task $task, string $userId, string $key): void
    {
        $job = $task->about_type === 'job' ? Job::query()->with(['workizJob', 'franchise'])->find($task->about_id) : null;
        if ($job === null || $job->performed_by_user_id === $userId) {
            return;
        }

        if ($job->workizJob) {
            $member = WorkizTeamMember::query()->where('franchise_id', $job->franchise_id)->where('user_id', $userId)->first();
            if ($member === null) {
                $name = User::query()->whereKey($userId)->value('name') ?? 'That person';
                throw ValidationException::withMessages([$key => "{$name} is not linked to anyone on this branch's Workiz, so the job cannot be given to them there."]);
            }
            $sent = app(WorkizJobWriter::class)->assign($job->workizJob, $member);
            if ($sent['refused'] !== null) {
                throw ValidationException::withMessages([$key => $sent['refused']]);
            }
        }

        $before = $job->performed_by_user_id;
        $job->forceFill(['performed_by_user_id' => $userId])->save();
        app(AuditLogger::class)->log('job.assigned', $job, ['performed_by_user_id' => [$before, $userId]], ['source' => 'task_card', 'task_id' => $task->id]);
    }

    /** Job → cards: every card about this job with a Technician column now names its technician. */
    public static function toCards(Job $job): void
    {
        $tasks = Task::query()->where('about_type', 'job')->where('about_id', $job->id)->get(['id', 'board_id', 'organization_id']);
        foreach ($tasks as $task) {
            $column = TaskColumn::query()->where('board_id', $task->board_id)->get()->first(fn (TaskColumn $c) => self::isTechnicianColumn($c));
            if ($column === null) {
                continue;
            }
            $job->performed_by_user_id === null
                ? TaskColumnValue::query()->where('task_id', $task->id)->where('column_id', $column->id)->delete()
                : TaskColumnValue::query()->updateOrCreate(['task_id' => $task->id, 'column_id' => $column->id],
                    ['organization_id' => $task->organization_id, 'value_text' => null, 'value_number' => null, 'value_date' => null, 'value_user_id' => $job->performed_by_user_id]);
        }
    }
}
