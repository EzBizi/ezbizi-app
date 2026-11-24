<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Support\Tasks\TaskBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Desk 11-23: a removed account's personal board ("Eric Valdes" twice) leaves everyone's list. */
class RemovedAccountBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_removed_accounts_personal_board_is_not_listed(): void
    {
        $t = $this->provisionTenant('Alpha');
        $eric = $this->userWithRole($t, 'Technician', ['tasks.view'], 'eric@example.test');
        $this->inTenant($t['org'], function () use ($t, $eric) {
            $boards = app(TaskBoards::class);
            $board = $boards->ensureFor($eric->fresh());
            $admin = $t['admin']->fresh();
            $this->assertTrue($boards->visibleTo($admin)->whereKey($board->id)->exists(), 'precondition: view_all lists it');
            $eric->forceFill(['email' => "removed-{$eric->id}@invalid", 'is_active' => false])->save();
            $this->assertFalse($boards->visibleTo($admin)->whereKey($board->id)->exists());
            $this->assertTrue($boards->visibleTo($admin)->whereKey(TaskBoard::query()->where('owner_user_id', $admin->id)->value('id'))->exists(), 'a live person\'s board still shows');
        });
    }
}
