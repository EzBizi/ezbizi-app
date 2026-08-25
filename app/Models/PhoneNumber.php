<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\PhoneNumberFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Twilio number hung on org / company / franchise (spec §4).
 *
 * Reach is not cascade: a franchise number does not inherit company lines.
 */
class PhoneNumber extends Model implements StatesRetention
{
    /** @use HasFactory<PhoneNumberFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY = 'company';
    public const SCOPE_FRANCHISE = 'franchise';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'A live phone line the organization has bought or ported. Held while the number '
            .'is on the account; releasing it deletes the row. Call and SMS history hang off '
            .'other tables with their own windows.'
        );
    }

    protected $fillable = [
        'organization_id', 'connection_id', 'scope_type', 'scope_id',
        'twilio_sid', 'e164', 'label',
        'flow_id', 'hours_schedule_id', 'ring_group_id',
        'record', 'transcribe',
        'after_hours', 'after_hours_number', 'language',
        'messaging_service_sid', 'sms_outbound_allowed', 'emergency_address_set',
    ];

    protected function casts(): array
    {
        return [
            'record' => 'boolean',
            'transcribe' => 'boolean',
            'sms_outbound_allowed' => 'boolean',
            'emergency_address_set' => 'boolean',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(PhoneConnection::class, 'connection_id');
    }

    public function grants(): HasMany
    {
        return $this->hasMany(PhoneNumberGrant::class, 'number_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(PhoneCall::class, 'number_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PhoneMessage::class, 'number_id');
    }
}
