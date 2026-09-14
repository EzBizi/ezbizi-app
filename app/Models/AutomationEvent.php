<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
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
class AutomationEvent extends Model implements StatesRetention
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    /**
     * Three months, and it takes the runs with it.
     *
     * This is the table §10 was written about and the inventory could not see:
     * it holds no personal data of its own, so it could not implement
     * HoldsPersonalData without lying, and until StatesRetention existed there
     * was nowhere else to say anything. It was therefore not a table with a
     * generous policy — it was a table nobody had ever decided about, taking a
     * row for every business change in every organization, forever.
     *
     * Three months matches business audit, deliberately: this is the same
     * history seen from the automation side, and two different windows over one
     * set of facts would mean "why did my rule not fire in April?" being
     * answerable from one table and not the other.
     *
     * `automation_runs.event_id` cascades from here, so expiring an event expires
     * its runs in the same statement. That is why AutomationRun states
     * `withParent` rather than a window of its own — one number, one place, and
     * a run can never outlive the event it explains.
     *
     * The sweep is bounded to DELIVERED or FAILED events by the command, because
     * an undelivered event is work still owed rather than history.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::window(3, 'occurred_at',
            'The outbox of what happened, kept so "why did my automation not fire?" stays '
            .'answerable — the same three months as the business audit trail it mirrors. '
            .'Expiring an event removes the runs it caused, by the cascade.'
        );
    }

    public const UPDATED_AT = null;
    public const CREATED_AT = null;   // occurred_at is the real timestamp

    /**
     * How deep a cascade may go.
     *
     * An automation updating a field emits an update event, which may trigger
     * another automation. Five is enough for any rule anyone means to write and
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
        'correlation_id', 'occurred_at', 'available_at', 'scope_snapshot',
    ];

    /**
     * `delivered_at` MEANS DELIVERED TO THE ENGINE. IT IS NOT ABOUT EMAIL.
     *
     * It is the moment this outbox row was handed to the rules that act on it —
     * nothing more. It says nothing about whether anything reached a person, and
     * the application cannot know that at all: there is no SES event ingestion,
     * no SNS route and no bounce handler, so delivered, bounced and opened are
     * not facts EzBizi holds about any message it sends.
     *
     * Written here because the NAME argues for the wrong word. The first screen
     * to show an email-sending automation step will be reading this table, one
     * column away from a field called `delivered_at`, and "Delivered" is the
     * single most natural label to reach for and the one thing that cannot be
     * said. The vocabulary that CAN be said is: Sent (handed over), Couldn't
     * send and which reason, and — for a box whose transport goes nowhere,
     * see `MailStatus` — that this installation is not sending mail at all.
     *
     * `available_at`, `attempts` and `failed_at` beside it are the honest pair
     * to that: they describe waiting to RUN, which is real and observable here.
     * Waiting to run and waiting to arrive look identical on a screen and mean
     * opposite things to somebody working out why a customer never heard back.
     *
     * Raised by the UI/UX session on 2026-09-14, after measuring that the app
     * cannot observe delivery.
     */
    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'scope_snapshot' => 'array',
            'version_ids' => 'array',
            'processing_until' => 'datetime',
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
            ->join('automation_runs', 'automation_runs.event_id', '=', 'automation_events.id')
            // Grouped and qualified. Ungrouped, `A or B and C` binds as
            // `A or (B and C)`, which quietly counts every run in the
            // organization rather than this cascade's — and `id` is ambiguous
            // once both tables are in the query.
            ->where(function ($q) use ($root) {
                $q->where('automation_events.root_event_id', $root)
                    ->orWhere('automation_events.id', $root);
            })
            ->sum('automation_runs.action_count');
    }
}
