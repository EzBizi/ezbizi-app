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

/** A staff command read by Grok and held for a YES (AppAct). */
class PlanWhatsAppCommand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // a retry would ask twice

    public int $timeout = 85;   // under the queue's 90 s retry_after

    public function __construct(public string $numberId, public string $to, public string $viewerId, public string $query) {}

    public function handle(WhatsAppStaffBot $bot, TenantContext $tenant): void
    {
        $number = WhatsAppNumber::query()->withoutGlobalScopes()->findOrFail($this->numberId);

        $tenant->run(Organization::withoutGlobalScopes()->findOrFail($number->organization_id), fn () => $bot->commandPlan(
            $number, $this->to, User::query()->withoutGlobalScopes()->findOrFail($this->viewerId), $this->query,
        ));
    }
}
