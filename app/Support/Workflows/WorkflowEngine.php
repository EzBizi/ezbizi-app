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

        if ($organization->isReadOnly()) {
            // Checked ONCE here rather than letting each action be refused
            // individually: §13 says a workflow that keeps firing after expiry
            // means the product works fine without paying, and a rule that fails
            // action-by-action still burns retries and fills the log with the
            // same refusal. Stopping the event drains the outbox and leaves one
            // legible reason on the run somebody can see in the UI.
            return $this->tenant->run($organization, function () use ($event) {
                $this->stop($event,
                    'Not run — this organization is read-only because its subscription '
                    .'has ended. Rules will resume automatically once billing is updated.'
                );

                return 0;
            });
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
                    ...$this->scopeOf($subject),
                    companyChain: $this->companyChainFor($subject),
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

        /*
         * CLAIM the run before doing anything.
         *
         * The unique index on (event, version) was being consulted AFTER the
         * action loop, which made it a guarantee about the LOG rather than about
         * the side effect: a redelivered event re-sent every webhook and only
         * then discovered it had already run. The migration, this class and the
         * job all claimed otherwise, and the test that was supposed to prove it
         * passed for the wrong reason — its action set a field to a constant, so
         * the second run no-opped inside ActionRunner instead of being stopped
         * here.
         *
         * Claiming first moves the index to where it can still prevent the POST.
         * The residual is a crash mid-request, which no database can close.
         */
        $run = $this->claim($workflow, $version, $event, $started);

        if ($run === null) {
            return false;   // already claimed — a redelivery, correctly ignored
        }

        $results = [];
        $failed  = null;

        foreach ($version->actions ?? [] as $action) {
            // Re-checked inside the loop: one version with two hundred actions
            // would otherwise blow through the cascade budget in a single run,
            // because the budget is only consulted between events.
            if ($event->actionsSpent() >= WorkflowEvent::ACTION_BUDGET) {
                $results[] = [
                    'type' => $action['type'] ?? 'unknown',
                    'ok' => false,
                    'detail' => 'Stopped: this cascade has spent its action budget.',
                ];
                $failed = 'Cascade action budget exhausted.';
                break;
            }

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

            // Persisted as we go, so a crash halfway through leaves evidence of
            // what had already happened rather than an empty row.
            $run->forceFill([
                'action_results' => $results,
                'action_count'   => count($results),
            ])->save();
        }

        $status = $failed === null ? WorkflowRun::SUCCEEDED : WorkflowRun::FAILED;

        $run->forceFill([
            'status'         => $status,
            'condition_trace' => $verdict['trace'],
            'action_results' => $results ?: null,
            'action_count'   => count($results),
            'error'          => $failed,
            'finished_at'    => now(),
        ])->save();

        $failed === null ? $workflow->noteSuccess() : $workflow->noteFailure($failed);

        return true;
    }

    /**
     * Take the (event, version) pair, or discover somebody already has.
     *
     * This is the idempotency guarantee, and it works because it happens BEFORE
     * any action runs. The insert either wins — and this delivery owns the run —
     * or it loses to the unique index, and nothing is performed.
     *
     * @return WorkflowRun|null null when this exact run was already claimed
     */
    private function claim(Workflow $workflow, $version, WorkflowEvent $event, $started): ?WorkflowRun
    {
        try {
            return WorkflowRun::create([
                'organization_id'     => $event->organization_id,
                'workflow_id'         => $workflow->getKey(),
                'workflow_version_id' => $version->getKey(),
                'event_id'            => $event->getKey(),
                'status'              => WorkflowRun::MATCHED,
                'started_at'          => $started,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Records a run that did nothing — a skip, or a rule that would not parse. */
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

        foreach (['franchise_id', 'owning_franchise_id'] as $attribute) {
            if ($subject->getAttribute($attribute) !== null) {
                return ['franchise', $subject->getAttribute($attribute)];
            }
        }

        return [null, null];
    }

    /**
     * Every company a franchise sits beneath, nearest first.
     *
     * Needed because a company-scoped workflow has to fire on its Sub Companies'
     * franchises too — the same step §6 flags as the one most easily missed in
     * the settings cascade, and missing it here meant a company-scoped rule could
     * be saved through the API and then never match anything at all.
     *
     * @return string[] company ids
     */
    private function companyChainFor($subject): array
    {
        $franchiseId = $subject?->getAttribute('franchise_id')
            ?? $subject?->getAttribute('owning_franchise_id');

        if ($franchiseId === null) {
            return [];
        }

        $company = \App\Models\Franchise::query()->find($franchiseId)?->company;
        $chain   = [];

        while ($company !== null) {
            $chain[] = $company->getKey();
            $company = $company->parent;
        }

        return $chain;
    }

    /**
     * Rehearse a rule against a real event without doing anything.
     *
     * §5.5 test mode. The conditions are evaluated for real — that is the part
     * people get wrong — and the actions report what they would have done.
     */
    public function rehearse(Workflow $workflow, WorkflowEvent $event): array
    {
        return $this->tenant->run($event->organization, function () use ($workflow, $event) {
            // Resolved INSIDE the tenant, like everything in deliver(). It used
            // to be read a line above the run() and worked only because the
            // caller happened to be a request that already had a tenant — the
            // same assumption deliver()'s header says nothing below it may make.
            // Reading a WorkflowVersion is a tenant-scoped read whoever asks.
            $version = $workflow->activeVersion ?? $workflow->versions()->first();

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
