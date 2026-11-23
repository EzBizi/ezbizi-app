<?php

namespace Tests\Feature\Tasks;

use App\Models\Notification;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Suggestion 01a20956 (Jennine, 11-23): "not getting notifications" of techs' updates. Monday rings an item's subscribers. */
class UpdateTellsFollowersTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_techs_update_rings_the_tasks_owner_not_the_tech(): void
    {
        $t = $this->provisionTenant('Alpha');
        $jennine = $this->userWithRole($t, 'Dispatcher', ['tasks.view', 'tasks.edit'], 'jennine@example.test');
        $tech = $this->userWithRole($t, 'Technician', ['tasks.view', 'tasks.edit'], 'tech@example.test');
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first());
        $task = $this->signedInAs($jennine)->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'No heat',
            'assignee_user_ids' => [$jennine->id]])->assertCreated()->json('data.id');

        $this->signedInAs($tech)->postJson("/api/v1/tasks/$task/updates", ['body' => 'On site, liner cracked'])->assertCreated();

        $bells = $this->inTenant($t['org'], fn () => Notification::query()->where('kind', 'task.reply')->pluck('user_id')->all());
        $this->assertSame([$jennine->id], $bells);
    }
}
