<?php

namespace App\Console\Commands;

use App\Jobs\MakeVideoPoster;
use App\Models\Attachment;
use Illuminate\Console\Command;

/** Queue a poster frame for every Task Board video that has none (Gallery, 2026-11-12). */
class AttachmentsVideoPosters extends Command
{
    protected $signature = 'attachments:video-posters';

    protected $description = 'Queue Gallery poster frames for existing Task Board videos';

    public function handle(): int
    {
        $n = 0;
        Attachment::withoutGlobalScopes()->where('attachable_type', 'task_updates')
            ->where('mime', 'like', 'video/%')->where('encrypted', false)
            ->select('id')->chunkById(500, function ($rows) use (&$n) {
                foreach ($rows as $row) {
                    MakeVideoPoster::dispatch($row->id);
                    $n++;
                }
            });
        $this->info("Queued {$n} videos (each skips when it already has a poster).");

        return self::SUCCESS;
    }
}
