<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Jose, 2026-11-13: the rest of Monday's column types, their value shapes and the formula language. */
class TaskColumnMondayTypesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->provisionTenant('Alpha');
        $this->board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $this->tenant['franchise']->id)->first());
    }

    private function api()
    {
        return $this->signedInAs($this->tenant['admin']);
    }

    private function column(array $body)
    {
        return $this->api()->postJson("/api/v1/task-boards/{$this->board->id}/columns", $body);
    }

    private function task(string $title, array $values = [])
    {
        return $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => $title, 'values' => $values]);
    }

    public function test_location_and_rating_keep_their_shape_and_refuse_others(): void
    {
        $place = $this->column(['name' => 'Where', 'type' => 'location'])->assertCreated()->json('data.id');
        $stars = $this->column(['name' => 'Stars', 'type' => 'rating', 'settings' => ['max' => 5]])->assertCreated()->json('data.id');

        $t = $this->task('Cap, Lorain', [$place => ['address' => '1 Main St, Lorain OH', 'lat' => 41.45, 'lng' => -82.18], $stars => 4])
            ->assertCreated()->json('data');
        $this->assertSame(['address' => '1 Main St, Lorain OH', 'lat' => 41.45, 'lng' => -82.18], $t['values'][$place]);
        $this->assertSame(4, $t['values'][$stars]);

        $this->api()->patchJson("/api/v1/tasks/{$t['id']}", ['values' => [$stars => 6]])
            ->assertUnprocessable()->assertJsonValidationErrors("values.$stars");
        $this->api()->patchJson("/api/v1/tasks/{$t['id']}", ['values' => [$place => ['address' => '', 'lat' => 100]]])
            ->assertUnprocessable()->assertJsonValidationErrors("values.$place");
        $this->column(['name' => 'Bad', 'type' => 'rating', 'settings' => ['max' => 50]])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.max');
    }

    public function test_dependency_names_tasks_on_this_board_and_a_mirror_shows_their_values(): void
    {
        $cost = $this->column(['name' => 'Cost', 'type' => 'number'])->assertCreated()->json('data.id');
        $needs = $this->column(['name' => 'Needs', 'type' => 'dependency'])->assertCreated()->json('data.id');
        $mirror = $this->column(['name' => 'Their cost', 'type' => 'mirror',
            'settings' => ['connect_column_id' => $needs, 'column_id' => $cost]])->assertCreated()->json('data.id');

        $first = $this->task('Order a cap', [$cost => 120])->json('data.id');
        $then = $this->task('Fit the cap', [$needs => [$first]])->assertCreated()->json('data');
        $this->assertSame([$first], $then['values'][$needs]);
        $this->assertSame(['120'], $then['values'][$mirror]);

        $this->api()->patchJson("/api/v1/tasks/{$then['id']}", ['values' => [$needs => ['00000000-0000-0000-0000-000000000000']]])
            ->assertUnprocessable()->assertJsonValidationErrors("values.$needs");
        $this->api()->patchJson("/api/v1/tasks/{$then['id']}", ['values' => [$mirror => ['x']]])
            ->assertUnprocessable()->assertJsonValidationErrors("values.$mirror");
    }

    public function test_a_formula_takes_mondays_functions_and_refuses_unknown_words(): void
    {
        $this->column(['name' => 'Total', 'type' => 'formula', 'settings' => [
            'expression' => 'IF([Paid], (VALUE([Price]) * 0.1) + [Cost], [Cost])']])->assertCreated();
        $this->column(['name' => 'Label', 'type' => 'formula', 'settings' => [
            'expression' => 'CONCATENATE(FLOOR([Hours]), "h ", MOD([Minutes], 60), "m")']])->assertCreated();

        foreach (['system("x")', 'IF([A], 1', 'EVAL(1)'] as $bad) {
            $this->column(['name' => 'X', 'type' => 'formula', 'settings' => ['expression' => $bad]])
                ->assertUnprocessable()->assertJsonValidationErrors('settings.expression');
        }
    }
}
