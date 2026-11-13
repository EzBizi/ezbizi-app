<?php

namespace Tests\Feature\Tasks;

use App\Http\Controllers\Api\TasksController;
use App\Models\SocialConnection;
use App\Models\Task;
use App\Models\TaskBoard;
use App\Models\TaskColumn;
use App\Models\TaskColumnValue;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsTenants;
use Tests\Concerns\FakesMonday;
use Tests\TestCase;

/**
 * Every Monday column type lands as its Task Board type, with Monday's labels
 * and colours (desk, 2026-11-13: status/label, dropdown, checkbox, person,
 * link, numbers and formula had landed NULL or as text; Jose: "as we have it
 * on Monday"). The re-run on boards imported before fills what is blank and
 * turns an earlier text column into its Monday type, values carried.
 */
class MondayImportTypesTest extends TestCase
{
    use BuildsTenants;
    use FakesMonday;
    use RefreshDatabase;

    private array $tenant;

    private User $jose;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-13 11:00:00');
        $this->tenant = $this->provisionTenant('Alpha');
        $this->jose = $this->userWithRole($this->tenant, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards'], 'jose@ezbizi.test');
        $this->inTenant($this->tenant['org'], fn () => $this->jose->forceFill(['name' => 'Jose Luis Demasi'])->save());
        $this->monday['users'] = [['id' => '1', 'name' => 'Jose Demasi', 'email' => 'jose@a-1chimney.com']];
    }

    /** The import as the desk ran it on 11-12: every column as text. */
    private function importOld(array $columns): void
    {
        $file = tempnam(sys_get_temp_dir(), 'monday');
        file_put_contents($file, json_encode(['people' => ['jose@a-1chimney.com' => 'jose@ezbizi.test'], 'users' => [], 'boards' => [[
            'monday_id' => '100', 'name' => 'Jose Demasi', 'owner_email' => 'jose@a-1chimney.com', 'groups' => [], 'items' => [[
                'monday_id' => '11', 'name' => 'Order the liner', 'group' => 'Open Tasks', 'created_at' => '2026-11-01T09:00:00Z',
                'updated_at' => '2026-11-12T10:00:00Z', 'creator_email' => 'jose@a-1chimney.com', 'status' => 'Working on it', 'done' => false,
                'people' => ['jose@a-1chimney.com'], 'columns' => $columns, 'subitems' => [], 'updates' => [],
            ]],
        ]]]));
        $this->artisan('ezbizi:tasks-import-monday', ['file' => $file, '--org' => $this->tenant['org']->id, '--as' => 'jose@a-1chimney.com'])->assertSuccessful();
        @unlink($file);
    }

    private function connect(): void
    {
        $this->inTenant($this->tenant['org'], function () {
            $c = new SocialConnection;
            $c->forceFill([
                'organization_id' => $this->tenant['org']->id, 'scope_type' => SocialConnection::SCOPE_ORGANIZATION,
                'scope_id' => $this->tenant['org']->id, 'provider' => 'monday', 'remote_id' => 'acc1', 'display_name' => 'A-1 Group',
                'setup' => ['monday' => ['as' => $this->jose->id]],
            ]);
            $c->connect(['access_token' => 'monday-token', 'refresh_token' => null, 'scopes' => []]);
            $c->save();
        });
        $this->fakeMonday();
    }

