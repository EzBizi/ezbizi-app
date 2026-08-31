<?php

namespace App\Console\Commands;

use App\Support\Monitoring\DeviceOfflineWorker;
use Illuminate\Console\Command;

final class DispatchMonitoringDeviceOffline extends Command
{
    protected $signature = 'ezbizi:dispatch-monitoring-device-offline';

    protected $description = 'Raise monitoring.device_offline for devices that stopped reporting';

    public function handle(DeviceOfflineWorker $worker): int
    {
        $this->info("Raised {$worker->sweep()} device-offline event(s).");

        return self::SUCCESS;
    }
}
