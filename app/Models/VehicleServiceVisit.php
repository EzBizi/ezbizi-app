<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAttachments;
use App\Support\Fleet\VehicleServiceKind;
use Database\Factories\VehicleServiceVisitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One service / maintenance log entry on a vehicle. */
#[Fillable([
    'vehicle_id', 'kind', 'occurred_on', 'odometer_miles', 'performed_by',
    'notes', 'next_service_on', 'next_service_miles',
])]
class VehicleServiceVisit extends Model
{
    /** @use HasFactory<VehicleServiceVisitFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use HasAttachments;

    protected function casts(): array
    {
        return [
            'kind' => VehicleServiceKind::class,
            'occurred_on' => 'date',
            'odometer_miles' => 'integer',
            'next_service_on' => 'date',
            'next_service_miles' => 'integer',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
