<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\GivesAuthenticatorCodes;
use Tests\TestCase;

/** Jose, 2026-11-09: "Removed accounts should not show up on boards, in any case they should be archived." */
class TaskBoardArchivedTest extends TestCase
{
    use BuildsTenants;
    use GivesAuthenticatorCodes;
    use RefreshDatabase;

    public function test_a_departed_persons_board_leaves_the_list_and_waits_under_archived(): void
    {
        $t = $this->provisionTenant('Alpha');
        $gone = $this->userWithRole($t, 'Staff', ['tasks.view', 'tasks.create', 'tasks.edit']);
        $this->signedInAs($gone)->getJson('/api/v1/task-boards')->assertOk(); // makes their personal board
        $board = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('owner_user_id', $gone->id)->firstOrFail());
        $this->signedInAs($t['admin'])->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Call back Ann'])->assertCreated();

        $ids = fn () => array_column($this->signedInAs($t['admin'])->getJson('/api/v1/task-boards')->json('data'), 'id');
        $this->assertContains($board->id, $ids());

        $this->inTenant($t['org'], fn () => $gone->forceFill(['is_active' => false])->save());

        $this->assertNotContains($board->id, $ids());
        $this->signedInAs($t['admin'])->getJson('/api/v1/task-boards')->assertJsonPath('meta.archived_count', 1);
        $archived = $this->signedInAs($t['admin'])->getJson('/api/v1/task-boards?archived=1')->assertOk()->json('data');
        $this->assertSame([$board->id], array_column($archived, 'id'));
        $this->assertSame(1, $archived[0]['open_tasks']);

        // Not an Administrator: no archive, and the board stays out of sight.
        $staff = $this->userWithRole($t, 'Staff 2', ['tasks.view', 'tasks.create', 'tasks.edit']);
        $res = $this->signedInAs($staff)->getJson('/api/v1/task-boards?archived=1')->assertOk();
        $this->assertNotContains($board->id, array_column($res->json('data'), 'id'));
        $this->assertNull($res->json('meta.archived_count'));
    }

    public function test_archive_is_reversible_and_a_deleted_board_restores_for_30_days(): void
    {
        $t = $this->provisionTenant('Alpha');
        $api = $this->signedInAs($t['admin']);
        $custom = $api->postJson('/api/v1/task-boards', ['name' => 'Spring jobs', 'visibility' => 'main'])->assertCreated()->json('data.id');
        $place = $this->inTenant($t['org'], fn () => TaskBoard::query()->where('franchise_id', $t['franchise']->id)->first());
        $listed = fn (bool $archived = false) => array_column($this->signedInAs($t['admin'])
            ->getJson('/api/v1/task-boards'.($archived ? '?archived=1' : ''))->json('data'), 'id');

        // An automatic board archives, and comes back.
        $api->postJson("/api/v1/task-boards/{$place->id}/archive")->assertOk();
        $this->assertNotContains($place->id, $listed());
        $this->assertContains($place->id, $listed(true));
        $api->postJson("/api/v1/task-boards/{$place->id}/unarchive")->assertOk();
        $this->assertContains($place->id, $listed());
        $api->deleteJson("/api/v1/task-boards/{$place->id}", ['confirm' => $place->name])->assertStatus(422);

        // A custom board deletes, waits under Archived, and restores.
        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson("/api/v1/task-boards/{$custom}", ['confirm' => 'Spring jobs'] + $code)->assertNoContent();
        $this->assertNotContains($custom, $listed());
        $row = collect($this->signedInAs($t['admin'])->getJson('/api/v1/task-boards?archived=1')->json('data'))->firstWhere('id', $custom);
        $this->assertTrue($row['deleted']);
        $this->assertSame(now()->addDays(30)->toDateString(), $row['restore_until']);
        $api->postJson("/api/v1/task-boards/{$custom}/restore")->assertOk();
        $this->assertContains($custom, $listed());

        // After 30 days the purge takes it for good.
        $code = $this->authenticatorCode($t['admin']);
        $this->signedInAs($t['admin'])->deleteJson("/api/v1/task-boards/{$custom}", ['confirm' => 'Spring jobs'] + $code)->assertNoContent();
        DB::table('task_boards')->where('id', $custom)->update(['deleted_at' => now()->subDays(31)]);
        $this->artisan('ezbizi:purge-deleted-task-boards')->assertSuccessful();
        $this->assertNull($this->inTenant($t['org'], fn () => TaskBoard::query()->withTrashed()->find($custom)));
    }
}
