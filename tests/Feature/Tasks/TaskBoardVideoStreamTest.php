<?php

namespace Tests\Feature\Tasks;

use App\Http\Controllers\Api\AttachmentStreamController;
use App\Jobs\MakeVideoPoster;
use App\Models\Attachment;
use App\Models\TaskBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** Gallery videos play through a short signed link; posters are queued (Jose, 2026-11-12). */
class TaskBoardVideoStreamTest extends TestCase
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

    private function video(): array
    {
        $task = $this->signedInAs($this->tenant['admin'])->postJson('/api/v1/tasks', ['board_id' => $this->board->id, 'title' => 'Walk'])
            ->assertCreated()->json('data.id');
        $this->signedInAs($this->tenant['admin'])->post("/api/v1/tasks/{$task}/updates",
            ['files' => [UploadedFile::fake()->create('walk.mp4', 10, 'video/mp4')]],
            ['Accept' => 'application/json'])->assertCreated();
        // A fake upload stores no bytes; give the video some so ranges mean something.
        $a = Attachment::withoutGlobalScopes()->where('mime', 'like', 'video/%')->firstOrFail();
        Storage::disk($a->disk ?? config('filesystems.default'))->put($a->path, str_repeat('0123456789', 100));

        $item = $this->signedInAs($this->tenant['admin'])->getJson("/api/v1/task-boards/{$this->board->id}/attachments?type=video")
            ->assertOk()->json('data.0');
        $this->app['auth']->forgetGuards();

        return $item;
    }

    public function test_signed_link_streams_without_auth_with_ranges_and_expires(): void
    {
        Queue::fake();
        $item = $this->video();
        $this->assertNotNull($item['stream_url']);
        $this->assertStringContainsString('signature=', $item['stream_url']);

        $this->get($item['stream_url'])->assertOk();
        $this->get($item['stream_url'], ['Range' => 'bytes=0-9'])->assertStatus(206);

        $this->travel(AttachmentStreamController::MINUTES + 1)->minutes();
        $this->get($item['stream_url'])->assertForbidden();
    }

    public function test_link_for_a_user_without_board_access_is_refused(): void
    {
        Queue::fake();
        $this->video();
        $a = Attachment::withoutGlobalScopes()->where('mime', 'like', 'video/%')->firstOrFail();
        $outsider = $this->userWithRole($this->tenant, 'Nobody', []);

        $this->get(AttachmentStreamController::url($a, $outsider))->assertNotFound();
        $this->get(AttachmentStreamController::url($a, $this->tenant['admin']).'x')->assertForbidden();
    }

    public function test_a_video_queues_a_poster_and_the_job_runs_ffmpeg(): void
    {
        Queue::fake();
        $this->video();
        Queue::assertPushed(MakeVideoPoster::class, 1);

        Process::fake();
        $a = Attachment::withoutGlobalScopes()->where('mime', 'like', 'video/%')->firstOrFail();
        (new MakeVideoPoster($a->id))->handle();

        if ((new \Symfony\Component\Process\ExecutableFinder)->find('ffmpeg') === null) {
            Process::assertNothingRan();
        } else {
            Process::assertRan(fn ($p) => in_array('-frames:v', $p->command, true));
        }
    }
}
