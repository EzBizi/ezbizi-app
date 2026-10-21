<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use App\Support\Automations\EventEmitter;
use App\Support\Hr\PtoPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * The two paid time off triggers a date raises rather than a click (Jose,
 * 2026-10-21): a waiting period ending today, and a reset 30 days away.
 * Once a day, so each fires once on its day.
 */
class PtoDailyTriggers extends Command
{
    protected $signature = 'ezbizi:pto-daily-triggers';

    protected $description = 'Raise "paid time off becomes available" and "resets in 30 days"';

    public function handle(TenantContext $tenant, PtoPolicy $policy, EventEmitter $events): int
    {
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        foreach (Organization::query()->get() as $organization) {
            $tenant->run($organization, function () use ($organization, $policy, $events, $today, $soon) {
                if (! $policy->enabledFor($organization)) {
                    return;
                }
                foreach (User::query()->where('is_active', true)->get() as $person) {
                    $answer = $policy->for($person);
                    $payload = ['person' => ['id' => $person->getKey(), 'name' => $person->name],
                        'total' => $answer['total'], 'used' => $answer['used'], 'left' => $answer['left'],
                        'resets_on' => $answer['resets_on']];
                    if ($answer['eligible_from'] === $today) {
                        $events->emit('user.pto_available', $person, $payload);
                    }
                    if ($answer['resets_on'] === $soon) {
                        $events->emit('user.pto_reset_soon', $person, $payload);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
