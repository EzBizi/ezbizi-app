<?php

namespace Tests\Feature\Tasks;

use App\Models\Automation;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/**
 * Monday's board automations become Task Board rules (Jose, 2026-11-13: "make
 * sure we aren't missing any features from Monday specially automations").
 */
class MondayAutomationsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_each_monday_recipe_becomes_a_rule_on_its_board_once_on_or_off_as_on_monday(): void
    {
        $t = $this->provisionTenant('Alpha');
        $nehorai = $this->userWithRole($t, 'Manager', ['tasks.view', 'tasks.edit'], 'nehorai@a1.test');
        [$board, $done, $recurring, $area, $date] = $this->inTenant($t['org'], function () use ($t) {
            $board = TaskBoard::query()->where('franchise_id', $t['franchise']->id)->firstOrFail();
            $board->forceFill(['external_ref' => 'monday:500'])->save();
            $make = fn (string $name) => TaskGroup::query()->create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => $name, 'position' => 9]);

            return [$board, $make('Done'), $make('Reccuring Tasks'),
                TaskColumn::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Area', 'type' => 'status', 'position' => 1,
                    'settings' => ['options' => [['id' => 'ct', 'label' => 'Connecticut', 'colour' => null]]]]),
                TaskColumn::create(['organization_id' => $t['org']->id, 'board_id' => $board->id, 'name' => 'Date', 'type' => 'date', 'position' => 2])];
        });
        $recipe = fn (string $id, array $trigger, array $conditions, array $actions, bool $active = true) => [
            'monday_id' => $id, 'board' => '500', 'active' => $active, 'monday_state' => $active ? 'active' : 'deactivated', 'sentence' => 'recipe '.$id,
            'trigger' => $trigger, 'conditions' => $conditions, 'actions' => $actions, 'by' => ''];
        $spec = ['automations' => [
            $recipe('1', ['kind' => 'status_changed', 'to' => 'Done'], [], [['move_group' => 'Done']]),
            $recipe('2', ['kind' => 'column_changed', 'column' => 'Area', 'to' => 'Connecticut'], [], [['assign' => 'nehorai@a1.test', 'add' => true]]),
            $recipe('3', ['kind' => 'date_arrived', 'column' => 'Date', 'days_before' => 0, 'hour' => 13.0], [['group' => 'Reccuring Tasks'], ['status' => 'Monthly Task']],
                [['notify' => 'nehorai@a1.test', 'message' => 'Maintain your vehicle'], ['shift' => 'Date', 'amount' => 1, 'unit' => 'months']]),
            $recipe('4', ['kind' => 'schedule', 'every' => 'weekly', 'days' => ['monday'], 'time' => '15:00'], [], [['notify' => 'nehorai@a1.test', 'message' => 'Weekly tasks']]),
            $recipe('5', ['kind' => 'created'], [], [['assign' => 'ron@nobody.test', 'add' => true]]),
            $recipe('6', ['kind' => 'created'], [], [['set' => 'Date', 'value' => 'today']], active: false),
        ], 'skipped' => []];
        $file = tempnam(sys_get_temp_dir(), 'auto');
        file_put_contents($file, json_encode($spec));

        $this->artisan('ezbizi:monday-automations', ['--spec' => $file, '--org' => $t['org']->id, '--as' => $t['admin']->email])->assertSuccessful();
        $this->artisan('ezbizi:monday-automations', ['--spec' => $file, '--org' => $t['org']->id, '--as' => $t['admin']->email])->assertSuccessful();
        @unlink($file);

        $this->inTenant($t['org'], function () use ($board, $done, $recurring, $area, $date, $nehorai, $t) {
            $rules = Automation::query()->with('activeVersion')->get()->keyBy(fn ($r) => explode(' ', $r->description)[0]);
            $this->assertCount(6, $rules, 'once each');
            $on = fn ($k) => $rules["monday:$k"]->is_active;
            $all = fn ($k) => $rules["monday:$k"]->activeVersion->conditions['all'] ?? [];
            $acts = fn ($k) => $rules["monday:$k"]->activeVersion->actions;

            $this->assertSame('task.status_changed', $rules['monday:1']->trigger_key);
            $this->assertEquals(['field' => 'board_id', 'op' => 'eq', 'value' => $board->id], $all(1)[0], 'the Task Board\'s own rule shape');
            $this->assertEquals([['type' => 'task.move_group', 'group_id' => $done->id]], $acts(1));
            $this->assertSame(['franchise', $t['franchise']->id], [$rules['monday:1']->scope_type, $rules['monday:1']->scope_id]);

            $this->assertSame('task.column_changed', $rules['monday:2']->trigger_key);
            $this->assertTrue(in_array(['field' => 'column_id', 'op' => 'eq', 'value' => $area->id], $all(2)), 'stored JSON keys come back in any order');
            $this->assertTrue(in_array(['field' => 'value', 'op' => 'changed_to', 'value' => 'Connecticut'], $all(2)), 'stored JSON keys come back in any order');
            $this->assertEquals([['type' => 'task.assign', 'user_field' => 'user:'.$nehorai->id, 'add' => true]], $acts(2));

            $this->assertSame('task.date_arrived', $rules['monday:3']->trigger_key);
            $this->assertSame(780, $rules['monday:3']->delay_minutes, '1pm');
            $this->assertTrue(in_array(['field' => 'group_id', 'op' => 'eq', 'value' => $recurring->id], $all(3)), 'stored JSON keys come back in any order');
            $this->assertTrue(in_array(['field' => 'status', 'op' => 'eq', 'value' => 'Monthly Task'], $all(3)), 'stored JSON keys come back in any order');
            $this->assertEquals(['type' => 'task.shift_date', 'column_id' => $date->id, 'amount' => 1, 'unit' => 'months'], $acts(3)[1]);

            $this->assertSame('automation.schedule_reached', $rules['monday:4']->trigger_key);
            $this->assertSame(['monday'], $rules['monday:4']->schedule['days']);
            $this->assertNotNull($rules['monday:4']->next_run_at);

            $this->assertTrue($on(1) && $on(2) && $on(3) && $on(4));
            $this->assertFalse($on(5), 'a person with no account here: made off');
            $this->assertStringContainsString('not here: person ron@nobody.test', $rules['monday:5']->description);
            $this->assertFalse($on(6), 'off on Monday, off here');
        });
    }
}
