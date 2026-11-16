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
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES). */
    protected static string $changeType = 'automations';

    /** Filed where its scope is: a company, a branch, or the organization (ChangeFeed). */
    public function changeFeedPlace(): array
    {
        return match ($this->scope_type) {
            'company' => [$this->scope_id, null],
            'franchise' => [null, $this->scope_id],
            default => [null, null],
        };
    }

    use SoftDeletes;

    /** Auto-disable after this many consecutive failures (§5.5). */
    public const FAILURE_LIMIT = 5;

    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id', 'name', 'description',
        'trigger_key', 'is_active', 'active_version_id', 'created_by',
        'schedule', 'next_run_at', 'delay_minutes',
    ];

    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'disabled_at' => 'datetime',
            'activated_at' => 'datetime',
            'delay_minutes' => 'integer',
            'schedule'    => 'array',
            'next_run_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every way of switching a rule on stamps when, so the engine can
        // leave alone what happened before it (see AutomationEngine).
        static::saving(function (Automation $a) {
            if ($a->is_active && $a->isDirty('is_active')) {
                $a->activated_at = now();
            }
        });
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
    /**
     * Returns true only on the transition INTO disabled, never while disabled.
     *
     * The caller announces a rule switching itself off, and announcing is an
     * edge: at level it would tell somebody once per failing event for as long
     * as the rule stayed off.
     */
    public function noteFailure(): bool
    {
        $wasOff = $this->disabled_at !== null;

        $this->consecutive_failures++;

        if ($this->consecutive_failures >= self::FAILURE_LIMIT) {
            $this->disabled_at = now();

            // NO EXCEPTION TEXT HERE. This string is rendered to the customer by
            // automations_screen.dart:370. Both callers used to pass raw
            // $e->getMessage() - a Symfony TransportException, a Guzzle
            // ConnectionException, a PDO error - which is written in the vocabulary
            // of whoever wrote the library, changes without notice, and tells the
            // reader nothing they can act on. Nothing is lost by dropping it:
            // AutomationEngine already stores the same text in automation_runs.error
            // on both paths (the throw at :176 and the action failure at :260), which
            // is where somebody debugging should be looking anyway.
            //
            // "Run history" is the label on the menu item that opens it
            // (automations_screen.dart:301). Naming it anything else - "run log",
            // "the logs" - sends somebody looking for a control that is not there.
            // If that menu item is renamed, rename it here in the same commit.
            $this->disabled_reason = "Disabled after {$this->consecutive_failures} "
                .'consecutive failures. Open Run history to see what failed.';
        }

        $this->save();

        return ! $wasOff && $this->disabled_at !== null;
    }

    public function noteSuccess(): void
    {
        if ($this->consecutive_failures !== 0) {
            $this->forceFill(['consecutive_failures' => 0])->save();
        }
    }

    /**
     * Write the next version and make it the one that runs. The ONE writer:
     * the builder's Save and anything else that edits a rule (the Email
     * settings switch) go through here, so every change is a version with
     * an author, never an edit in place.
     */
    public function addVersion(?array $conditions, array $actions, User $by): AutomationVersion
    {
        $version = AutomationVersion::create([
            'automation_id' => $this->getKey(),
            'version' => ($this->versions()->max('version') ?? 0) + 1,
            'conditions' => $conditions,
            'actions' => $actions,
            'created_by' => $by->getKey(),
            'created_at' => now(),
        ]);

        $this->forceFill(['active_version_id' => $version->getKey()])->save();

        return $version;
    }
}
