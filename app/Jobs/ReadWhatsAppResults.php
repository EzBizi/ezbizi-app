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

/** A results photo (ACE) sent to the staff line, read into pending rows and held for a YES (WhatsAppResults). */
class ReadWhatsAppResults implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would read twice

    // ResultReader gives Grok up to 120 s, so this runs on `database-long`
    // (retry_after 200), never on the default queue's 90 s.
    public int $timeout = 150;

    public function __construct(public string $numberId, public string $to, public string $viewerId, public string $mediaId, public ?string $caption)
    {
        $this->onConnection('database-long')->onQueue('long');
    }

    public function handle(WhatsAppStaffBot $bot, TenantContext $tenant): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), fn () => $bot->resultsRead(
            $number, $this->to, User::query()->withoutGlobalScopes()->findOrFail($this->viewerId), $this->mediaId, $this->caption,
        ));
    }
}
