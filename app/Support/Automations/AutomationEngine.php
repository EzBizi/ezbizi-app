<?php

namespace App\Support\Automations;

use App\Models\Automation;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\AutomationVersion;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Delivering one outbox event to whatever rules care about it (plan §5.5).
 *
 * Runs on a queue, never inline: §5.5 is explicit that a slow external webhook
 * must not delay a user's save. By the time this executes, the business change
 * has already committed.
 */
class AutomationEngine
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ConditionEvaluator $conditions,
        private readonly ActionRunner $actions,
        private readonly AuditLogger $audit,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * @return int how many automations acted on it
     */
    public function deliver(AutomationEvent $event): int
    {
        $organization = $event->organization()->withoutGlobalScopes()->first();
        if ($organization === null) {
            return 0;
        }

        return $this->tenant->run($organization, function () use ($event, $organization) {
            /*
             * EVERY TIME VALUE IN THIS CLASS IS A DURATION, AND THAT IS WHAT
             * KEEPS IT OUT OF THE DAYLIGHT-SAVING PROBLEM.
             *
             * The lease below, the retry backoff, and `available_at` are all
             * `now()` plus an elapsed offset. A duration means the same thing
             * either side of a clock change; a WALL-CLOCK time does not. So
             * this engine has no timezone in it at all — grep it, there is not
             * one — and that is a property to keep rather than a gap to fill.
             *
             * It matters because the organization timezone defaults to
             * `Etc/GMT+5`, a FIXED OFFSET with no daylight saving, and says so
             * in its own help text. That is a deliberate product decision, not
             * a bug — but it means anything expressed as "9am daily" resolved
             * through that setting runs an hour off local civil time from March
             * to November. It has already bitten company- and org-scoped
             * schedules elsewhere in the app.
             *
             * §5.5 asks for DST behaviour to be settled BEFORE schedules are
             * built, and the moment that arrives is known: the sweep that would
             * raise `item.low`, `vehicle.service_due` and
             * `vehicle.insurance_expiring`, which are declared and unraised for
             * exactly that reason. Whoever builds it inherits the question this
             * class currently does not have to answer — and must answer it,
             * rather than reading the absence of a timezone here as permission
             * to skip it.
             */

            // A durable lease serializes workers without holding a transaction
            // open across network calls. Abandoned actions remain on the run.
            $token = (string) Str::uuid();
            $claimed = AutomationEvent::query()->whereKey($event->id)
                ->whereNull('delivered_at')->whereNull('failed_at')
                ->where('available_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('processing_until')->orWhere('processing_until', '<=', now()))
                ->update(['processing_token' => $token, 'processing_until' => now()->addMinutes(10)]);
            if ($claimed !== 1) {
                return 0;
            }
            try {
                $event->refresh();
                if ($organization->isReadOnly()) {
                    $this->stop($event, 'Not run: this organization is read-only because its subscription has ended.');
                    return 0;
                }
                if ($event->attempts >= AutomationEvent::MAX_ATTEMPTS) {
                    $this->stop($event, 'Stopped: delivery retry budget exhausted.');
                    return 0;
                }
                if ($event->isTooDeep() || $event->actionsSpent() >= AutomationEvent::ACTION_BUDGET) {
                    $this->stop($event, 'Stopped: this cascade has reached its depth or action budget.');
                    return 0;
                }
                $event->forceFill(['attempts' => $event->attempts + 1])->save();
                $subject = $this->subjectOf($event);
                // Leads › Manage › Hidden (Jose, 2026-10-29): no rule, alert or text acts on a hidden lead.
                // A quiet skip: no error, no audit row, nothing that names the lead (Automations' review).
                if ($subject instanceof \App\Models\Lead && $subject->isHidden()) {
                    $this->finishPending($event, 'Skipped.');
                    $event->forceFill(['delivered_at' => now()])->save();

                    return 0;
                }
                $scope = $event->scope_snapshot ?? EventScope::capture($subject);
                if ($event->version_ids === null) {
                    $ids = Automation::query()->where('trigger_key', $event->event_key)
                        ->where('is_active', true)->whereNull('disabled_at')->whereNotNull('active_version_id')
                        ->get()->filter(fn (Automation $a) => $a->coversScope(
                            $scope['type'], $scope['id'], companyChain: $scope['company_chain'],
                        // A rule switched on acts from then on: a backlog, or an
                        // event that waited out an outage, is not texted to a
                        // customer by a rule that was off when it happened.
                        ) && ($a->activated_at === null || ! $event->occurred_at->lt($a->activated_at)))
                        ->pluck('active_version_id')->values()->all();
                    // Freeze the selected versions before the first side effect.
                    $event->forceFill(['version_ids' => $ids])->save();
                }
                $acted = 0;
                $dueAt = null;
                foreach ($event->version_ids as $versionId) {
                    $version = AutomationVersion::query()->find($versionId);
                    $automation = $version ? Automation::query()->find($version->automation_id) : null;
                    if ($automation === null || ! $automation->is_active || $automation->disabled_at !== null) {
                        $this->finishPending($event, 'Stopped: the automation was disabled or removed.', $versionId);
                        continue;
                    }
                    // A rule with a wait comes back for this event when it is due;
                    // the others act now, and a redelivery cannot run them twice.
                    $due = $event->occurred_at->copy()->addMinutes((int) $automation->delay_minutes
                        + self::replySpread($version, $event, (int) $automation->delay_minutes));
                    if ($due->isFuture()) {
                        $dueAt = $dueAt === null || $due->lt($dueAt) ? $due : $dueAt;
                        continue;
                    }
                    if ($this->runOne($automation, $version, $event, $subject)) {
                        $acted++;
                    }
                }
                $runs = AutomationRun::query()->where('event_id', $event->id)->get();
                $pending = $runs->contains(fn ($r) => $r->status === AutomationRun::MATCHED);
                $failed = $runs->first(fn ($r) => $r->status === AutomationRun::FAILED);
                $event->forceFill($dueAt !== null ? [
                    'available_at' => $dueAt,
                    'queued_at' => null,
                    // The visit is not a failed try: it does not spend the budget.
                    'attempts' => $event->attempts - 1,
                ] : ($pending ? [
                    'available_at' => now()->addSeconds([10, 60, 300, 900][min(max($event->attempts - 1, 0), 3)]),
                    'queued_at' => null,
                    'last_error' => 'A transient webhook failure is waiting for retry.',
                ] : [
                    'delivered_at' => now(),
                    'failed_at' => $failed ? now() : null,
                    'last_error' => $failed?->error,
                ]))->save();
                return $acted;
            } finally {
                AutomationEvent::query()->whereKey($event->id)->where('processing_token', $event->processing_token ?? $token)
                    ->update(['processing_token' => null, 'processing_until' => null]);
            }
        });
    }

    /** Resolve a queue failure without overwriting a completed or newer delivery. */
    public function failDelivery(string $eventId, string $reason): void
    {
        $event = AutomationEvent::withoutGlobalScopes()->find($eventId);
        $organization = $event?->organization()->withoutGlobalScopes()->first();
        if ($organization === null) {
            return;
        }
        $this->tenant->run($organization, fn () => DB::transaction(function () use ($eventId, $reason) {
            $event = AutomationEvent::query()->lockForUpdate()->find($eventId);
            if ($event === null || $event->delivered_at !== null || $event->failed_at !== null) {
                return;
            }
            if ($event->processing_until?->isFuture()) {
                // A killed worker and a newer active worker look identical to
                // this hook. Let the durable lease expire before recovery.
                $event->forceFill(['queued_at' => null, 'available_at' => $event->processing_until,
                    'last_error' => $reason])->save();
                return;
            }
            $this->stop($event, $reason);
        }));
    }

    private function runOne(Automation $automation, AutomationVersion $version, AutomationEvent $event, $subject): bool
    {
        $run = AutomationRun::query()->where('event_id', $event->id)
            ->where('automation_version_id', $version->id)->first();
        if ($run !== null && $run->status !== AutomationRun::MATCHED) {
            return false;
        }
        if ($run === null) {
            $started = now();
            try {
                $verdict = $this->conditions->evaluate($version->conditions, $event, $subject);
            } catch (Throwable $e) {
                $this->record($automation, $version, $event, AutomationRun::FAILED, $started, error: $e->getMessage());
                if ($automation->noteFailure()) {
                    $this->announceDisabled($automation);
                }
                return false;
            }
            if (! $verdict['matched']) {
                $this->record($automation, $version, $event, AutomationRun::SKIPPED, $started, trace: $verdict['trace']);
                return false;
            }
            $run = AutomationRun::create([
                'organization_id' => $event->organization_id,
                'automation_id' => $automation->id, 'automation_version_id' => $version->id,
                'event_id' => $event->id, 'status' => AutomationRun::MATCHED,
                'started_at' => $started, 'condition_trace' => $verdict['trace'],
                'actions_snapshot' => $version->actions ?? [],
                'context_snapshot' => $automation->only(['scope_type', 'scope_id', 'created_by']),
            ]);
        }
        if ($run->actions_snapshot === null) {
            // Before durable per-action claims, an unfinished run cannot say
            // whether its unrecorded side effects happened. Never replay it.
            $this->finishPending($event, 'Unknown outcome from a legacy interrupted run; not replayed.', $version->id);
            return false;
        }
        $executionRule = clone $automation;
        if ($run->context_snapshot !== null) {
            $executionRule->forceFill($run->context_snapshot);
        }
        $results = $run->action_results ?? [];
        foreach ($run->actions_snapshot ?? $version->actions ?? [] as $index => $action) {
            $previous = $results[$index] ?? null;
            if ($previous !== null && ! in_array($previous['state'] ?? 'terminal', ['retryable', 'started'], true)) {
                continue; // Success, permanent failure, and unknown never repeat.
            }
            $type = $action['type'] ?? 'unknown';
            if (($previous['state'] ?? null) === 'started' && $type !== 'webhook') {
                $results[$index] = array_merge($previous, [
                    'state' => 'unknown', 'ok' => false,
                    'detail' => 'Worker stopped during this action; delivery outcome is unknown. Not retried.',
                ]);
                continue;
            }
            $attempts = (int) ($previous['attempts'] ?? 0);
            if ($attempts >= AutomationEvent::MAX_ATTEMPTS || $event->actionsSpent() >= AutomationEvent::ACTION_BUDGET) {
                $results[$index] = array_merge($previous ?? ['type' => $type], [
                    'state' => 'failed', 'ok' => false, 'detail' => 'Stopped: retry or cascade action budget exhausted.',
                ]);
                continue;
            }
            $renewToken = (string) Str::uuid();
            $renewed = AutomationEvent::query()->whereKey($event->id)
                ->where('processing_token', $event->processing_token)
                ->where('processing_until', '>', now())
                ->update(['processing_until' => now()->addMinutes(10), 'processing_token' => $renewToken]);
            if ($renewed !== 1) {
                throw new \RuntimeException('Automation delivery lease was lost.');
            }
            $event->setAttribute('processing_token', $renewToken);
            $event->syncOriginalAttribute('processing_token');
            $key = hash('sha256', $event->id.':'.$version->id.':'.$index);
            $results[$index] = ['type' => $type, 'ok' => false, 'state' => 'started',
                'detail' => 'Action started; outcome not yet recorded.', 'attempts' => $attempts + 1,
                'idempotency_key' => $key];
            $run->forceFill(['action_results' => $results,
                'action_count' => array_sum(array_map(fn ($r) => $r['attempts'] ?? 1, $results)),
            ])->save();
            try {
                $result = $this->actions->run($action, $event, $subject,
                    automation: $executionRule, actionIndex: $index, idempotencyKey: $key);
            } catch (Throwable $e) {
                $result = ['type' => $type, 'ok' => false, 'detail' => $e->getMessage(),
                    'unknown' => true];
            }
            $retry = $type === 'webhook' && ($result['retryable'] ?? false)
                && $attempts + 1 < AutomationEvent::MAX_ATTEMPTS;
            $results[$index] = array_merge($results[$index], $result, [
                'state' => $result['ok'] ? 'succeeded' : ($retry ? 'retryable' : (($result['unknown'] ?? false) ? 'unknown' : 'failed')),
            ]);
            $run->forceFill(['action_results' => $results])->save();
        }
        $pending = collect($results)->contains(fn ($r) => ($r['state'] ?? '') === 'retryable');
        $failure = collect($results)->first(fn ($r) => ! $r['ok']);
        $run->forceFill([
            'status' => $pending ? AutomationRun::MATCHED : ($failure ? AutomationRun::FAILED : AutomationRun::SUCCEEDED),
            'action_results' => $results ?: null,
            'error' => $failure['detail'] ?? null,
            'finished_at' => $pending ? null : now(),
        ])->save();
        if (! $pending) {
            if ($failure) {
                if ($automation->noteFailure()) {
                    $this->announceDisabled($automation);
                }
            } else {
                $automation->noteSuccess();
            }
        }
        return true;
    }

    /**
     * Tell the people who could fix it that a rule switched itself off.
     *
     * Two decisions, both mine, taken 2026-09-15 under ACTIVE-CONSTRAINTS section
     * 11 because Jose was away and the alternative was leaving it silent.
     *
     * WHO: the automation itself is the subject, so audienceFor() reads
     * CarriesAutomationScope and tells the people who STAND at that rule's scope
     * - a franchise rule reaches that franchise, not the whole business. The
     * failure being avoided is real and is why this sat unbuilt: a NULL subject
     * falls through to EVERY ACTIVE USER, so one branch's broken rule would
     * notify everybody. Passing the rule prevents it, using a scope contract
     * that already existed rather than a second answer invented here.
     *
     * SOURCE system, NOT automation. notifications.automation_enabled is the
     * switch for rules notifying PEOPLE - the notify action. A rule breaking is
     * EzBizi talking about its own tool, not a rule firing. Filing it under
     * automation would mean a business that muted chatty rules also muted the
     * message saying one had died, which is the one they most need. Both
     * channels off still silences it, and that is right: that switch says "do
     * not contact us here", not "we do not care".
     *
     * EDGE, NOT LEVEL: noteFailure() returns true only on the transition, so
     * this fires once. At level it would repeat for every event arriving while
     * the rule sat disabled.
     */
    private function announceDisabled(Automation $automation): void
    {
        $this->notifications->dispatch(
            title: "Automation switched off: {$automation->name}",
            body: $automation->disabled_reason,
            subject: $automation,
            source: 'system',
            automationId: (string) $automation->getKey(),
        );
    }

    private function finishPending(AutomationEvent $event, string $reason, ?string $versionId = null): void
    {
        $runs = AutomationRun::query()->where('event_id', $event->id)->where('status', AutomationRun::MATCHED)
            ->when($versionId !== null, fn ($q) => $q->where('automation_version_id', $versionId))->get();
        foreach ($runs as $run) {
            $results = array_map(function ($result) use ($reason) {
                if (in_array($result['state'] ?? '', ['started', 'retryable'], true)) {
                    $result['state'] = $result['state'] === 'started' ? 'unknown' : 'failed';
                    $result['detail'] = $reason;
                    $result['ok'] = false;
                }
                return $result;
            }, $run->action_results ?? []);
            $run->forceFill(['status' => AutomationRun::FAILED, 'error' => $reason,
                'action_results' => $results ?: null, 'finished_at' => now(),
            ])->save();
        }
    }

    /** Records a run that did nothing — a skip, or a rule that would not parse. */
    private function record(
        Automation $automation,
        $version,
        AutomationEvent $event,
        string $status,
        $started,
        array $trace = [],
        array $results = [],
        ?string $error = null,
        // Only runNow() passes true: the run happened because somebody pressed
        // a button rather than because the engine delivered an event. The
        // ACTIONS were real either way; this marks how it was started.
        bool $isTest = false,
    ): bool {
        try {
            AutomationRun::create([
                'is_test' => $isTest,
                'organization_id' => $event->organization_id,
                'automation_id' => $automation->getKey(),
                'automation_version_id' => $version->getKey(),
                'event_id' => $event->getKey(),
                'status' => $status,
                'condition_trace' => $trace ?: null,
                'action_results' => $results ?: null,
                'error' => $error,
                'started_at' => $started,
                'finished_at' => now(),
            ]);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    /**
     * Refuse to go further, and say so where somebody will find it.
     *
     * Recorded via logError, which is exempt from the Lead Developer audit
     * suppression (§4.2) — a runaway cascade is exactly the kind of thing that
     * must not be invisible because of who happened to trigger it.
     */
    private function stop(AutomationEvent $event, string $reason): void
    {
        // Called from inside deliver()'s tenant block, so this save passes the
        // BelongsToTenant guard. It did not always — that guard caught a real
        // bug where the queue worker had no tenant, which is exactly its job.
        $this->finishPending($event, $reason);
        $event->forceFill(['delivered_at' => now(), 'failed_at' => now(), 'last_error' => $reason])->save();

        $this->audit->logError('automation.cascade_stopped', [
            'event' => $event->event_key,
            'event_id' => $event->getKey(),
            'root_event_id' => $event->rootId(),
            'depth' => $event->depth,
            'reason' => $reason,
        ]);
    }

    /** The evaluator's verdict on one stored event, its subject loaded as a real run would (Build's "would have run"). */
    public function wouldMatch(?array $conditions, AutomationEvent $event): bool
    {
        return $this->conditions->evaluate($conditions, $event, $this->subjectOf($event))['matched'];
    }

    private function subjectOf(AutomationEvent $event)
    {
        if ($event->subject_type === null || ! class_exists($event->subject_type)) {
            return null;
        }

        // May legitimately be gone — a delete event's subject is the thing that
        // was deleted. Conditions fall back to the event payload for that reason.
        return $event->subject_type::query()->find($event->subject_id);
    }

    /**
     * Rehearse a rule against a real event without doing anything.
     *
     * §5.5 test mode. The conditions are evaluated for real — that is the part
     * people get wrong — and the actions report what they would have done.
     */
    /**
     * Run the rule against a real event FOR REAL, because somebody pressed it.
     *
     * ── Why this exists beside rehearse() rather than as a flag on it ──────
     *
     * `rehearse()` performs nothing and reports what it WOULD do, which is the
     * right default and useless for the question Jose actually has: "does the
     * email arrive". Waiting for a missed call to happen in order to find out
     * whether a rule works is how a rule sits broken for two weeks, which is
     * exactly what happened to the one that started all of this.
     *
     * SEPARATE METHOD, NOT `isTest: false` ON THE OTHER ONE. A boolean deciding
     * whether real texts and real emails go out is one wrong argument away from
     * a rehearsal that sends, and the two calls would be indistinguishable at a
     * glance. This one is named for what it does.
     *
     * ── What it deliberately does NOT do ──────
     *
     * IT WRITES NO RUN ROW, and that is forced rather than chosen.
     * `automation_runs` is UNIQUE on (event_id, automation_version_id) - the
     * index the at-least-once outbox depends on - so a second row for an event
     * this rule has already handled cannot exist. And the common case IS an
     * event already handled: somebody presses this to try the rule against the
     * last real thing that happened. `record()` swallows the collision and
     * returns false, so calling it here would silently record nothing while
     * reporting success.
     *
     * What IS durable: the controller writes `automation.run_now` to the audit
     * log with the event id, and every arm writes its own audit entry for what
     * it did. So a real send is attributable; it simply is not in the DELIVERY
     * ledger, because it was not a delivery. The results come back to the caller
     * to be shown immediately, which is the point of pressing it.
     *
     * The cascade budget and depth cap still apply through `run()`, so a rule
     * that fires other rules cannot be made to run away by pressing a button.
     */
    public function runNow(Automation $automation, AutomationEvent $event): array
    {
        return $this->tenant->run($event->organization, function () use ($automation, $event) {
            $version = $automation->activeVersion ?? $automation->versions()->first();

            $subject = $this->subjectOf($event);
            $verdict = $this->conditions->evaluate($version?->conditions, $event, $subject);

            $results = [];

            if ($verdict['matched']) {
                foreach ($version?->actions ?? [] as $actionIndex => $action) {
                    $results[] = $this->actions->run(
                        $action,
                        $event,
                        $subject,
                        isTest: false,
                        automation: $automation,
                        actionIndex: $actionIndex,
                    );
                }
            }

            return [
                'matched' => $verdict['matched'],
                'condition_trace' => $verdict['trace'],
                'did' => $results,
            ];
        });
    }

    /** rehearse() for a rule not saved yet: the same verdict and would-do, from its draft conditions and steps. */
    public function rehearseDraft(Automation $draft, ?array $conditions, array $actions, AutomationEvent $event): array
    {
        return $this->tenant->run($event->organization, function () use ($draft, $conditions, $actions, $event) {
            $subject = $this->subjectOf($event);
            $verdict = $this->conditions->evaluate($conditions, $event, $subject);

            return [
                'matched' => $verdict['matched'],
                'condition_trace' => $verdict['trace'],
                'would_do' => $verdict['matched']
                    ? array_map(fn ($a) => $this->actions->run($a, $event, $subject, isTest: true, automation: $draft), array_values($actions))
                    : [],
            ];
        });
    }

    public function rehearse(Automation $automation, AutomationEvent $event): array
    {
        return $this->tenant->run($event->organization, function () use ($automation, $event) {
            // Resolved INSIDE the tenant, like everything in deliver(). It used
            // to be read a line above the run() and worked only because the
            // caller happened to be a request that already had a tenant — the
            // same assumption deliver()'s header says nothing below it may make.
            // Reading an AutomationVersion is a tenant-scoped read whoever asks.
            $version = $automation->activeVersion ?? $automation->versions()->first();

            $subject = $this->subjectOf($event);
            $verdict = $this->conditions->evaluate($version?->conditions, $event, $subject);

            $results = [];

            if ($verdict['matched']) {
                foreach ($version?->actions ?? [] as $actionIndex => $action) {
                    $results[] = $this->actions->run(
                        $action,
                        $event,
                        $subject,
                        isTest: true,
                        automation: $automation,
                        actionIndex: $actionIndex,
                    );
                }
            }

            return [
                'matched' => $verdict['matched'],
                'condition_trace' => $verdict['trace'],
                'would_do' => $results,
            ];
        });
    }

    /**
     * Public review replies are spread out, not posted in a burst (A1 Leads'
     * pacing, Jose's takeover, 2026-10-23): a sync finds a batch of reviews at
     * once, and a rule that publishes replies would otherwise answer them all in
     * the same minute. Such a rule's wait becomes somewhere between the wait and
     * twice the wait, the same for an event every time it is looked at (so a
     * redelivery does not move it). Every other rule waits exactly as set.
     */
    private static function replySpread(AutomationVersion $version, AutomationEvent $event, int $delay): int
    {
        if ($delay <= 0 || ! collect((array) $version->actions)->contains(fn ($a) => ($a['type'] ?? null) === 'publish_reply')) {
            return 0;
        }

        return crc32((string) $event->getKey()) % ($delay + 1);
    }
}
