<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one automation did about one event (plan §5.5).
 *
 * Written for skipped runs as well as acted-on ones. "The rule did not match"
 * and "the rule never saw it" are completely different answers to give someone
 * asking why their automation did not fire, and only a recorded skip can tell
 * them apart.
 */
class AutomationRun extends Model implements StatesRetention
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use \App\Support\Live\RecordsParentChanges;

    /** A write here is its rule's change on the live feed (RecordsParentChanges). */
    protected static array $changeParent = ['automations', Automation::class, 'automation_id'];


    /**
     * Goes with the event that caused it — one number, in one place.
     *
     * A window of its own here would be a second number governing one history,
     * and the day the two disagreed the system would hold runs whose events had
     * expired: "this rule fired" with no record of what it fired ABOUT, which is
     * the one state a run log must never be in.
     *
     * `automation_runs.event_id` has `cascadeOnDelete`, so this is enforced by the
     * database rather than by the sweep remembering to do it in the right order.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A run explains one event, and is removed with it by the cascade when that '
            .'event expires. Keeping runs longer would leave "the rule fired" with no '
            .'record of what it fired about.'
        );
    }

    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    /**
     * Claimed and in flight.
     *
     * Written BEFORE the actions run, so the unique index can refuse a
     * redelivery while it can still prevent the side effect. A row left in this
     * state is a delivery that died mid-flight, which is worth being able to see.
     */
    public const MATCHED   = 'matched';

    public const SKIPPED   = 'skipped';
    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';

    protected $fillable = [
        'organization_id', 'automation_id', 'automation_version_id', 'event_id',
        'status', 'condition_trace', 'action_results', 'action_count', 'error',
        'is_test', 'started_at', 'finished_at', 'actions_snapshot', 'context_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'condition_trace' => 'array',
            'action_results'  => 'array',
            'actions_snapshot' => 'array',
            'context_snapshot' => 'array',
            'is_test'         => 'boolean',
            'started_at'      => 'datetime',
            'finished_at'     => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(AutomationEvent::class, 'event_id');
    }
}
