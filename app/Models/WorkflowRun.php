<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one workflow did about one event (plan §5.5).
 *
 * Written for skipped runs as well as acted-on ones. "The rule did not match"
 * and "the rule never saw it" are completely different answers to give someone
 * asking why their automation did not fire, and only a recorded skip can tell
 * them apart.
 */
class WorkflowRun extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const UPDATED_AT = null;
    public const CREATED_AT = null;

    public const SKIPPED   = 'skipped';
    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';

    protected $fillable = [
        'organization_id', 'workflow_id', 'workflow_version_id', 'event_id',
        'status', 'condition_trace', 'action_results', 'error', 'is_test',
        'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'condition_trace' => 'array',
            'action_results'  => 'array',
            'is_test'         => 'boolean',
            'started_at'      => 'datetime',
            'finished_at'     => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(WorkflowEvent::class, 'event_id');
    }
}
