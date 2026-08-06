<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Writes audit events (plan §10, §2.2).
 *
 * Called inside whatever transaction the business change is using, so the change
 * and its record commit together or not at all (review R-11).
 */
class AuditLogger
{
    /**
     * Never written to the log, at any nesting depth.
     *
     * "Log every action" must never become "log every secret". These are matched
     * case-insensitively as substrings, so `password_confirmation`,
     * `stripe_api_key` and `remember_token` are all caught without listing them.
     */
    private const REDACT = [
        'password', 'secret', 'token', 'api_key', 'apikey',
        'authorization', 'credit_card', 'card_number', 'cvv', 'ssn',
        'private_key', 'access_key', 'refresh_token',
    ];

    /** Ties every event from one request together. */
    private ?string $correlationId = null;

    /** Set while a Lead Developer is acting — see shouldRecord(). */
    private bool $suppressed = false;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::uuid7();
    }

    public function setCorrelationId(string $id): void
    {
        $this->correlationId = $id;
    }

    /**
     * Suppress ordinary auditing for the Lead Developer (owner's decision).
     *
     * ── What this costs, recorded plainly ────────────────────────
     *
     * The owner decided the Lead Developer account is not audited except for
     * errors and crashes, so their development and testing activity doesn't
     * flood the business audit trail. That rationale is reasonable, and this
     * implements it faithfully.
     *
     * The consequence, which belongs in writing rather than in someone's memory:
     * the audit log cannot prove every access or change. If the
     * highest-privilege account is ever compromised or misused, there may be no
     * business audit trail of what it did. Errors and crashes are still recorded
     * (see logError), so a *failing* action leaves a trace — a successful one
     * does not.
     *
     * Regular Developers are audited normally; only the Lead Developer is exempt.
     */
    public function suppressForLeadDeveloper(callable $callback): mixed
    {
        $previous = $this->suppressed;
        $this->suppressed = true;

        try {
            return $callback();
        } finally {
            $this->suppressed = $previous;
        }
    }

    // ── writing ──────────────────────────────────────────────────

    public function log(
        string $action,
        ?Model $target = null,
        array $changes = [],
        array $metadata = [],
        ?User $actor = null,
        ?string $causationId = null,
    ): ?AuditEvent {
        if ($this->suppressed) {
            return null;
        }

        $actor ??= auth()->user();

        return AuditEvent::create([
            'organization_id' => $this->tenant->has() ? $this->tenant->id() : null,
            'actor_type'      => $actor ? AuditEvent::ACTOR_USER : AuditEvent::ACTOR_SYSTEM,
            'actor_id'        => $actor?->getKey(),
            // Captured now, because "who did this" must survive the actor's deletion.
            'actor_label'     => $actor ? "{$actor->name} <{$actor->email}>" : 'system',
            'action'          => $action,
            'target_type'     => $target ? $target::class : null,
            'target_id'       => $target?->getKey(),
            'target_label'    => $this->labelFor($target),
            'changes'         => $this->redact($changes) ?: null,
            'metadata'        => $this->redact($metadata) ?: null,
            'correlation_id'  => $this->correlationId(),
            'causation_id'    => $causationId,
            'occurred_at'     => now(),
        ]);
    }

    /**
     * Record what changed on a model, before and after.
     *
     * Only dirty attributes — logging every field on every save buries the one
     * that actually changed and bloats the table for no benefit.
     */
    public function logChange(string $action, Model $model, ?User $actor = null): ?AuditEvent
    {
        $after  = $model->getDirty();
        $before = array_intersect_key($model->getOriginal(), $after);

        return $this->log($action, $model, ['before' => $before, 'after' => $after], actor: $actor);
    }

    /**
     * Errors and crashes.
     *
     * Recorded even under Lead Developer suppression — that was the explicit
     * carve-out, and it's the one that matters: a crash nobody logged is a crash
     * nobody fixes.
     */
    public function logError(string $action, array $context = [], ?User $actor = null): AuditEvent
    {
        $wasSuppressed = $this->suppressed;
        $this->suppressed = false;

        try {
            return $this->log($action, metadata: $context, actor: $actor);
        } finally {
            $this->suppressed = $wasSuppressed;
        }
    }

    /**
     * Platform staff activity (§4.2) — always recorded, never suppressed for
     * regular Developers, and retained for 12 months because these evidence
     * staff access to client data.
     */
    public function logStaff(
        string $action,
        string $staffId,
        string $staffLabel,
        ?Model $target = null,
        array $metadata = [],
    ): AuditEvent {
        return AuditEvent::create([
            'organization_id' => $this->tenant->has() ? $this->tenant->id() : null,
            'actor_type'      => AuditEvent::ACTOR_STAFF,
            'actor_id'        => $staffId,
            'actor_label'     => $staffLabel,
            'action'          => $action,
            'target_type'     => $target ? $target::class : null,
            'target_id'       => $target?->getKey(),
            'target_label'    => $this->labelFor($target),
            'metadata'        => $this->redact($metadata) ?: null,
            'correlation_id'  => $this->correlationId(),
            'occurred_at'     => now(),
        ]);
    }

    // ── redaction ────────────────────────────────────────────────

    /** Recursive: secrets nest inside `before`/`after` and request payloads. */
    public function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::REDACT as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function labelFor(?Model $target): ?string
    {
        if ($target === null) {
            return null;
        }

        foreach (['name', 'title', 'email'] as $attribute) {
            if (filled($target->getAttribute($attribute))) {
                return (string) $target->getAttribute($attribute);
            }
        }

        return null;
    }
}
