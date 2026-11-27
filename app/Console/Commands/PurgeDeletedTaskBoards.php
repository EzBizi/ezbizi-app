<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\TaskBoard;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/** A deleted board can be restored for 30 days (Jose, 2026-11-09); after that it goes for good, with its tasks. */
class PurgeDeletedTaskBoards extends Command
{
    public const DAYS = 30;

    protected $signature = 'ezbizi:purge-deleted-task-boards';

    protected $description = 'Remove Task Boards deleted more than 30 days ago, with their tasks.';

    public function handle(TenantContext $tenants): int
    {
        $gone = 0;
        Organization::query()->each(function (Organization $org) use ($tenants, &$gone) {
            $tenants->run($org, function () use (&$gone) {
                TaskBoard::query()->onlyTrashed()
                    ->where('deleted_at', '<', now()->subDays(self::DAYS))
                    ->each(function (TaskBoard $b) use (&$gone) {
                        // Audit 11-27: the SQL cascade skips model events, so update files would be orphaned.
                        // Their rows and bytes go first, through the model (a refusing file stops this board).
                        $updates = \App\Models\TaskUpdate::query()->withoutGlobalScopes()
                            ->whereIn('task_id', \App\Models\Task::withTrashed()->where('board_id', $b->id)->select('id'))->select('id');
                        \App\Models\Attachment::query()->where('attachable_type', 'task_updates')->whereIn('attachable_id', $updates)
                            ->each(function (\App\Models\Attachment $a) {
                                $a->delete();
                                if ($a->disk && $a->path) {
                                    \Illuminate\Support\Facades\Storage::disk($a->disk)->delete($a->path);
                                }
                            });
                        $b->forceDelete(); // its tasks, columns and groups cascade
                        $gone++;
                    });
            });
        });
        $this->info("Purged {$gone} board(s).");

        return self::SUCCESS;
    }
}
