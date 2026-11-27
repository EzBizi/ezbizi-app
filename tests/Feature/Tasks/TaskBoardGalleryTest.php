<?php

namespace Tests\Feature\Tasks;

use App\Http\Controllers\Api\TaskBoardGalleryController;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Every file on a board, for the Gallery view (Jose, 2026-11-12). */
class TaskBoardGalleryTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private TaskBoard $board;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->tenant = $this->provisionTenant('Alpha');
        $this->board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $this->tenant['franchise']->id)->first());
    }

    private function task(string $title, ?string $parent = null): string
    {
        return $this->signedInAs($this->tenant['admin'])->postJson('/api/v1/tasks',
            ['board_id' => $this->board->id, 'title' => $title] + ($parent ? ['parent_task_id' => $parent] : []))
            ->assertCreated()->json('data.id');
    }

    private function upload(string $task, array $files): void
    {
        $this->signedInAs($this->tenant['admin'])->post("/api/v1/tasks/{$task}/updates", ['files' => $files], ['Accept' => 'application/json'])->assertCreated();
    }

    private function gallery(array $q = [])
    {
        return $this->signedInAs($this->tenant['admin'])->getJson("/api/v1/task-boards/{$this->board->id}/attachments?".http_build_query($q));
    }

    public function test_lists_task_and_subtask_files_with_their_task_and_filters_by_type(): void
    {
        $parent = $this->task('Roof job');
        $sub = $this->task('Flashing', $parent);
        $this->upload($parent, [UploadedFile::fake()->image('roof.jpg'), UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')]);
        $this->upload($sub, [UploadedFile::fake()->create('walk.mp4', 10, 'video/mp4')]);

        $all = $this->gallery()->assertOk()->json();
        $this->assertCount(3, $all['data']);
        $this->assertEqualsCanonicalizing(['Roof job', 'Flashing'], array_unique(array_column(array_column($all['data'], 'task'), 'name')));
        $this->assertSame([['id' => $this->tenant['admin']->id, 'name' => $this->tenant['admin']->name]], $all['people']);

        $photos = $this->gallery(['type' => 'photo'])->json('data');
        $this->assertSame(['roof.jpg'], array_column($photos, 'name'));
        $this->assertNotNull($photos[0]['thumbnail_url']);
        $this->assertSame('photo', $photos[0]['kind']);
        $this->assertSame(['walk.mp4'], array_column($this->gallery(['type' => 'video'])->json('data'), 'name'));
        $this->assertSame(['quote.pdf'], array_column($this->gallery(['type' => 'file'])->json('data'), 'name'));
    }

    public function test_pages_with_a_cursor_and_constant_queries(): void
    {
        $task = $this->task('Many');
        $n = TaskBoardGalleryController::PAGE + 5;
        for ($i = 0; $i < $n; $i += 10) {
            $this->upload($task, array_map(fn ($j) => UploadedFile::fake()->image("p{$i}_{$j}.jpg"), range(1, min(10, $n - $i))));
        }

        DB::enableQueryLog();
        $first = $this->gallery()->assertOk()->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertCount(TaskBoardGalleryController::PAGE, $first['data']);
        $this->assertNotNull($first['next_cursor']);
        $this->assertLessThan(30, $queries);

        $second = $this->gallery(['cursor' => $first['next_cursor']])->assertOk()->json();
        $this->assertCount(5, $second['data']);
        $this->assertNull($second['next_cursor']);
        $this->assertEmpty(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')));
    }

    public function test_someone_who_cannot_see_the_board_gets_nothing(): void
    {
        $this->upload($this->task('Secret'), [UploadedFile::fake()->image('a.jpg')]);
        $outsider = $this->userWithRole($this->tenant, 'Nobody', []);
        $res = $this->signedInAs($outsider)->getJson("/api/v1/task-boards/{$this->board->id}/attachments");
        $this->assertContains($res->status(), [403, 404]);
    }
}
