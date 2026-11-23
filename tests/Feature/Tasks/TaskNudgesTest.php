<?php

namespace Tests\Feature\Tasks;

use App\Models\Notification;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Shoval 11-13, before Monday turns off: reminders, overdue and quiet-task alerts, a daily summary. */
class TaskNudgesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private function bells(array $t, string $kind): array
    {
        return $this->inTenant($t['org'], fn () => Notification::query()->where('kind', $kind)->pluck('title', 'user_id')->all());
    }

    private function task(array $t, array $input): Task
    {
        return $this->inTenant($t['org'], function () use ($t, $input) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first();

            return app(TaskWriter::class)->create($board, $input + ['assignee_user_ids' => [$t['admin']->id]], $t['admin']);
        });
    }

    public function test_a_reminder_rings_its_persons_bell_once_when_due(): void
    {
        Carbon::setTestNow('2026-11-20 09:00:00');
        $t = $this->provisionTenant('Alpha');
        $task = $this->task($t, ['title' => 'Call Pat back']);
        $this->signedInAs($t['admin'])->postJson("/api/v1/tasks/{$task->id}/reminders", ['remind_at' => '2026-11-20 08:00:00'])->assertUnprocessable();
        $this->signedInAs($t['admin'])->postJson("/api/v1/tasks/{$task->id}/reminders", ['remind_at' => '2026-11-20 10:00:00', 'note' => 'Ask about the cap'])->assertCreated();

        $this->artisan('ezbizi:tasks-nudges')->assertSuccessful();
        $this->assertSame([], $this->bells($t, 'task.reminder'), 'not yet due');

        Carbon::setTestNow('2026-11-20 10:01:00');
        $this->artisan('ezbizi:tasks-nudges')->assertSuccessful();
        $this->artisan('ezbizi:tasks-nudges')->assertSuccessful();
        $this->assertSame([$t['admin']->id => 'Reminder: Call Pat back'], $this->bells($t, 'task.reminder'), 'once');
    }

    public function test_an_overdue_task_tells_its_owners(): void
    {
        Carbon::setTestNow('2026-11-20 09:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->task($t, ['title' => 'Order the liner', 'due_on' => '2026-11-18']);

        $this->artisan('ezbizi:tasks-overdue')->assertSuccessful();
        $this->assertSame([$t['admin']->id => 'Overdue: Order the liner'], $this->bells($t, 'task.due'));
    }

    public function test_a_week_without_an_update_nudges_the_owners_once(): void
    {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $t = $this->provisionTenant('Alpha');
        $quiet = $this->task($t, ['title' => 'Quiet one']);
        $busy = $this->task($t, ['title' => 'Busy one']);
        Carbon::setTestNow('2026-11-18 09:00:00');
        $this->inTenant($t['org'], fn () => app(TaskWriter::class)->comment($busy, $t['admin'], 'On it'));
        DB::table('tasks')->where('id', $busy->id)->update(['updated_at' => '2026-11-10 09:00:00']); // only the update is recent

        $this->artisan('ezbizi:tasks-nudges')->assertSuccessful();
        $this->artisan('ezbizi:tasks-nudges')->assertSuccessful();
        $this->assertSame([$t['admin']->id => 'No update for 7 days: Quiet one'], $this->bells($t, 'task.stale'));
    }

    public function test_the_daily_digest_sums_up_each_owners_open_tasks(): void
    {
        Carbon::setTestNow('2026-11-20 08:00:00');
        $t = $this->provisionTenant('Alpha');
        $this->task($t, ['title' => 'Late one', 'due_on' => '2026-11-19']);
        $this->task($t, ['title' => 'Today one', 'due_on' => '2026-11-20']);
        $this->task($t, ['title' => 'Someday']);

        $this->artisan('ezbizi:tasks-daily-digest')->assertSuccessful();
        $this->assertSame([$t['admin']->id => 'Your tasks today: 3 open, 1 due today, 1 overdue'], $this->bells($t, 'task.digest'));
    }
}
