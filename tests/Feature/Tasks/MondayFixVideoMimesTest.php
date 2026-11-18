<?php

namespace Tests\Feature\Tasks;

use App\Jobs\MakeVideoPoster;
use App\Models\Attachment;
use App\Models\TaskUpdate;
use App\Support\Tasks\TaskBoards;
use App\Support\Tasks\TaskWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsTenants;
use Tests\TestCase;

/** The 11-12 import's videos stored as octet-stream get their video type back, and posters (desk, 2026-11-12). */
class MondayFixVideoMimesTest extends TestCase
{
    use BuildsTenants;
    use RefreshDatabase;

    public function test_imported_videos_get_their_type_back_with_a_backup_and_posters_and_nothing_else_moves(): void
    {
        Storage::fake(config('filesystems.default'));
        $t = $this->provisionTenant('Alpha');
        $jose = $this->userWithRole($t, 'Owner', ['tasks.view', 'tasks.edit', 'tasks.create_boards'], 'jose@a-1chimney.com');

        $ids = $this->inTenant($t['org'], function () use ($t, $jose) {
            $board = app(TaskBoards::class)->ensureFor($jose);
            $task = app(TaskWriter::class)->create($board, ['title' => 'Videos'], $jose);
            $update = fn (?string $ref) => tap(TaskUpdate::query()->create(['organization_id' => $t['org']->id, 'task_id' => $task->id, 'body' => 'x']),
                fn ($u) => $u->forceFill(['external_ref' => $ref])->saveQuietly());
            $fromMonday = $update('monday:u1');
            $byHand = $update(null);
            $file = fn (TaskUpdate $u, string $name) => tap(new Attachment([
                'attachable_type' => 'task_updates', 'attachable_id' => $u->id, 'original_name' => $name,
                'mime' => 'application/octet-stream', 'uploaded_by_user_id' => $jose->id,
            ]), fn ($a) => $a->forceFill(['organization_id' => $t['org']->id, 'disk' => config('filesystems.default'), 'path' => 'attachments/'.$name, 'size_bytes' => 1, 'checksum' => 'x'])->save())->id;

            return [
                'mp4' => $file($fromMonday, 'Leads Ezbizi.mp4'),
                'mov' => $file($fromMonday, 'roof.MOV'),
                'svg' => $file($fromMonday, 'logo.svg'),
                'cr2' => $file($fromMonday, 'ZC4A0040.CR2'),
                'hand' => $file($byHand, 'mine.mp4'),
            ];
        });
        $mime = fn (string $k) => Attachment::withoutGlobalScopes()->whereKey($ids[$k])->value('mime');

        $this->artisan('ezbizi:monday-fix-video-mimes', ['--dry-run' => true])->expectsOutputToContain('2 imported videos')->assertSuccessful();
        $this->assertSame('application/octet-stream', $mime('mp4'), 'a dry run changes nothing');

        Queue::fake([MakeVideoPoster::class]);
        $this->artisan('ezbizi:monday-fix-video-mimes')->assertSuccessful();

        $this->assertSame('video/mp4', $mime('mp4'));
        $this->assertSame('video/quicktime', $mime('mov'));
        $this->assertSame('application/octet-stream', $mime('svg'), 'an svg stays a download');
        $this->assertSame('application/octet-stream', $mime('cr2'));
        $this->assertSame('application/octet-stream', $mime('hand'), 'only what came from Monday');
        Queue::assertPushed(MakeVideoPoster::class, 2);

        $backups = glob(storage_path('app/monday/video-mimes-before-*.json'));
        $this->assertNotEmpty($backups);
        $backup = end($backups);
        $this->assertCount(2, json_decode((string) file_get_contents($backup), true));

        $this->artisan('ezbizi:monday-fix-video-mimes', ['--restore' => $backup])->assertSuccessful();
        $this->assertSame('application/octet-stream', $mime('mp4'), 'the backup puts it back');
        array_map('unlink', $backups);
    }
}
