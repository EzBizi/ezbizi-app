<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One thing that happened, waiting to be delivered (plan §5.5).
 *
 * The transactional outbox. Written inside the same transaction as the business
 * change, so the two cannot disagree: roll back and the event goes with it,
 * commit and the event is already durable.
 *
 * Dispatching to a queue from inside the transaction is the bug this avoids —
 * the worker can pick the job up before the commit lands and find nothing, or
 * the commit can fail after the job was queued and act on something that never
 * happened.
 */
class WorkflowEvent extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const UPDATED_AT = null;
    public const CREATED_AT = null;   // occurred_at is the real timestamp

    /**
     * How deep a cascade may go.
     *
     * A workflow updating a field emits an update event, which may trigger
     * another workflow. Five is enough for any rule anyone means to write and
     * short enough that a loop stops being expensive quickly.
     */
    public const MAX_DEPTH = 5;

    /**
     * Total ACTIONS one originating event may cause across the whole cascade.
     *
     * Actions, not runs. Counting runs made this a limit of 50 *rules firing*,
     * each of which can carry any number of actions — so the real ceiling on
     * outbound calls was unbounded, which is precisely what a budget is for.
     */
    public const ACTION_BUDGET = 50;

    /** Give up after this many delivery attempts and dead-letter it. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = [
        'organization_id', 'event_key', 'subject_type', 'subject_id', 'payload',
        'actor_id', 'actor_label', 'root_event_id', 'causation_id', 'depth',
        'correlation_id', 'occurred_at', 'available_at',
    ];

    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'occurred_at'  => 'datetime',
            'available_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at'    => 'datetime',
            'depth'        => 'integer',
            'attempts'     => 'integer',
        ];
    }

    /**
     * The originating event of this cascade.
     *
     * Its own id when it started the cascade — so every event in a chain shares
     * one root, and the action budget is enforced across the whole thing rather
     * than per hop.
     */
    public function rootId(): string
    {
        return $this->root_event_id ?? $this->getKey();
    }

    public function isTooDeep(): bool
    {
        return $this->depth >= self::MAX_DEPTH;
    }

    /**
     * How many actions this cascade has already performed.
     *
     * Depth alone does not stop A→B→A across separate delayed runs, and it does
     * nothing about a rule that fans out sideways — one event producing fifty.
     * The budget covers both.
     */
    public function actionsSpent(): int
    {
        $root = $this->rootId();

        return static::query()
            ->join('workflow_runs', 'workflow_runs.event_id', '=', 'workflow_events.id')
            // Grouped and qualified. Ungrouped, `A or B and C` binds as
            // `A or (B and C)`, which quietly counts every run in the
            // organization rather than this cascade's — and `id` is ambiguous
            // once both tables are in the query.
            ->where(function ($q) use ($root) {
                $q->where('workflow_events.root_event_id', $root)
                    ->orWhere('workflow_events.id', $root);
            })
            ->sum('workflow_runs.action_count');
    }
}
