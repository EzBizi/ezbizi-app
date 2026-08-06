<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable workflow definition (plan §5.5).
 *
 * Editing a workflow writes a new version and repoints the workflow at it.
 * Nothing ever edits one of these, because every run records which version it
 * executed — and a version that can change turns the run log into a description
 * of a rule that no longer exists.
 */
class WorkflowVersion extends Model
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const UPDATED_AT = null;   // versions are written once

    protected $fillable = [
        'organization_id', 'workflow_id', 'version', 'conditions', 'actions', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions'    => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException(
                'Workflow versions are immutable. Changing a rule means creating a '
                .'new version — editing this one would rewrite the meaning of every '
                .'run that already referenced it.'
            );
        });
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }
}
