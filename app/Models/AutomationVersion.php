<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * An immutable automation definition (plan §5.5).
 *
 * Editing an automation writes a new version and repoints the automation at it.
 * Nothing ever edits one of these, because every run records which version it
 * executed — and a version that can change turns the run log into a description
 * of a rule that no longer exists.
 */
class AutomationVersion extends Model implements StatesRetention
{
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const UPDATED_AT = null;   // versions are written once

    /**
     * Never, deliberately — and this is the weaker of the two `forever()`s here.
     *
     * A version is what a run points at, so it is how "what did this rule
     * actually do in March?" stays answerable. Runs expire after three months
     * with the events that caused them, which means a version older than that
     * with no live runs is arguably dead weight.
     *
     * Kept anyway, because the growth is one row per human edit of an
     * automation, and because a version is also the thing an operator restores
     * from after a bad change. Bounded by how often people edit rules, which is
     * not a number that runs away. Revisit if it ever does; do not leave it
     * undecided in the meantime.
     */
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Kept for as long as the automation exists. It is the record of what a rule '
            .'did at the time, and the version an operator restores from after an edit '
            .'that went wrong. One row per human edit, so it does not accumulate with use.'
        );
    }

    protected $fillable = [
        'organization_id', 'automation_id', 'version', 'conditions', 'actions', 'created_by',
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
                'Automation versions are immutable. Changing a rule means creating a '
                .'new version — editing this one would rewrite the meaning of every '
                .'run that already referenced it.'
            );
        });
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}
