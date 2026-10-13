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

/**
 * A WhatsApp question the Library's words did not match, answered by Grok from
 * the Library off the webhook (Jose, 2026-10-12: "Grok on WhatsApp bot too").
 */
class AnswerWhatsAppWithAi implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would answer twice

    public int $timeout = 330;

    public function __construct(
        public string $numberId,
        public string $to,
        public ?string $viewerId,
        public string $query,
    ) {}

    public function handle(WhatsAppStaffBot $bot, TenantContext $tenant): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), fn () => $bot->aiReply(
            $number,
            $this->to,
            $this->viewerId ? User::query()->withoutGlobalScopes()->find($this->viewerId) : null,
            $this->query,
        ));
    }
}
