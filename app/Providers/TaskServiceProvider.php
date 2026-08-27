<?php

namespace App\Providers;

use App\Support\Tasks\RecordLinks\CustomerTaskLink;
use App\Support\Tasks\RecordLinks\HrPersonTaskLink;
use App\Support\Tasks\RecordLinks\InspectionReportTaskLink;
use App\Support\Tasks\RecordLinks\InspectionRunTaskLink;
use App\Support\Tasks\RecordLinks\InventoryItemTaskLink;
use App\Support\Tasks\RecordLinks\JobTaskLink;
use App\Support\Tasks\RecordLinks\ServiceTaskLink;
use App\Support\Tasks\RecordLinks\VehicleTaskLink;
use App\Support\Tasks\TaskRecordLinkRegistry;
use Illuminate\Support\ServiceProvider;

final class TaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TaskRecordLinkRegistry::class, function ($app): TaskRecordLinkRegistry {
            $registry = new TaskRecordLinkRegistry;
            foreach ([
                CustomerTaskLink::class, JobTaskLink::class, VehicleTaskLink::class,
                InventoryItemTaskLink::class, InspectionReportTaskLink::class,
                InspectionRunTaskLink::class, HrPersonTaskLink::class, ServiceTaskLink::class,
            ] as $definition) {
                $registry->register($app->make($definition));
            }

            return $registry;
        });
    }
}
