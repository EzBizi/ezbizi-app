<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use App\Support\Tasks\TaskBoards;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose 11-23 (row 35): A-1's company board IS "A1 - Operatrion"; writers land there, no second board. */
class CompanyBoardStandInTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_a_board_holding_the_company_key_is_the_company_board(): void
    {
        $alpha = $this->provisionTenant('Alpha');
        $this->inTenant($alpha['org'], function () use ($alpha) {
            $company = $alpha['franchise']->company;
            // The data step: the old company board gives up its key and goes to the bin.
            TaskBoard::query()->where('auto_key', 'company:'.$company->id)->get()->each(function (TaskBoard $b) {
                $b->forceFill(['auto_key' => null])->save();
                $b->delete();
            });
            $ops = TaskBoard::query()->create(['organization_id' => $alpha['org']->id, 'kind' => TaskBoard::KIND_CUSTOM,
                'company_id' => $company->id, 'name' => 'A1 - Operatrion', 'visibility' => TaskBoard::MAIN, 'auto_key' => 'company:'.$company->id]);

            $this->assertSame($ops->id, app(TaskBoards::class)->ensureFor($company)->id);
            $this->assertSame(0, TaskBoard::query()->where('kind', TaskBoard::KIND_COMPANY)->count(), 'no second board made');
        });
    }
}
