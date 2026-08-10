<?php

namespace App\Support\Automations;

use App\Models\AutomationEvent;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Writing something into the outbox (plan §5.5).
 *
 * Called from inside whatever transaction the business change is using. That is
 * the whole point of an outbox: the event and the change commit together or
 * neither does.
 */
class EventEmitter
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly ModuleRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array  $payload  typically ['before' => [...], 'after' => [...]]
     * @param  AutomationEvent|null  $causedBy  set when an automation action
     *                                          caused this — carries the
     *                                          cascade's root and depth so loop
     *                                          protection works across separate
     *                                          delayed runs.
     */
    public function emit(
        string $eventKey,
        ?Model $subject = null,
        array $payload = [],
        ?User $actor = null,
        ?AutomationEvent $causedBy = null,
        ?\DateTimeInterface $availableAt = null,
    ): AutomationEvent {
        $this->assertRegistered($eventKey);

        $actor ??= auth()->user() instanceof User ? auth()->user() : null;

        return AutomationEvent::create([
            'organization_id' => $this->tenant->idOrFail(),
            'event_key'       => $eventKey,
            'subject_type'    => $subject ? $subject::class : null,
            'subject_id'      => $subject?->getKey(),
            // Same redaction rules as the audit log. An event payload carrying a
            // password because somebody added a field is exactly as bad here as
            // it is there, and this table is read by tenant-authored rules.
            'payload'         => $this->audit->redact($payload) ?: null,
            'actor_id'        => $actor?->getKey(),
            'actor_label'     => $actor ? "{$actor->name} <{$actor->email}>" : 'system',
            'root_event_id'   => $causedBy?->rootId(),
            'causation_id'    => $causedBy?->getKey(),
            'depth'           => ($causedBy?->depth ?? -1) + 1,
            'correlation_id'  => $this->audit->correlationId(),
            'occurred_at'     => now(),
            'available_at'    => $availableAt ?? now(),
        ]);
    }

    /**
     * A trigger no module declares is a typo, and an automation listening for one
     * would look configured and never fire — the most confusing possible
     * failure, because nothing anywhere reports an error.
     *
     * The §5.5 standing rule is what makes this checkable: every module declares
     * its triggers, so the registry is the whole truth.
     */
    private function assertRegistered(string $eventKey): void
    {
        if ($this->registry->findTrigger($eventKey) !== null) {
            return;
        }

        throw new InvalidArgumentException(
            "No module declares the trigger '{$eventKey}'. Add it to the module's "
            .'ModuleDefinition, or the automation engine can never route it.'
        );
    }

    /**
     * Every trigger key any module declares, grouped by module.
     *
     * Keys only. What the automation builder OFFERS is the catalogue endpoint,
     * which carries each trigger's label and description as well — this is the
     * engine's view, and the engine routes on the key alone.
     *
     * @return array<string, string[]> module key => trigger keys
     */
    public function availableTriggers(): array
    {
        $triggers = [];

        foreach ($this->registry->all() as $key => $module) {
            if ($module->triggers !== []) {
                $triggers[$key] = $module->triggerKeys();
            }
        }

        return $triggers;
    }

    /**
     * Emit only if the surrounding transaction commits.
     *
     * For callers that are NOT already inside one. Without a transaction an
     * outbox row is written immediately and there is nothing to be atomic with,
     * which is fine — but it must not be silently assumed.
     */
    public function emitAfterCommit(string $eventKey, ?Model $subject = null, array $payload = []): void
    {
        DB::afterCommit(fn () => $this->emit($eventKey, $subject, $payload));
    }
}
