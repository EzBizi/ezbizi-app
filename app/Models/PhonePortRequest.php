<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Twilio port-in request for numbers on a connection (spec §6).
 */
class PhonePortRequest extends Model implements StatesRetention
{
    use BelongsToTenant;
    use HasUuids;

    public const STATUS_WAITING_FOR_SIGNATURE = 'waiting_for_signature';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_ACTION_REQUIRED = 'action_required';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELED = 'canceled';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'Port-in status for numbers moving onto a connection. Kept while the organization '
            .'exists so Settings can show why a port stalled.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'e164s',
        'twilio_sid', 'status', 'status_reason', 'carrier',
    ];

    protected function casts(): array
    {
        return [
            'e164s' => 'array',

            // The losing carrier's details and the port PIN, encrypted at
            // rest: the account number and PIN are what move a number.
            'carrier' => 'encrypted:array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }
}
