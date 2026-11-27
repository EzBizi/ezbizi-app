<?php

namespace App\Console\Commands;

use App\Support\Live\ChangeFeed;
use Illuminate\Console\Command;

/** Drops change-feed rows older than a day; an open screen polls every 10 s. */
class PruneChangeEvents extends Command
{
    protected $signature = 'ezbizi:prune-change-events';

    protected $description = 'Delete change_events rows older than one day';

    public function handle(): int
    {
        $this->info('Pruned '.ChangeFeed::prune().' change events.');

        return self::SUCCESS;
    }
}
