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

/** A staff parts question or rating-plate photo, answered by the Tech Assistant off the webhook. */
class AnswerWhatsAppWithTech implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would answer twice

    // A photo is read with a tool turn, so it runs on `database-long`
    // (retry_after 200) as ReadWhatsAppBill; typed words on the default
    // queue, under its 90 s retry_after as AnswerWhatsAppWithAi.
    public int $timeout = 75;

    public function __construct(public string $numberId, public string $to, public string $viewerId, public string $query, public ?string $mediaId = null)
    {
        if ($mediaId !== null) {
            $this->timeout = 150;
            $this->onConnection('database-long')->onQueue('long');
        }
    }

    public function handle(WhatsAppStaffBot $bot, TenantContext $tenant): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), fn () => $bot->techReply(
            $number, $this->to, User::query()->withoutGlobalScopes()->findOrFail($this->viewerId), $this->query, $this->mediaId,
        ));
    }
}
