<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This role may start that named inspection."
 *
 * Same shape and the same reason as MailboxGrant: this is tenant data, not a
 * row in `permissions`. A permission per named inspection would write one
 * tenant's inspection titles into a platform table every other tenant reads.
 */
class InspectionDefinitionGrant extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'One row for each role an organization has offered a named inspection to. It '
            .'is removed when the offer is taken away, when the inspection is deleted, and '
            .'when the role is deleted. It holds no information about any person — a role '
            .'is not somebody\'s name.'
        );
    }

    protected $fillable = ['organization_id', 'inspection_definition_id', 'role_id'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(InspectionDefinition::class, 'inspection_definition_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