    /** Jose's Monday board with one column of every type the boards use. */
    private function board(string $updatedAt): array
    {
        $board = $this->mondayBoard('100', 'Jose Demasi', []);
        $board['groups'] = [
            ['id' => 'done', 'title' => 'Completed Tasks', 'color' => '#037f4c', 'position' => '2'],
            ['id' => 'topics', 'title' => 'Open Tasks', 'color' => '#579bfc', 'position' => '1'],
        ];
        $board['columns'][2]['settings_str'] = json_encode([
            'labels' => ['0' => 'Working on it', '1' => 'Done', '2' => 'Stuck', '5' => 'Waiting Parts'],
            'labels_colors' => ['0' => ['color' => '#fdab3d'], '1' => ['color' => '#00c875'], '2' => ['color' => '#df2f4a'], '5' => ['color' => '#9d50dd']],
            'labels_positions_v2' => ['0' => 1, '1' => 3, '2' => 2, '5' => 0], 'done_colors' => [1],
        ]);
        $board['views'] = [['id' => 'v1', 'name' => 'Table', 'type' => 'TableBoardView'], ['id' => 'v2', 'name' => 'Kanban', 'type' => null],
            ['id' => 'v3', 'name' => 'Table', 'type' => 'TableBoardView']];
        array_push($board['columns'],
            ['id' => 'color_area', 'title' => 'Area', 'type' => 'status', 'settings_str' => json_encode([
                'labels' => ['0' => 'Connecticut', '1' => 'Ohio'], 'labels_colors' => ['0' => ['color' => '#ff642e'], '1' => ['color' => '#00c875']],
            ])],
            ['id' => 'dropdown1', 'title' => 'Skills', 'type' => 'dropdown', 'settings_str' => json_encode(['labels' => [['id' => 1, 'name' => 'Liners'], ['id' => 2, 'name' => 'Caps']]])],
            ['id' => 'boolean1', 'title' => 'Production job', 'type' => 'checkbox', 'settings_str' => '{}'],
            ['id' => 'numeric1', 'title' => 'Job Cost', 'type' => 'numbers', 'settings_str' => json_encode(['unit' => ['symbol' => '$', 'custom_unit' => '', 'direction' => 'left']])],
            ['id' => 'link1', 'title' => 'Link', 'type' => 'link', 'settings_str' => '{}'],
            ['id' => 'formula1', 'title' => 'Total Cost', 'type' => 'formula', 'settings_str' => json_encode(['formula' => '{numeric1} * 1.1'])],
            ['id' => 'people2', 'title' => 'Helper', 'type' => 'people', 'settings_str' => '{}'],
            ['id' => 'long1', 'title' => 'Notes', 'type' => 'long_text', 'settings_str' => '{}'],
            ['id' => 'file1', 'title' => 'Files', 'type' => 'file', 'settings_str' => '{}'],
            ['id' => 'creation1', 'title' => 'Creation log', 'type' => 'creation_log', 'settings_str' => '{}'],
        );
        $item = $this->mondayItem('11', 'Order the liner', $updatedAt);
        array_push($item['column_values'],
            ['id' => 'color_area', 'type' => 'status', 'text' => 'Ohio', 'label' => 'Ohio', 'index' => 1, 'value' => '{"index":1}'],
            ['id' => 'dropdown1', 'type' => 'dropdown', 'text' => 'Liners, Caps', 'value' => null, 'values' => [['id' => '1', 'label' => 'Liners'], ['id' => '2', 'label' => 'Caps']]],
            ['id' => 'boolean1', 'type' => 'checkbox', 'text' => '', 'value' => '{"checked":"true"}', 'checked' => true],
            ['id' => 'numeric1', 'type' => 'numbers', 'text' => '1250', 'value' => '"1250"'],
            ['id' => 'link1', 'type' => 'link', 'text' => 'Quote - https://example.com/q', 'value' => null, 'url' => 'https://example.com/q', 'url_text' => 'Quote'],
            ['id' => 'formula1', 'type' => 'formula', 'text' => '', 'value' => null, 'display_value' => '1375'],
            ['id' => 'people2', 'type' => 'people', 'text' => 'Jose Demasi', 'value' => null, 'persons_and_teams' => [['id' => '1', 'kind' => 'person']]],
            ['id' => 'long1', 'type' => 'long_text', 'text' => "Two lines\nof notes", 'value' => null],
            ['id' => 'file1', 'type' => 'file', 'text' => '', 'value' => null, 'files' => []],
            ['id' => 'creation1', 'type' => 'creation_log', 'text' => '2026-11-01', 'value' => null],
        );
        $board['items'] = [$item + ['_board' => '100']];

        return $board;
    }

    private function values(): array
    {
        return $this->inTenant($this->tenant['org'], function () {
            $task = Task::query()->where('external_ref', 'monday:11')->sole();

            return TaskColumnValue::query()->with('column')->where('task_id', $task->id)->get()
                ->mapWithKeys(fn (TaskColumnValue $v) => [$v->column->name => TasksController::valueOf($v)])->all();
        });
    }

    private function column(string $name): TaskColumn
    {
        return $this->inTenant($this->tenant['org'], fn () => TaskColumn::query()->where('name', $name)->sole());
    }

    private function optionId(TaskColumn $column, string $label): ?string
    {
        return collect($column->options())->firstWhere('label', $label)['id'] ?? null;
    }

