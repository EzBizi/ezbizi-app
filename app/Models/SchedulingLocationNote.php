<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's note for new dispatchers on Scheduling › Locations. */
#[Fillable(['organization_id', 'company_id', 'text', 'updated_by'])]
class SchedulingLocationNote extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How the business briefs its own dispatchers: part of how it is set up, '
            .'kept until somebody edits or clears it, and gone with the company.'
        );
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
