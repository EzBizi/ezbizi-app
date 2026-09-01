<?php

namespace App\Console\Commands;

use App\Support\Monitoring\ScreenshotPruner;
use Illuminate\Console\Command;

final class PruneScreenshots extends Command
{
    protected $signature = 'ezbizi:prune-screenshots';

    protected $description = 'Remove monitoring screenshots (and their JPEGs) past MonitoringScreenshot::RETENTION_DAYS';

    public function handle(ScreenshotPruner $pruner): int
    {
        $this->info("Pruned {$pruner->prune()} screenshot(s).");

        return self::SUCCESS;
    }
}
