<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
 * workflow_versions, so history stays readable after an edit.
 */
class Workflow extends Model
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
    ];

    protected function casts(): array
    {
        return [
            'is_active'   => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'active_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }

    /**
     * Would this workflow act on an event from the given scope?
     *
     * An organization-scoped workflow covers everything. A franchise-scoped one
     * covers only that franchise. Deliberately NOT the settings cascade in
     * reverse: a franchise workflow must not fire on a sibling franchise's work
     * just because they share a parent.
     */
    public function coversScope(?string $scopeType, ?string $scopeId): bool
    {
        if ($this->scope_type === null) {
            return true;   // organization-wide
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
     * Record a failure, and stop the workflow if it keeps failing.
     *
     * §5.5 asks for auto-disable after repeated failure. The reason it matters:
     * a workflow calling a webhook that has started returning 500 will otherwise
     * retry on every event forever, and the noise buries whatever broke.
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
