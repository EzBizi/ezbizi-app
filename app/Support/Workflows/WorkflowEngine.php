<?php

namespace App\Support\Workflows;

use App\Models\Workflow;
use App\Models\WorkflowEvent;
use App\Models\WorkflowRun;
use App\Support\Audit\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Delivering one outbox event to whatever rules care about it (plan §5.5).
 *
 * Runs on a queue, never inline: §5.5 is explicit that a slow external webhook
 * must not delay a user's save. By the time this executes, the business change
 * has already committed.
 */
class WorkflowEngine
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ConditionEvaluator $conditions,
        private readonly ActionRunner $actions,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int how many workflows acted on it
     */
    public function deliver(WorkflowEvent $event): int
    {
        // The tenant is established FIRST, around everything — including the loop
        // guards, which read and write tenant-owned rows. A queue worker has no
        // tenant of its own, and a worker handling many jobs in one long-lived
        // process is precisely where an inherited one becomes a cross-tenant
        // write (§5.1). Nothing below may assume a caller set it.
        $organization = $event->organization()->withoutGlobalScopes()->first();

        if ($organization === null) {
            return 0;   // the organization was removed while this sat in the queue
        }

        return $this->tenant->run($organization, function () use ($event) {
            // Loop protection (§5.5). Depth catches a rule that feeds itself; the
            // budget catches one that fans out sideways, and a chain alternating
            // A→B→A across separate delayed runs — which depth alone does not.
            if ($event->isTooDeep()) {
                $this->stop($event, sprintf(
                    'Stopped at depth %d. A workflow action caused another workflow '
                    .'to run, and so on — the chain is looping or longer than intended.',
                    $event->depth,
                ));

                return 0;
            }

            if ($event->actionsSpent() >= WorkflowEvent::ACTION_BUDGET) {
                $this->stop($event, sprintf(
                    'Stopped after %d actions from one originating event. The rules '
                    .'are fanning out further than anything deliberate would.',
                    WorkflowEvent::ACTION_BUDGET,
                ));

                return 0;
            }

            $subject = $this->subjectOf($event);

            $workflows = Workflow::query()
                ->where('trigger_key', $event->event_key)
                ->where('is_active', true)
                ->whereNull('disabled_at')
                ->whereNotNull('active_version_id')
                ->with('activeVersion')
                ->get()
                ->filter(fn (Workflow $w) => $w->coversScope(
                    ...$this->scopeOf($subject)
                ));

            $acted = 0;

            foreach ($workflows as $workflow) {
                if ($this->runOne($workflow, $event, $subject)) {
                    $acted++;
                }
            }

            $event->forceFill(['delivered_at' => now()])->save();

            return $acted;
        });
    }

    private function runOne(Workflow $workflow, WorkflowEvent $event, $subject): bool
    {
        $version = $workflow->activeVersion;

        if ($version === null) {
            return false;
        }

        $started = now();

        try {
            $verdict = $this->conditions->evaluate($version->conditions, $event, $subject);
        } catch (Throwable $e) {
            // A malformed rule is the rule's fault, not the event's. Record it
            // against this workflow and carry on with the others — one broken
            // automation must not stop everybody else's.
            $this->record($workflow, $version, $event, WorkflowRun::FAILED, $started, error: $e->getMessage());
            $workflow->noteFailure($e->getMessage());

            return false;
        }

        if (! $verdict['matched']) {
            // Skips ARE recorded. "The rule did not match" and "the rule never
            // saw it" are completely different answers to give someone asking
            // why their automation did not fire, and only this tells them apart.
            $this->record(
                $workflow, $version, $event, WorkflowRun::SKIPPED, $started,
                trace: $verdict['trace'],
            );

            return false;
        }

        $results = [];
        $failed  = null;

        foreach ($version->actions ?? [] as $action) {
            try {
                $result = $this->actions->run($action, $event, $subject);
            } catch (Throwable $e) {
                $result = ['type' => $action['type'] ?? 'unknown', 'ok' => false, 'detail' => $e->getMessage()];
            }

            $results[] = $result;

            if (! $result['ok']) {
                $failed = $result['detail'];
                // Keep going. Actions are independent, and stopping at the first
                // failure means a broken webhook silently cancels the field
                // update that was supposed to happen alongside it.
            }
        }

        $status = $failed === null ? WorkflowRun::SUCCEEDED : WorkflowRun::FAILED;

        $recorded = $this->record(
            $workflow, $version, $event, $status, $started,
            trace: $verdict['trace'], results: $results, error: $failed,
        );

        if (! $recorded) {
            return false;   // already run — a redelivery, correctly ignored
        }

        $failed === null ? $workflow->noteSuccess() : $workflow->noteFailure($failed);

        return true;
    }

    /**
     * Write the run, and let the unique index be the idempotency guarantee.
     *
     * §5.5 asks for at-least-once delivery with idempotency keys. Rather than
     * inventing a key and checking it first — which races — the (event, version)
     * pair is unique in the database, so a redelivery loses the insert and stops.
     *
     * @return bool false when this exact run already happened
     */
    private function record(
        Workflow $workflow,
        $version,
        WorkflowEvent $event,
        string $status,
        $started,
        array $trace = [],
        array $results = [],
        ?string $error = null,
    ): bool {
        try {
            WorkflowRun::create([
                'organization_id'     => $event->organization_id,
                'workflow_id'         => $workflow->getKey(),
                'workflow_version_id' => $version->getKey(),
                'event_id'            => $event->getKey(),
                'status'              => $status,
                'condition_trace'     => $trace ?: null,
                'action_results'      => $results ?: null,
                'error'               => $error,
                'started_at'          => $started,
                'finished_at'         => now(),
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
    private function stop(WorkflowEvent $event, string $reason): void
    {
        // Called from inside deliver()'s tenant block, so this save passes the
        // BelongsToTenant guard. It did not always — that guard caught a real
        // bug where the queue worker had no tenant, which is exactly its job.
        $event->forceFill(['delivered_at' => now(), 'failed_at' => now(), 'last_error' => $reason])->save();

        $this->audit->logError('workflow.cascade_stopped', [
            'event'         => $event->event_key,
            'event_id'      => $event->getKey(),
            'root_event_id' => $event->rootId(),
            'depth'         => $event->depth,
            'reason'        => $reason,
        ]);
    }

    private function subjectOf(WorkflowEvent $event)
    {
        if ($event->subject_type === null || ! class_exists($event->subject_type)) {
            return null;
        }

        // May legitimately be gone — a delete event's subject is the thing that
        // was deleted. Conditions fall back to the event payload for that reason.
        return $event->subject_type::query()->find($event->subject_id);
    }

    /**
     * Which scope an event belongs to, for matching franchise- or company-scoped
     * workflows. Read off the subject, because the event itself is deliberately
     * generic.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function scopeOf($subject): array
    {
        if ($subject === null) {
            return [null, null];
        }

        foreach (['franchise_id' => 'franchise', 'owning_franchise_id' => 'franchise'] as $attribute => $type) {
            if ($subject->getAttribute($attribute) !== null) {
                return [$type, $subject->getAttribute($attribute)];
            }
        }

        return [null, null];
    }

    /**
     * Rehearse a rule against a real event without doing anything.
     *
     * §5.5 test mode. The conditions are evaluated for real — that is the part
     * people get wrong — and the actions report what they would have done.
     */
    public function rehearse(Workflow $workflow, WorkflowEvent $event): array
    {
        $version = $workflow->activeVersion ?? $workflow->versions()->first();

        return $this->tenant->run($event->organization, function () use ($version, $event) {
            $subject = $this->subjectOf($event);
            $verdict = $this->conditions->evaluate($version?->conditions, $event, $subject);

            $results = [];

            if ($verdict['matched']) {
                foreach ($version?->actions ?? [] as $action) {
                    $results[] = $this->actions->run($action, $event, $subject, isTest: true);
                }
            }

            return [
                'matched'         => $verdict['matched'],
                'condition_trace' => $verdict['trace'],
                'would_do'        => $results,
            ];
        });
    }
}