    public function test_every_monday_type_lands_as_its_task_board_type_with_mondays_labels_and_colours(): void
    {
        $this->importOld([]);
        $this->connect();
        $this->monday['boards'] = ['100' => $this->board('2026-11-13T10:30:00Z')];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $types = $this->inTenant($this->tenant['org'], fn () => TaskColumn::query()->orderBy('position')->pluck('type', 'name')->all());
        $this->assertSame([
            'Area' => 'status', 'Skills' => 'tags', 'Production job' => 'checkbox', 'Job Cost' => 'currency', 'Link' => 'url',
            'Total Cost' => 'text', 'Helper' => 'person', 'Notes' => 'long_text',
        ], $types, 'Monday order; no column for name, status, Person, Due Date, files or the creation log');

        $area = $this->column('Area');
        $this->assertSame([['monday-0', 'Connecticut', '#ff642e'], ['monday-1', 'Ohio', '#00c875']],
            array_map(fn ($o) => [$o['id'], $o['label'], $o['colour']], $area->options()));
        $skills = $this->column('Skills');
        $this->assertSame('{numeric1} * 1.1', $this->column('Total Cost')->settings['monday_formula']);

        $this->assertSame([
            'Area' => 'monday-1',
            'Skills' => [$this->optionId($skills, 'Liners'), $this->optionId($skills, 'Caps')],
            'Production job' => true,
            'Job Cost' => 1250.0,
            'Link' => 'https://example.com/q',
            'Total Cost' => '1375',
            'Helper' => $this->jose->id,
            'Notes' => "Two lines\nof notes",
        ], array_intersect_key($this->values(), array_flip(['Area', 'Skills', 'Production job', 'Job Cost', 'Link', 'Total Cost', 'Helper', 'Notes'])));

        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()->where('external_ref', 'monday:100')->sole());
        $statuses = $this->inTenant($this->tenant['org'], fn () => TaskStatus::query()->where('board_id', $board->id)->get()->keyBy('name'));
        $this->assertSame('#9d50dd', $statuses['Waiting Parts']->colour, 'a Monday label the board lacked arrives with its colour');
        $this->assertFalse($statuses['Waiting Parts']->is_done);
        $groups = $this->inTenant($this->tenant['org'], fn () => $board->groups()->orderBy('position')->get()->map(fn ($g) => [$g->name, $g->colour])->all());
        $this->assertSame([['Open Tasks', '#579bfc'], ['Completed Tasks', '#037f4c']], $groups,
            'Monday\'s groups in Monday\'s order and colours; the empty starter groups Monday does not have are gone');

        $views = fn () => $this->inTenant($this->tenant['org'], fn () => \App\Models\TaskBoardView::query()->where('board_id', $board->id)->whereNull('user_id')
            ->orderBy('position')->get()->map(fn ($v) => [$v->name, $v->config['layout']])->all());
        $this->assertSame([['Table', 'table'], ['Kanban', 'kanban'], ['Table 2', 'table']], $views(),
            'Monday\'s named views, shared on the board, with their layout; a repeated name is numbered');
        $this->artisan('ezbizi:monday-sync --full')->assertSuccessful();
        $this->assertCount(3, $views(), 'once each');
    }

    public function test_the_rerun_turns_an_old_text_column_into_its_type_and_fills_blanks_without_overwriting(): void
    {
        // 11-12: Area came over as text "Ohio"; Job Cost was typed by hand here since.
        $this->importOld(['Area' => 'Ohio', 'Job Cost' => '999']);
        $this->connect();
        // Monday has not changed the item since: the re-run still fills what is blank.
        $this->monday['boards'] = ['100' => $this->board('2026-11-12T10:00:00Z')];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $area = $this->column('Area');
        $this->assertSame('status', $area->type);
        $ohio = collect($area->options())->firstWhere('label', 'Ohio');
        $this->assertSame('#00c875', $ohio['colour'], 'the carried label takes Monday\'s colour');
        $this->assertNotNull(collect($area->options())->firstWhere('label', 'Connecticut'), 'Monday\'s other labels are there to pick');

        $values = $this->values();
        $this->assertSame($ohio['id'], $values['Area']);
        $this->assertSame('currency', $this->column('Job Cost')->type);
        $this->assertSame(999.0, $values['Job Cost'], 'a value already here is never overwritten');
        $this->assertTrue($values['Production job']);
        $this->assertSame($this->jose->id, $values['Helper']);

        // Twice is once.
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $this->assertSame($values, $this->values());
    }
}
