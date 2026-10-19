<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Models\User;
use App\Models\WhatsAppNumber;
use App\Support\Social\WhatsAppStaffBot;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** A bill photo sent to the staff line, read and held for a YES (WhatsAppBills). */
class ReadWhatsAppBill implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would read twice

    // BillReader gives Grok up to 120 s. ponytail: above the database queue's
    // 90 s retry_after, so a read slower than 90 s also leaves a failed_jobs
    // row (tries = 1, never a second read); a long-running queue if that shows up.
    public int $timeout = 150;

    public function __construct(public string $numberId, public string $to, public string $viewerId, public string $mediaId, public ?string $caption) {}

    public function handle(WhatsAppStaffBot $bot, TenantContext $tenant): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), fn () => $bot->billRead(
            $number, $this->to, User::query()->withoutGlobalScopes()->findOrFail($this->viewerId), $this->mediaId, $this->caption,
        ));
    }
}
