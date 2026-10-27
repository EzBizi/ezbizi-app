<?php

namespace Tests\Feature\Tasks;

use App\Models\Attachment;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Files on task updates: upload, list, open in place, download (Jose, 2026-10-27). */
class TaskUpdateAttachmentsTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    private array $tenant;

    private string $taskId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->tenant = $this->provisionTenant('Alpha');
        $board = $this->inTenant($this->tenant['org'], fn () => TaskBoard::query()
            ->where('franchise_id', $this->tenant['franchise']->id)->first());
        $this->taskId = $this->signedInAs($this->tenant['admin'])
            ->postJson('/api/v1/tasks', ['board_id' => $board->id, 'title' => 'Chimney photos'])
            ->assertCreated()->json('data.id');
    }

    private function postUpdate(array $data)
    {
        return $this->signedInAs($this->tenant['admin'])->post("/api/v1/tasks/{$this->taskId}/updates", $data, ['Accept' => 'application/json']);
    }

    public function test_an_update_carries_files_and_lists_them(): void
    {
        $created = $this->postUpdate([
            'body' => 'Before and after',
            'files' => [UploadedFile::fake()->image('before.jpg'), UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf')],
        ])->assertCreated()->json('data');

        $this->assertSame('Before and after', $created['body']);
        $this->assertCount(2, $created['attachments']);

        $update = $this->signedInAs($this->tenant['admin'])->getJson("/api/v1/tasks/{$this->taskId}")
            ->assertOk()->json('data.updates.0');
        $this->assertSame(['before.jpg', 'quote.pdf'], array_column($update['attachments'], 'name'));
        $image = $update['attachments'][0];
        $this->assertSame('image/jpeg', $image['mime']);
        $this->assertGreaterThan(0, $image['size']);
        $this->assertStringEndsWith("/api/v1/attachments/{$image['id']}", $image['url']);
        $this->assertSame($image['url'].'?inline=1', $image['thumbnail_url']);
        $this->assertNull($update['attachments'][1]['thumbnail_url']);
        $this->assertSame($update['attachments'][1]['url'].'?inline=1', $update['attachments'][1]['inline_url']);

        $row = $this->inTenant($this->tenant['org'], fn () => Attachment::query()->findOrFail($image['id']));
        $this->assertSame('task_updates', $row->attachable_type);
        Storage::disk($row->disk)->assertExists($row->path);
    }

    public function test_download_is_for_board_viewers_and_opens_inline_only_when_asked(): void
    {
        $id = $this->postUpdate(['files' => [UploadedFile::fake()->image('roof.png')]])->assertCreated()->json('data.attachments.0.id');

        $viewer = $this->userWithRole($this->tenant, 'Viewer', ['tasks.view']);
        $download = $this->signedInAs($viewer)->get("/api/v1/attachments/{$id}")->assertOk();
        $this->assertStringStartsWith('attachment', $download->headers->get('Content-Disposition'));
        $inline = $this->signedInAs($viewer)->get("/api/v1/attachments/{$id}?inline=1")->assertOk();
        $this->assertStringStartsWith('inline', $inline->headers->get('Content-Disposition'));

        $outsider = $this->userWithRole($this->tenant, 'Nobody', []);
        $this->signedInAs($outsider)->getJson("/api/v1/attachments/{$id}")->assertNotFound(); // the board hides itself

        // A viewer cannot add files.
        $this->signedInAs($viewer)->post("/api/v1/tasks/{$this->taskId}/updates",
            ['files' => [UploadedFile::fake()->image('x.png')]], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_text_file_never_opens_inline(): void
    {
        $id = $this->postUpdate(['files' => [UploadedFile::fake()->createWithContent('notes.txt', 'hello')]])
            ->assertCreated()->json('data.attachments.0.id');
        $res = $this->signedInAs($this->tenant['admin'])->get("/api/v1/attachments/{$id}?inline=1")->assertOk();
        $this->assertStringStartsWith('attachment', $res->headers->get('Content-Disposition'));
    }

    public function test_a_body_less_update_needs_a_file(): void
    {
        $this->postUpdate(['files' => [UploadedFile::fake()->image('a.jpg')]])->assertCreated()->assertJsonPath('data.body', '');
        $this->postUpdate([])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->postUpdate(['body' => '   '])->assertStatus(422)->assertJsonValidationErrors('body');
    }

    public function test_too_big_too_many_and_wrong_type_are_refused(): void
    {
        $this->postUpdate(['files' => [UploadedFile::fake()->create('big.pdf', 25601, 'application/pdf')]])
            ->assertStatus(422)->assertJsonValidationErrors('files.0');
        $this->postUpdate(['files' => array_map(fn ($i) => UploadedFile::fake()->image("p$i.jpg"), range(1, 11))])
            ->assertStatus(422)->assertJsonValidationErrors('files');
        $this->postUpdate(['files' => [UploadedFile::fake()->createWithContent('x.html', '<script>1</script>')]])
            ->assertStatus(422)->assertJsonValidationErrors('files.0');
        $this->assertSame(0, $this->inTenant($this->tenant['org'], fn () => Attachment::query()->count()));
    }

    public function test_deleting_the_task_keeps_its_files(): void
    {
        $id = $this->postUpdate(['files' => [UploadedFile::fake()->image('keep.jpg')]])->assertCreated()->json('data.attachments.0.id');
        $this->signedInAs($this->tenant['admin'])->deleteJson("/api/v1/tasks/{$this->taskId}")->assertNoContent();
        $row = $this->inTenant($this->tenant['org'], fn () => Attachment::query()->find($id));
        $this->assertNotNull($row);
        Storage::disk($row->disk)->assertExists($row->path);
    }
}
