<?php

namespace App\Console\Commands;

use App\Models\Complaint;
use App\Models\Organization;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Escalating alerts on Priority complaints (Shoval, 2026-11-20: BBB and Attorney General answered within
 * 24 hours, 48 at most). Raises "complaint.due_alert" once per step: halfway (12 h left), due (24 h after
 * it came in) and last call (40 h, eight before the 48 h limit). Who hears is the Automations rule's.
 */
class ComplaintDueAlerts extends Command
{
    protected $signature = 'ezbizi:complaint-due-alerts';

    protected $description = 'Raise the halfway, due and last-call alerts on open Priority complaints';

    /** Step => hours after the complaint came in, for a 24 h due time. */
    public const STEPS = [1 => 12, 2 => 24, 3 => 40];

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        Organization::query()->each(fn (Organization $org) => $tenant->run($org, function () use ($events) {
            Complaint::query()->where('priority', true)->whereIn('status', Complaint::OPEN)->whereNotNull('due_at')
                ->where('alerted_level', '<', count(self::STEPS))->get()
                ->each(function (Complaint $c) use ($events) {
                    $raised = $c->due_at->copy()->subHours(24);
                    $step = collect(self::STEPS)->filter(fn ($h) => now()->gte($raised->copy()->addHours($h)))->keys()->max();
                    if ($step === null || $step <= $c->alerted_level) {
                        return;
                    }
                    $c->forceFill(['alerted_level' => $step])->save();
                    $events->emit('complaint.due_alert', $c, ['after' => [
                        'step' => $step, 'label' => [1 => 'halfway', 2 => 'due now', 3 => 'last call'][$step],
                        'hours_open' => (int) $raised->diffInHours(now()), 'kind' => $c->kind, 'title' => $c->title,
                        'due_at' => $c->due_at->toIso8601String(),
                    ]]);
                });
        }));

        return self::SUCCESS;
    }
}
