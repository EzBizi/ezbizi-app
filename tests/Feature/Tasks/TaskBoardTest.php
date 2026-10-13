<?php

namespace Tests\Feature\Tasks;

use App\Models\AutomationEvent;
use App\Models\Company;
use App\Models\Franchise;
use App\Models\Task;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_every_company_franchise_and_person_gets_a_board_with_default_groups(): void
    {
        $tenant = $this->provisionTenant('Alpha');

        $this->inTenant($tenant['org'], function () use ($tenant) {
            $company = TaskBoard::query()->where('kind', 'company')->where('company_id', $tenant['company']->id)->first();
            $franchise = TaskBoard::query()->where('kind', 'franchise')->where('franchise_id', $tenant['franchise']->id)->first();
            $mine = TaskBoard::query()->where('kind', 'user')->where('owner_user_id', $tenant['admin']->id)->first();

            $this->assertNotNull($company);
            $this->assertNotNull($franchise);
            $this->assertNotNull($mine);
            $this->assertSame(['This week', 'Next week', 'Done'], $franchise->groups->pluck('name')->all());

            // The backfill is idempotent.
            Artisan::call('ezbizi:backfill-task-boards');
            $this->assertSame(3, TaskBoard::query()->count());
        });
    }

    public function test_boards_follow_the_current_view_and_never_show_a_sibling_company(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        [$beta, $betaF1] = $this->inTenant($tenant['org'], function () use ($tenant) {
            $beta = Company::create(['organization_id' => $tenant['org']->id, 'name' => 'Beta Co', 'is_active' => true]);
            $betaF1 = Franchise::create(['company_id' => $beta->id, 'name' => 'Beta F1']);

            return [$beta, $betaF1];
        });

        $viewer = $this->userWithRole($tenant, 'Viewer', ['tasks.view']);

        $names = fn (string $view) => collect($this->signedInAs($viewer)
            ->withHeader('X-EzBizi-View', $view)
            ->getJson('/api/v1/task-boards')
            ->assertOk()
            ->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Alpha Co', 'Alpha F1', 'Viewer'], $names('company:'.$tenant['company']->id));
        $this->assertSame(['Alpha Co', 'Alpha F1', 'Viewer'], $names('franchise:'.$tenant['franchise']->id));
        $this->assertSame(['Beta Co', 'Beta F1', 'Viewer'], $names('company:'.$beta->id));
        $this->assertNotContains('Beta F1', $names('franchise:'.$tenant['franchise']->id));
    }

    public function test_assignable_is_the_owner_on_a_personal_board_and_reach_on_a_team_board(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $betaF1 = $this->inTenant($tenant['org'], function () use ($tenant) {
            $beta = Company::create(['organization_id' => $tenant['org']->id, 'name' => 'Beta Co', 'is_active' => true]);

            return Franchise::create(['company_id' => $beta->id, 'name' => 'Beta F1']);
        });
        $viewer = $this->userWithRole($tenant, 'Viewer', ['tasks.view']);
        $outsider = $this->userWithRole($tenant, 'Outsider', ['tasks.view'], onlyAt: $betaF1);

        $assignable = function (string $name) use ($viewer) {
            $boards = $this->signedInAs($viewer)->getJson('/api/v1/task-boards')->json('data');
            $id = collect($boards)->firstWhere('name', $name)['id'];

            return collect($this->signedInAs($viewer)->getJson("/api/v1/task-boards/{$id}")->assertOk()->json('data.assignable'))->pluck('name')->all();
        };

        $this->assertSame(['Viewer'], $assignable('Viewer'));
        $this->assertContains('Viewer', $assignable('Alpha F1'));
        $this->assertNotContains('Outsider', $assignable('Alpha F1'));
    }

    public function test_create_update_and_done_sets_and_clears_completed_at_and_emits(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $tenant['franchise']->id)->first());

        $id = $this->signedInAs($tenant['admin'])
            ->postJson('/api/v1/tasks', [
                'board_id' => $board->id,
                'title' => 'Order ladders',
                'assignee_user_id' => $tenant['admin']->id,
                'due_on' => '2026-10-20',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'not_started')
            ->assertJsonPath('data.owner.id', $tenant['admin']->id)
            ->json('data.id');

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$id}", ['status' => 'done'])
            ->assertOk();
        $this->inTenant($tenant['org'], fn () => $this->assertNotNull(Task::find($id)->completed_at));

        $this->signedInAs($tenant['admin'])->patchJson("/api/v1/tasks/{$id}", ['status' => 'working_on_it'])
            ->assertOk()
            ->assertJsonPath('data.completed_at', null);

        $this->signedInAs($tenant['admin'])->postJson("/api/v1/tasks/{$id}/updates", ['body' => 'On it'])
            ->assertCreated();
        $this->signedInAs($tenant['admin'])->getJson("/api/v1/tasks/{$id}")
            ->assertOk()
            ->assertJsonPath('data.updates.0.body', 'On it');

        $this->inTenant($tenant['org'], function () use ($id, $tenant) {
            $created = AutomationEvent::query()->where('event_key', 'task.created')->first();
            $this->assertSame($id, $created->subject_id);
            $this->assertSame($tenant['admin']->id, $created->payload['assignee_user_id']);
            $this->assertSame('franchise', $created->scope_snapshot['type']);

            $changes = AutomationEvent::query()->where('event_key', 'task.status_changed')->get();
            $this->assertCount(2, $changes);
            $toDone = $changes->firstWhere('payload.before.status', 'not_started');
            $this->assertSame('done', $toDone->payload['after']['status']);
        });

        $this->signedInAs($tenant['admin'])->deleteJson("/api/v1/tasks/{$id}")->assertNoContent();
    }

    public function test_view_only_refused_on_team_board_but_own_board_is_always_writable(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $viewer = $this->userWithRole($tenant, 'Viewer', ['tasks.view']);
        [$team, $own] = $this->inTenant($tenant['org'], fn () => [
            TaskBoard::query()->where('franchise_id', $tenant['franchise']->id)->first(),
            TaskBoard::query()->where('owner_user_id', $viewer->id)->first(),
        ]);

        $this->signedInAs($viewer)->postJson('/api/v1/tasks', ['board_id' => $team->id, 'title' => 'Nope'])
            ->assertForbidden();
        $this->signedInAs($viewer)->postJson('/api/v1/tasks', ['board_id' => $own->id, 'title' => 'Mine'])
            ->assertCreated();

        // Somebody else's personal board is not even visible.
        $adminBoard = $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('owner_user_id', $tenant['admin']->id)->first());
        $this->signedInAs($viewer)->getJson('/api/v1/task-boards/'.$adminBoard->id)->assertNotFound();
    }

    public function test_overdue_is_raised_once_per_task(): void
    {
        $tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $tenant['franchise']->id)->first());

        $this->signedInAs($tenant['admin'])->postJson('/api/v1/tasks', [
            'board_id' => $board->id, 'title' => 'Late', 'due_on' => now()->subDays(2)->toDateString(),
        ])->assertCreated();
        $this->signedInAs($tenant['admin'])->postJson('/api/v1/tasks', [
            'board_id' => $board->id, 'title' => 'Late but done', 'status' => 'done',
            'due_on' => now()->subDays(2)->toDateString(),
        ])->assertCreated();

        Artisan::call('ezbizi:tasks-overdue');
        Artisan::call('ezbizi:tasks-overdue');

        $this->inTenant($tenant['org'], function () {
            $events = AutomationEvent::query()->where('event_key', 'task.overdue')->get();
            $this->assertCount(1, $events);
            $this->assertSame('Late', $events[0]->payload['after']['title']);
        });
    }
}
