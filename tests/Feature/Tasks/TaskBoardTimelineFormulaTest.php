<?php

namespace Tests\Feature\Tasks;

use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Design 178 (Jose, 2026-11-03): Timeline columns, number formats, Formula columns. */
class TaskBoardTimelineFormulaTest extends TestCase
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

    public function test_a_timeline_column_keeps_a_range_and_refuses_a_backwards_one(): void
    {
        $col = $this->column(['name' => 'Timeline', 'type' => 'timeline'])->assertCreated()->json('data.id');
        $task = $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'Rebuild, Lorain',
            'values' => [$col => ['from' => '2026-11-09', 'to' => '2026-11-17']]])->assertCreated()->json('data');
        $this->assertSame(['from' => '2026-11-09', 'to' => '2026-11-17'], $task['values'][$col]);

        $this->api()->patchJson("/api/v1/tasks/{$task['id']}", ['values' => [$col => ['from' => '2026-11-17', 'to' => '2026-11-09']]])
            ->assertUnprocessable()->assertJsonValidationErrors("values.$col");
    }

    public function test_number_format_and_footer_are_kept_and_checked(): void
    {
        $this->column(['name' => 'Job Cost', 'type' => 'number', 'settings' => ['format' => 'currency', 'footer' => 'sum']])
            ->assertCreated()->assertJsonPath('data.settings', ['format' => 'currency', 'footer' => 'sum']);
        $this->column(['name' => 'Bad', 'type' => 'number', 'settings' => ['format' => 'roman']])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.format');
    }

    public function test_a_formula_column_keeps_its_expression_and_takes_no_values(): void
    {
        $f = $this->column(['name' => 'Total Cost', 'type' => 'formula', 'settings' => ['expression' => '[Job Cost] + [Materials] * 1.1', 'format' => 'currency']])
            ->assertCreated()->json('data');
        $this->assertSame('[Job Cost] + [Materials] * 1.1', $f['settings']['expression']);

        foreach (['[Job Cost]; DROP', 'system("x")', ''] as $bad) {
            $this->column(['name' => 'X', 'type' => 'formula', 'settings' => ['expression' => $bad]])
                ->assertUnprocessable()->assertJsonValidationErrors('settings.expression');
        }

        $this->api()->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'T', 'values' => [$f['id'] => 5]])
            ->assertUnprocessable()->assertJsonValidationErrors("values.{$f['id']}");
    }
}
