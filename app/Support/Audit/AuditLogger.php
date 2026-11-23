<?php

namespace App\Support\Audit;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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
        // Plural deliberately: a recovery code is a full second-factor bypass,
        // but `recovery_code_count` is just a number and stays readable.
        'recovery_codes',
    ];

    /** Ties every event from one request together. */
    private ?string $correlationId = null;

    /**
     * Set while a Lead Developer is acting — raised by
     * suppressForLeadDeveloper(), and the one thing log() checks before it
     * writes. logError() and logStaff() write regardless; both say why.
     */
    private bool $suppressed = false;

    public function __construct(
        private readonly TenantContext $tenant,
    ) {}

    public function correlationId(): string
    {
        return $this->correlationId ?? $this->useCorrelationId((string) Str::uuid7());
    }

    /**
     * Adopt a caller-supplied correlation id, if it is actually one.
     *
     * The value arrives from an untrusted request header and lands in a CHAR(36)
     * column. Anything longer is a 500 raised *after* the business change has
     * already committed — the worst possible moment. A malformed id is not worth
     * failing a request over, so it is replaced rather than rejected.
     */
    public function setCorrelationId(string $id): void
    {
        $this->useCorrelationId(Str::isUuid($id) ? $id : (string) Str::uuid7());
    }

    /**
     * One id, put on the LOG CHANNEL as well as kept for the rows.
     *
     * ExceptionAudit deliberately keeps the exception message and the stack
     * trace out of `audit_events` — that table is append-only, erasure cannot
     * reach it, and a tenant admin reads their own rows out of it — and the
     * argument for doing so rests entirely on the message still being findable
     * in `storage/logs`, "joined by the correlation id".
     *
     * It was not findable. The id went on the audit row and on an
     * X-Correlation-Id response header and nowhere near a log line, so the row
     * and the log entry that explained it had nothing in common but a timestamp.
     * On a box handling concurrent requests a timestamp is not a join.
     *
     * `shareContext` is what makes the claim true: every channel gets the id in
     * its context from here on, including the framework's own exception report,
     * which runs after ExceptionAudit's reportable callback returns.
     */
    private function useCorrelationId(string $id): string
    {
        $this->correlationId = $id;

        Log::shareContext(['correlation_id' => $id]);

        return $id;
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
     * (see logError, and ExceptionAudit which now feeds it every unhandled
     * exception), so a *failing* action leaves a trace — a successful one does
     * not.
     *
     * WHO IS EXEMPT is one account, not a tier. StaffOnly reaches this only for
     * a staff member whose `audit_exempt` flag is set, which §4.2 requires to be
     * "an explicit flag on the single Lead Developer account" — it was keyed on
     * the tier, so every promotion silently widened the exemption. Granting and
     * revoking that flag is itself written, through logStaff, which this method
     * cannot suppress.
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
        ?string $scopeType = null,
        ?string $scopeId = null,
    ): ?AuditEvent {
        if ($this->suppressed) {
            return null;
        }

        $actor ??= auth()->user();

        // View as (2026-11-23): a write made while a developer views the app as
        // somebody is recorded against the developer, saying who they were
        // viewing as. Never against the person or role being viewed.
        if ($actor instanceof \App\Support\ViewAs\ViewAsUser) {
            $metadata['viewing_as'] ??= \App\Support\ViewAs\ViewAs::describe($actor->viewAsSession);
            $actor = $actor->viewAsActor;
        }

        // Where it happened, from what it happened to: hardly any caller says,
        // so 18,000 of 18,100 rows had no scope and a company's Activity was
        // empty (Jose, 2026-10-19). A branch beats its company.
        if ($scopeType === null && $target !== null) {
            [$scopeType, $scopeId] = match (true) {
                filled($target->getAttribute('franchise_id')) => ['franchise', (string) $target->getAttribute('franchise_id')],
                filled($target->getAttribute('company_id')) => ['company', (string) $target->getAttribute('company_id')],
                default => [null, $scopeId],
            };
        }

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
            'scope_type'      => $scopeType,
            'scope_id'        => $scopeId,
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

        /*
         * A sensitive value never enters the log, in either direction.
         *
         * Two separate wrongs before this. `getOriginal()` APPLIES casts and
         * `getDirty()` does not, so for an encrypted column the row read:
         *
         *     before: "OLD-BANK-ACCOUNT"        <- the previous value, in clear
         *     after:  "eyJpdiI6Ijk4V3BQS29..."  <- the new one, unreadable
         *
         * So the old bank account went into an append-only table in plaintext,
         * and the new one went in as a blob nobody could ever read. Erasure
         * cannot reach audit_events by design, which makes it strictly the worst
         * destination for the one value the tenant marked as needing protection.
         *
         * `REDACT` — the keyword list above — could not have caught it. It
         * matches on English column names, and these fields are named by the
         * tenant: somebody calling theirs `acct` is invisible to it. This asks
         * the RECORD which of its own attributes are sensitive, which is the
         * same question the mask and the export ask, so all three agree without
         * anybody maintaining a list.
         *
         * The fact of the change is still recorded. What changed, when, and by
         * whom is the point of the entry; the value was never the point.
         */
        if ($model instanceof \App\Support\Privacy\HoldsSensitiveValues) {
            foreach ($model->sensitiveAttributes() as $attribute) {
                if (array_key_exists($attribute, $after)) {
                    $after[$attribute] = \App\Support\Privacy\SensitiveValues::MASK;
                }

                if (array_key_exists($attribute, $before)) {
                    $before[$attribute] = \App\Support\Privacy\SensitiveValues::MASK;
                }
            }
        }

        // Not sensitive (§9) — {@see SummarizesBulkAttributes}'s own
        // docblock — just bulk and unread. `count`, never the value: the
        // fact that `questions`/`screens` changed, and by how much, is
        // still on the row; the content is not.
        if ($model instanceof SummarizesBulkAttributes) {
            foreach ($model->bulkAuditAttributes() as $attribute) {
                if (array_key_exists($attribute, $after)) {
                    $after[$attribute] = self::bulkSummary($after[$attribute]);
                }

                if (array_key_exists($attribute, $before)) {
                    $before[$attribute] = self::bulkSummary($before[$attribute]);
                }
            }
        }

        return $this->log($action, $model, ['before' => $before, 'after' => $after], actor: $actor);
    }

    /**
     * @return array{count: ?int}
     */
    private static function bulkSummary(mixed $value): array
    {
        // `getDirty()` returns the RAW stored form for an `array`-cast
        // attribute (a JSON string), while `getOriginal()` (above) already
        // applies the cast (a real PHP array) — the same asymmetry this
        // method's own caller documents for encrypted columns. Handling
        // both shapes here means `before` and `after` summarize the same
        // way regardless of which side of that asymmetry produced them.
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return ['count' => is_array($decoded) ? count($decoded) : null];
    }

    /**
     * Errors and crashes.
     *
     * Recorded even under Lead Developer suppression — that was the explicit
     * carve-out, and it's the one that matters: a crash nobody logged is a crash
     * nobody fixes.
     *
     * The framework's exception handling reaches this through ExceptionAudit
     * (registered in bootstrap/app.php), which is what makes "every crash" true
     * rather than "every crash somebody remembered to catch". Read that class
     * before adding anything to `$context` from a throwable: REDACT matches
     * array KEYS and is inert against free text, so a message or a raw trace put
     * in here is not filtered by anything.
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
        // Staff work in platform scope, so there is no tenant in context to
        // infer this from. Passed explicitly where the event concerns a specific
        // client — otherwise "everything that happened to organization X" would
        // miss the rows that matter most to them.
        ?string $organizationId = null,
    ): AuditEvent {
        return AuditEvent::create([
            'organization_id' => $organizationId
                ?? ($this->tenant->has() ? $this->tenant->id() : null),
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
