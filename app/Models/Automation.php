<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Automations\CarriesAutomationScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One automation rule (plan §5.5).
 *
 * The rule itself is a pointer: what triggers it, whether it is on, and which
 * immutable version is currently live. Everything that can change lives in
 * automation_versions, so history stays readable after an edit.
 */
class Automation extends Model implements CarriesAutomationScope
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use SoftDeletes;

    /** Auto-disable after this many consecutive failures (§5.5). */
    public const FAILURE_LIMIT = 5;

    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id', 'name', 'description',
        'trigger_key', 'is_active', 'active_version_id', 'created_by',
        'schedule', 'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'disabled_at' => 'datetime',
            'schedule'    => 'array',
            'next_run_at' => 'datetime',
        ];
    }

    /** A rule that runs on a clock rather than on something happening (§5.5). */
    public function isScheduled(): bool
    {
        return $this->schedule !== null && $this->schedule !== [];
    }

    /**
     * A scheduled rule is the subject of its OWN event, and that is load-bearing.
     *
     * Every other trigger is about a record — a job, a call, an email — and
     * `NotificationDispatcher::audienceFor` reads that record to decide who
     * hears. A schedule coming round is about nothing: there is no job and no
     * customer, only the clock. A subject-less event reaches the fallthrough in
     * `audienceFor`, which is EVERY ACTIVE USER IN THE ORGANIZATION — so
     * "email me the weekly report" written by one branch would have mailed the
     * whole business, and nothing in the builder would have looked wrong.
     *
     * The rule is a real record and it already knows where it sits, so it is
     * its own subject. A franchise-scoped scheduled rule then reaches that
     * franchise and nobody else, which is what its author meant.
     *
     * The two words are `automations.scope_type`'s own, which is why this is a
     * pass-through rather than a translation: an organization-wide rule answers
     * [null, null] and only an organization-wide rule covers it.
     */
    public function automationScope(): array
    {
        return match ($this->scope_type) {
            'company'   => ['company', $this->scope_id],
            'franchise' => ['franchise', $this->scope_id],
            default     => [null, null],
        };
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationVersion::class)->orderByDesc('version');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationVersion::class, 'active_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class);
    }

    /**
     * Would this automation act on an event from the given scope?
     *
     * An organization-scoped automation covers everything. A franchise-scoped one
     * covers only that franchise. Deliberately NOT the settings cascade in
     * reverse: a franchise automation must not fire on a sibling franchise's work
     * just because they share a parent.
     */
    public function coversScope(?string $scopeType, ?string $scopeId, array $companyChain = []): bool
    {
        if ($this->scope_type === null) {
            return true;   // organization-wide
        }

        // A company-scoped rule covers every franchise beneath it, including
        // those of its Sub Companies — the same downward reach assignments have
        // (§4). Without this the API accepted a company scope the engine could
        // never match, so the rule looked configured and never ran.
        if ($this->scope_type === 'company') {
            return in_array($this->scope_id, $companyChain, strict: true);
        }

        return $this->scope_type === $scopeType && $this->scope_id === $scopeId;
    }

    /** Live means switched on AND not auto-disabled after repeated failure. */
    public function isLive(): bool
    {
        return $this->is_active
            && $this->disabled_at === null
            && $this->active_version_id !== null;
    }

    /**
     * Record a failure, and stop the automation if it keeps failing.
     *
     * §5.5 asks for auto-disable after repeated failure. The reason it matters:
     * an automation calling a webhook that has started returning 500 will
     * otherwise retry on every event forever, and the noise buries what broke.
     */
    public function noteFailure(string $reason): void
    {
        $this->consecutive_failures++;

        if ($this->consecutive_failures >= self::FAILURE_LIMIT) {
            $this->disabled_at = now();
            $this->disabled_reason = "Disabled after {$this->consecutive_failures} "
                ."consecutive failures. Last error: {$reason}";
        }

        $this->save();
    }

    public function noteSuccess(): void
    {
        if ($this->consecutive_failures !== 0) {
            $this->forceFill(['consecutive_failures' => 0])->save();
        }
    }
}
