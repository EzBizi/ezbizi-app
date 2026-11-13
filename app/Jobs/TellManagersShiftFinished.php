<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\TimeClockShift;
use App\Support\Tenancy\TenantContext;
use App\Support\TimeClock\ShiftFinishedNotice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A manual-clock-out person's finished shift, WhatsApped to their branch's managers off the clock-out request. */
class TellManagersShiftFinished implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would text the managers twice

    public function __construct(public string $shiftId, public string $organizationId) {}

    public function handle(TenantContext $tenant, ShiftFinishedNotice $notice): void
    {
        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($this->organizationId), function () use ($notice) {
            $shift = TimeClockShift::query()->find($this->shiftId);
            if ($shift !== null && $shift->clocked_out_at !== null) {
                $notice->send($shift);
            }
        });
    }
}
