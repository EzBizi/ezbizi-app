<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Franchise;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tasks\TaskBoards;
use Illuminate\Console\Command;

/** Give every existing top-level company, franchise and person their Task Board (none for Sub Companies). Idempotent. */
class BackfillTaskBoards extends Command
{
    protected $signature = 'ezbizi:backfill-task-boards';

    protected $description = 'Create the Task Board for every company, franchise and person that lacks one';

    public function handle(TaskBoards $boards): int
    {
        $count = 0;

        foreach ([Company::class, Franchise::class, User::class] as $class) {
            // withoutGlobalScopes also drops SoftDeletes, so trashed rows are excluded by hand.
            // Skip orphans: a throw inside the migration's up() would leave it half-applied.
            $query = $class::query()->withoutGlobalScopes()->orderBy('id')
                ->whereIn('organization_id', Organization::query()->withoutGlobalScopes()->select('id'));
            if ($class !== User::class) {
                $query->whereNull('deleted_at');
            }
            $query
                ->each(function ($owner) use ($boards, &$count) {
                    if (! $owner instanceof User && ! TaskBoards::hasPlaceBoard($owner)) {
                        return; // a Sub Company and its franchises have no board
                    }
                    $boards->ensureFor($owner);
                    $count++;
                });
        }

        $this->info("Checked {$count} owners.");

        return self::SUCCESS;
    }
}
