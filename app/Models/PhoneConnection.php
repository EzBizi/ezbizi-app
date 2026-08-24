<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\MasksSensitiveValues;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use Database\Factories\PhoneConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Twilio account link at organization or company scope (spec §4).
 *
 * Secrets follow the same three holds as Mailbox: encrypted columns,
 * MasksSensitiveValues, mayReveal false.
 *
 * `credentials` = API key secret (REST / Access Tokens).
 * `auth_token` = Account Auth Token (X-Twilio-Signature only).
 */
class PhoneConnection extends Model implements HoldsSensitiveValues, StatesRetention
{
    /** @use HasFactory<PhoneConnectionFactory> */
    use HasFactory;
    use HasUuids;
    use BelongsToTenant;
    use MasksSensitiveValues;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY = 'company';

    public const KIND_BYO = 'byo';
    public const KIND_SUBACCOUNT = 'subaccount';

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_BROKEN = 'broken';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The live Twilio link for a company or the organization. One row per scope, '
            .'held while connected and removed when disconnected. Expiring it would silently '
            .'cut every number that walks up to this connection.'
        );
    }

    /**
     * `credentials` and `auth_token` are absent on purpose — written by
     * writeSecrets(), not mass assignment.
     */
    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id',
        'kind', 'account_sid', 'api_key_sid', 'twiml_app_sid',
        'status', 'status_reason',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
            'auth_token' => 'encrypted',
        ];
    }

    /**
     * Persist the API key secret and the account auth token together.
     */
    public function writeSecrets(string $apiSecret, string $authToken): void
    {
        $this->forceFill([
            'credentials' => $apiSecret,
            'auth_token' => $authToken,
        ])->save();
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'connection_id');
    }

    public function hoursSchedules(): HasMany
    {
        return $this->hasMany(PhoneHoursSchedule::class, 'connection_id');
    }

    public function ringGroups(): HasMany
    {
        return $this->hasMany(PhoneRingGroup::class, 'connection_id');
    }

    public function flows(): HasMany
    {
        return $this->hasMany(PhoneFlow::class, 'connection_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(PhoneCall::class, 'connection_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PhoneMessage::class, 'connection_id');
    }

    public function presences(): HasMany
    {
        return $this->hasMany(PhonePresence::class, 'connection_id');
    }

    public function sensitiveAttributes(): array
    {
        return ['credentials', 'auth_token'];
    }

    /**
     * Nobody — same argument as Mailbox. Re-enter the secret; do not read it back.
     */
    public function mayReveal(User $actor): bool
    {
        return false;
    }
}
