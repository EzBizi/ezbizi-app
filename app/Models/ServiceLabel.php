<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\ServiceLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A label on the service catalogue (spec). */
#[Fillable(['company_id', 'franchise_id', 'name'])]
class ServiceLabel extends Model implements StatesRetention
{
    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'How this organization tagged the work it sold. Removing the '
            .'organization takes the row. Empty mistakes can still be deleted.'
        );
    }

    /** @use HasFactory<ServiceLabelFactory> */
    use BelongsToTenant;
    use \App\Support\Live\RecordsChanges;

    /** The change feed's name for this record (ChangeFeed::TYPES): groups and labels together. */
    protected static string $changeType = 'service_taxonomy';

    use HasFactory;
    use HasUuids;

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function franchise(): BelongsTo
    {
        return $this->belongsTo(Franchise::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class, 'label_id');
    }
}
