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
 * Open hours for numbers on a connection (weeks JSON + exceptions).
 */
class PhoneHoursSchedule extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasFactory;
    use HasUuids;

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Named open-hours calendars for a connection. Live dial-plan config, removed '
            .'when the schedule is deleted or the connection goes.'
        );
    }

    protected $fillable = [
        'scope_type', 'scope_id',
        'organization_id', 'connection_id', 'name', 'timezone', 'weeks', 'exceptions',
    ];

    protected function casts(): array
    {
        return [
            'weeks' => 'array',
            'exceptions' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
