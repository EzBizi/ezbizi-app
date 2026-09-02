<?php

namespace App\Console\Commands;

use App\Support\Monitoring\DevicePruner;
use Illuminate\Console\Command;

final class PruneDevices extends Command
{
    protected $signature = 'ezbizi:prune-devices';

    protected $description = 'Remove monitored devices (and their screenshots, JPEGs, live frame and activity) silent past DevicePruner::RETENTION_DAYS';

    public function handle(DevicePruner $pruner): int
    {
        $this->info("Pruned {$pruner->prune()} device(s).");

        return self::SUCCESS;
    }
}
