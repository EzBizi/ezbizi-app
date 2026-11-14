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
            ['id' => 'location1', 'title' => 'Place', 'type' => 'location', 'settings_str' => '{}'],
            ['id' => 'rating1', 'title' => 'Stars', 'type' => 'rating', 'settings_str' => '{"limit":5}'],
            ['id' => 'connect1', 'title' => 'Related', 'type' => 'board_relation', 'settings_str' => '{}'],
            ['id' => 'item1', 'title' => 'Item ID', 'type' => 'item_id', 'settings_str' => '{}'],
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
            ['id' => 'location1', 'type' => 'location', 'text' => '1 Main St, Columbus', 'value' => null, 'address' => '1 Main St, Columbus', 'lat' => '39.96', 'lng' => '-83.1'],
            ['id' => 'rating1', 'type' => 'rating', 'text' => '4', 'value' => '{"rating":4}'],
            ['id' => 'connect1', 'type' => 'board_relation', 'text' => null, 'value' => null, 'display_value' => 'Order the liner', 'linked_item_ids' => ['11']],
            ['id' => 'item1', 'type' => 'item_id', 'text' => '11', 'value' => null],
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
            'Total Cost' => 'formula', 'Helper' => 'people', 'Notes' => 'long_text', 'Files' => 'files', 'Creation log' => 'creation_log',
            'Place' => 'location', 'Stars' => 'rating', 'Related' => 'connect',
        ], $types, 'Monday order; no column for name, status, Person, Due Date or item id');
        $this->assertSame('[Job Cost] * 1.1', $this->column('Total Cost')->settings['expression'], 'Monday\'s formula, its column ids as names here');
        $this->assertSame(5, $this->column('Stars')->settings['max']);

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
            'Helper' => [$this->jose->id],
            'Notes' => "Two lines\nof notes",
            'Place' => ['address' => '1 Main St, Columbus', 'lat' => 39.96, 'lng' => -83.1],
            'Stars' => 4,
            'Related' => [$this->inTenant($this->tenant['org'], fn () => Task::query()->where('external_ref', 'monday:11')->value('id'))],
        ], array_intersect_key($this->values(), array_flip(['Area', 'Skills', 'Production job', 'Job Cost', 'Link', 'Helper', 'Notes', 'Place', 'Stars', 'Related'])));
        $this->assertArrayNotHasKey('Total Cost', $this->values(), 'a formula works itself out; nothing stored');

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
        $this->assertSame([$this->jose->id], $values['Helper']);

        // Twice is once.
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $this->assertSame($values, $this->values());
    }

    /** Jose 11-13 (via desk): each board in the same folder, with Monday's folder colours. */
    public function test_the_board_lands_in_mondays_folder_with_its_colours_and_a_move_here_stays(): void
    {
        $this->importOld([]);
        $this->connect();
        $this->monday['folders'] = [
            ['id' => 'f1', 'name' => 'A1 Chimney', 'color' => 'DARK_ORANGE', 'parent' => null],
            ['id' => 'f2', 'name' => 'Marketing', 'color' => null, 'parent' => ['id' => 'f1']],
        ];
        $this->monday['boards'] = ['100' => ['folder' => 'f2'] + $this->board('2026-11-13T10:30:00Z')];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $this->artisan('ezbizi:monday-sync --full')->assertSuccessful();

        $this->inTenant($this->tenant['org'], function () {
            $folders = \App\Models\TaskBoardFolder::query()->get();
            $this->assertCount(2, $folders, 'made once');
            $root = $folders->firstWhere('name', 'A1 Chimney');
            $leaf = $folders->firstWhere('name', 'Marketing');
            $this->assertSame([null, '#ff6d3b'], [$root->parent_id, $root->colour]);
            $this->assertSame([$root->id, null], [$leaf->parent_id, $leaf->colour]);
            $board = TaskBoard::query()->where('external_ref', 'monday:100')->sole();
            $this->assertSame($leaf->id, $board->folder_id);

            // Somebody moves it here: the next sync leaves it where they put it.
            $board->forceFill(['folder_id' => $root->id])->save();
        });
        $this->artisan('ezbizi:monday-sync --full')->assertSuccessful();
        $this->inTenant($this->tenant['org'], fn () => $this->assertSame(
            \App\Models\TaskBoardFolder::query()->where('name', 'A1 Chimney')->value('id'),
            TaskBoard::query()->where('external_ref', 'monday:100')->value('folder_id')));
    }

    /**
     * Prod import, 2026-11-13: on the Project Management boards Monday's first
     * status column (id `status`) is Area, and Area's index 1 (Cincinnati) is
     * in its done_colors: Cincinnati jobs landed done. The status is Job Status.
     */
    public function test_a_board_without_a_status_column_takes_job_status_not_its_first_label_column(): void
    {
        $this->importOld([]);
        $this->connect();
        $board = $this->board('2026-11-13T10:30:00Z');
        $board['columns'] = [
            ['id' => 'name', 'title' => 'Name', 'type' => 'name', 'settings_str' => '{}'],
            ['id' => 'status', 'title' => 'Area', 'type' => 'status', 'settings_str' => json_encode(['labels' => ['0' => 'Cleveland', '1' => 'Cincinnati'], 'done_colors' => [1]])],
            ['id' => 'color_job', 'title' => 'Job Status', 'type' => 'status', 'settings_str' => json_encode(['labels' => ['1' => 'Done & Paid', '9' => 'Scheduled'], 'done_colors' => [1]])],
        ];
        $item = $board['items'][0];
        $item['column_values'] = [
            ['id' => 'status', 'type' => 'status', 'text' => 'Cincinnati', 'label' => 'Cincinnati', 'index' => 1, 'value' => '{"index":1}'],
            ['id' => 'color_job', 'type' => 'status', 'text' => 'Scheduled', 'label' => 'Scheduled', 'index' => 9, 'value' => '{"index":9}'],
        ];
        $board['items'] = [$item];
        $this->monday['boards'] = ['100' => $board];
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();

        $task = $this->inTenant($this->tenant['org'], fn () => Task::query()->with('statusRow')->where('external_ref', 'monday:11')->sole());
        $this->assertSame('Scheduled', $task->statusRow->name);
        $this->assertFalse($task->statusRow->is_done, 'Cincinnati is a place, not done');
        $this->assertSame('monday-1', $this->values()['Area'], 'Area is a label column');
        $this->assertTrue($this->inTenant($this->tenant['org'], fn () => TaskStatus::query()->where('board_id', $task->board_id)->where('name', 'Done & Paid')->value('is_done')));
    }

    /** A mapping fixed after the import: --reapply takes Monday's fields again though Monday has not changed. */
    public function test_reapply_takes_mondays_fields_again_on_tasks_already_here(): void
    {
        $this->importOld([]);
        $this->connect();
        $this->monday['boards'] = ['100' => $this->board('2026-11-12T10:00:00Z')];
        $this->inTenant($this->tenant['org'], fn () => \App\Models\Task::query()->where('external_ref', 'monday:11')
            ->update(['status_id' => TaskStatus::query()->where('name', 'Stuck')->value('id')]));
        $this->artisan('ezbizi:monday-sync')->assertSuccessful();
        $status = fn () => $this->inTenant($this->tenant['org'], fn () => Task::query()->with('statusRow')->where('external_ref', 'monday:11')->sole()->statusRow->name);
        $this->assertSame('Stuck', $status(), 'Monday unchanged: the sync leaves it');

        $map = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($map, json_encode(['100' => ['target' => 'personal', 'owner_email' => 'jose@ezbizi.test']]));
        $this->artisan('ezbizi:monday-import-boards', ['--map' => $map, '--reapply' => true])->assertSuccessful();
        @unlink($map);
        $this->assertSame('Working on it', $status(), 'reapplied from Monday');

        // Prod, 2026-11-13: PM jobs whose Job Status is blank on Monday kept the Area label they
        // were given ("Cleveland"). A blank status on Monday is "Not started" here.
        $board = $this->board('2026-11-12T10:00:00Z');
        foreach ($board['items'][0]['column_values'] as $i => $c) {
            if ($c['id'] === 'status') {
                $board['items'][0]['column_values'][$i] = ['id' => 'status', 'type' => 'status', 'text' => '', 'label' => null, 'index' => null, 'value' => null];
            }
        }
        $this->monday['boards'] = ['100' => $board];
        $map = tempnam(sys_get_temp_dir(), 'map');
        file_put_contents($map, json_encode(['100' => ['target' => 'personal', 'owner_email' => 'jose@ezbizi.test']]));
        $this->artisan('ezbizi:monday-import-boards', ['--map' => $map, '--reapply' => true])->assertSuccessful();
        @unlink($map);
        $this->assertSame('Not started', $status(), 'blank on Monday, blank here');
    }
}
