<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tracker or dash cam in a van, linked to its Fleet vehicle. Holds the
 * latest reading; the history is {@see VehiclePosition}.
 */
class TelematicsDevice extends Model implements StatesRetention
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'organization_id', 'connection_id', 'external_id', 'label', 'kind', 'vehicle_id',
        'latitude', 'longitude', 'speed_mph', 'heading', 'ignition_on', 'odometer_mi', 'fuel_pct',
        'fault_codes', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float', 'longitude' => 'float', 'ignition_on' => 'boolean',
            'fault_codes' => 'array', 'last_seen_at' => 'datetime',
        ];
    }

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::withParent('A device of its connection, deleted with it. Its latest reading describes the van, not a person.');
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TelematicsConnection::class, 'connection_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
