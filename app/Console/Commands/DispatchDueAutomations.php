<?php

namespace App\Console\Commands;

use App\Models\Automation;
use App\Support\Automations\AutomationSchedule;
use App\Support\Automations\EventEmitter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Raise `automation.schedule_reached` for rules whose time has come (§5.5).
 *
 * ── This raises an EVENT; it does not run anything ───────────────
 *
 * The temptation is to execute the rule here, since the sweep already has it in
 * hand. That would be a second execution path beside `AutomationEngine`, and
 * everything the engine carries would have to be rebuilt inside it: the outbox,
 * the run log, the condition trace, the causation chain, the depth cap, the
 * cascade budget, the retry backoff, the auto-disable after repeated failure.
 * Two paths that must agree eventually disagree, and the one that drifts is the
 * one nobody is looking at.
 *
 * So a schedule coming round is an event like any other. Everything downstream
 * of it is code that already exists and is already tested.
 *
 * ── Advancing the clock IS the claim ─────────────────────────────
 *
 * The advance is a conditional update against the value that was read, inside
 * the transaction that writes the event. Two overlapping sweeps cannot both
 * fire a rule: the second one's update matches no row, so it emits nothing. No
 * lease column and nothing to expire — the row's own next_run_at is the token.
 *
 * ── Late once, never twice ───────────────────────────────────────
 *
 * The next run is computed strictly after NOW rather than after the time that
 * was missed, so a worker that was down for six hours finds one due rule and
 * advances past the five occurrences nobody was there for. A tenant who wanted
 * a daily report gets today's, late — not six identical ones at once, which is
 * how an outage becomes an incident.
 */
class DispatchDueAutomations extends Command
{
    protected $signature = 'ezbizi:dispatch-due-automations {--limit=200}';

    protected $description = 'Raise automation.schedule_reached for scheduled rules that are due';

    public function handle(TenantContext $tenant, EventEmitter $events): int
    {
        $now = now();

        $due = Automation::withoutGlobalScopes()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->where('is_active', true)
            ->whereNull('disabled_at')
            ->whereNull('deleted_at')
            ->orderBy('next_run_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $raised = 0;

        foreach ($due as $automation) {
            try {
                $raised += $this->fire($automation, $now, $tenant, $events);
            } catch (Throwable $e) {
                /*
                 * One tenant's broken schedule must not stop everybody else's.
                 *
                 * The row is left alone deliberately — not advanced, not
                 * disabled. Advancing would hide the fault by moving the rule
                 * on as though it had run, and this sweep runs every minute, so
                 * a genuinely broken schedule reports itself once a minute in
                 * the log rather than once and never again.
                 */
                $this->error("Automation {$automation->getKey()}: {$e->getMessage()}");
            }
        }

        $this->info("Raised {$raised} scheduled automation event(s).");

        return self::SUCCESS;
    }

    private function fire(
        Automation $automation,
        Carbon $now,
        TenantContext $tenant,
        EventEmitter $events,
    ): int {
        $schedule = new AutomationSchedule($automation->schedule ?? []);

        // Strictly after NOW, not after the run that was missed. See the class
        // docblock: this is what makes an outage cost one late run rather than
        // one run per minute it was down.
        $next = $schedule->nextAfter($now);

        $organization = $automation->organization;

        if ($organization === null) {
            throw new \RuntimeException('the rule has no organization, so nothing can be emitted in its name');
        }

        return $tenant->run($organization, function () use ($automation, $next, $events, $now) {
            return DB::transaction(function () use ($automation, $next, $events, $now) {
                $claimed = Automation::withoutGlobalScopes()
                    ->whereKey($automation->getKey())
                    // The value we read. A second sweep that already advanced
                    // it matches nothing here and emits nothing.
                    ->where('next_run_at', $automation->next_run_at)
                    ->update(['next_run_at' => $next]);

                if ($claimed !== 1) {
                    return 0;
                }

                $events->emit(
                    'automation.schedule_reached',
                    // The rule is its own subject, so a scheduled notify reaches
                    // the rule's own scope instead of every active user. See
                    // Automation::automationScope.
                    $automation,
                    [
                        // Flat, and readable by a condition: ConditionEvaluator
                        // resolves payload['after'] first and then the top level,
                        // so `due_at` and `schedule_kind` can both be tested.
                        'due_at'        => $automation->next_run_at?->toIso8601String(),
                        'ran_at'        => $now->toIso8601String(),
                        'next_run_at'   => $next->toIso8601String(),
                        'schedule_kind' => $automation->schedule['kind'] ?? null,
                        'timezone'      => $automation->schedule['timezone'] ?? null,
                    ],
                );

                return 1;
            });
        });
    }
}
