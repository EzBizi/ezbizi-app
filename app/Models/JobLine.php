<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of work on a job (spec §7).
 *
 * `name` and `unit_price_minor` are SNAPSHOTS taken when the line was added,
 * not lookups through `service_id`. The catalogue is a current fact and a job
 * is a historical one — a price raised in September must not rewrite what a
 * job in August came to, and the Revenue widget must not report a past month
 * differently each time it is opened.
 */
#[Fillable([
    'organization_id', 'service_id', 'name', 'unit_price_minor', 'qty',
    'amount_minor', 'position',
])]
class JobLine extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent(
            'A line of work hangs off the job. It names a service and a price and '
            .'holds no information about any person. Removing the job takes it.'
        );
    }

    protected function casts(): array
    {
        return [
            'unit_price_minor' => 'integer',
            'qty'              => 'integer',
            'amount_minor'     => 'integer',
            'position'         => 'integer',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
