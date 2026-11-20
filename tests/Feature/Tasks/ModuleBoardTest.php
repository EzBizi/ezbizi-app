<?php

namespace Tests\Feature\Tasks;

use App\Models\Task;
use App\Models\TaskStatus;
use App\Support\Tasks\ModuleBoard;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Customer Experience 11-20: complaints mirrored onto a "Reviews & Complaints" board, both ways. */
class ModuleBoardTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_module_owns_a_board_upserts_by_its_key_and_hears_a_persons_edits_only(): void
    {
        $t = $this->provisionTenant('Alpha');
        $heard = [];
        ModuleBoard::listen('complaint:', function (Task $task, array $kinds, $update) use (&$heard) {
            $heard[] = [$task->external_ref, $kinds, $update?->body];
        });

        $this->inTenant($t['org'], function () use ($t, &$heard) {
            $mb = app(ModuleBoard::class);
            $spec = [['New' => false, 'In progress' => false, 'Solved' => true, 'Cannot be solved' => true],
                ['Client' => ['Client', 'Not a client'], 'Rating' => 'number']];
            $board = $mb->board('cx-complaints', 'Reviews & Complaints', ...$spec);
            $this->assertSame($board->id, $mb->board('cx-complaints', 'Reviews & Complaints', ...$spec)->id, 'once per tenant');

            $task = $mb->upsert($board, 'complaint:c1', ['title' => 'Soot on the carpet', 'group' => 'Connecticut', 'status' => 'New',
                'owners' => [$t['admin']->id], 'values' => ['Client' => 'Client', 'Rating' => 1]]);
            $again = $mb->upsert($board, 'complaint:c1', ['status' => 'In progress']);
            $this->assertSame($task->id, $again->id, 'same card');
            $task->refresh();
            $this->assertSame('Connecticut', $task->group->name);
            $this->assertSame('In progress', TaskStatus::query()->find($task->status_id)->name);
            $this->assertSame([$t['admin']->id], $task->owners()->pluck('user_id')->all());
            $this->assertSame([], $heard, 'the module\'s own writes never echo');

            // A person on the board: the module hears it.
            $solved = TaskStatus::query()->where('board_id', $board->id)->where('name', 'Solved')->value('id');
            app(TaskWriter::class)->update($task, ['status_id' => $solved], $t['admin']);
            app(TaskWriter::class)->comment($task, $t['admin'], 'Called her back');
        });

        $this->assertSame([['complaint:c1', ['status'], null], ['complaint:c1', ['update'], 'Called her back']], $heard);
    }
}
