<?php

namespace App\Console\Commands;

use App\Jobs\MakeVideoPoster;
use App\Models\Attachment;
use App\Models\TaskUpdate;
use App\Support\Tasks\Monday\MondayImport;
use Illuminate\Console\Command;

/**
 * One-off (desk, 2026-11-12): the 11-12 Monday import stored Rodrigo's videos
 * as application/octet-stream, so the Gallery would not play them and no
 * poster was made. Gives the imported attachments whose extension is a
 * MondayImport::STREAMABLE video their video type back, then queues their
 * posters. Only attachments on updates that came from Monday
 * (external_ref monday:...) and are still octet-stream are touched.
 *
 * Before changing anything it writes the rows it will change (id, old mime,
 * name) to storage/app/monday/; --restore=<that file> puts them back.
 */
class MondayFixVideoMimes extends Command
{
    protected $signature = 'ezbizi:monday-fix-video-mimes {--dry-run} {--restore= : a backup file this command wrote}';

    protected $description = 'Give videos imported from Monday their video type back and queue their posters';

    public function handle(): int
    {
        if ($file = $this->option('restore')) {
            $rows = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($rows as $row) {
                Attachment::withoutGlobalScopes()->whereKey($row['id'])->update(['mime' => $row['mime']]);
            }
            $this->info('Restored '.count($rows).' attachments from '.$file);

            return self::SUCCESS;
        }

        $rows = Attachment::withoutGlobalScopes()
            ->where('attachable_type', 'task_updates')
            ->where('mime', 'application/octet-stream')
            ->whereIn('attachable_id', TaskUpdate::withoutGlobalScopes()->where('external_ref', 'like', 'monday:%')->select('id'))
            ->get(['id', 'mime', 'original_name'])
            ->filter(fn (Attachment $a) => isset(MondayImport::STREAMABLE[mb_strtolower(pathinfo((string) $a->original_name, PATHINFO_EXTENSION))]))
            ->values();

        $this->line(($this->option('dry-run') ? '[dry run] ' : '').$rows->count().' imported videos stored as octet-stream');
        if ($rows->isEmpty() || $this->option('dry-run')) {
            return self::SUCCESS;
        }

        $dir = storage_path('app/monday');
        @mkdir($dir, 0750, true);
        $backup = $dir.'/video-mimes-before-'.now()->format('YmdHis').'.json';
        file_put_contents($backup, json_encode($rows->map(fn (Attachment $a) => ['id' => $a->id, 'mime' => $a->mime, 'name' => $a->original_name])->all(), JSON_PRETTY_PRINT));
        $this->line('Backup: '.$backup);

        $queued = 0;
        foreach ($rows as $row) {
            $mime = MondayImport::STREAMABLE[mb_strtolower(pathinfo((string) $row->original_name, PATHINFO_EXTENSION))];
            Attachment::withoutGlobalScopes()->whereKey($row->id)->update(['mime' => $mime]);
            $fresh = Attachment::withoutGlobalScopes()->find($row->id);
            if ($fresh !== null && MakeVideoPoster::wants($fresh)) {
                MakeVideoPoster::dispatch($fresh->id);
                $queued++;
            }
        }
        $this->info("Fixed {$rows->count()} attachments; queued {$queued} posters.");

        return self::SUCCESS;
    }
}
