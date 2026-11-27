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
        'push_credential_ios_sid', 'push_credential_android_sid',
        'status', 'status_reason',

        // A company-wide rule: must the person who took a call say what it
        // was about before the desk is theirs again. See the migration for
        // why a company setting lives on this row.
        'require_call_tag',

        // Seconds somebody is left alone after a call to finish writing it
        // up. Zero is off. Company-wide for the same reason the tag rule is:
        // a rule set per line is one somebody has to set seven times.
        'wrap_up_seconds',

        // What answers a call, decided once for the company. A line may still
        // differ — resolution is `number ?? connection`, see
        // PhoneNumber::resolved() — but nothing has to be set seven times.
        'default_flow_id', 'default_hours_schedule_id', 'default_ring_group_id',
        'default_voicemail_box_id', 'default_fallback_number',
        'default_recording_notice', 'default_record', 'default_transcribe',
        'default_voicemail_transcribe',
        'default_after_hours', 'default_after_hours_number',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted',
            'auth_token' => 'encrypted',
            'previous_auth_token' => 'encrypted',
            'require_call_tag' => 'boolean',
            'wrap_up_seconds' => 'integer',
            'default_record' => 'boolean',
            'default_transcribe' => 'boolean',
            'default_voicemail_transcribe' => 'boolean',
        ];
    }

    /**
     * The push credential that can wake a handset on `$platform`, or null.
     *
     * Null for a browser, which needs none, and null for a company whose
     * credential has not been created yet — both mean "mint the token without
     * one" rather than "refuse", because refusing would take the desk phone
     * down to fix the mobile one.
     */
    public function pushCredentialFor(?string $platform): ?string
    {
        $sid = match ($platform) {
            'ios' => $this->push_credential_ios_sid,
            'android' => $this->push_credential_android_sid,
            default => null,
        };

        return ($sid === null || $sid === '') ? null : (string) $sid;
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

    /**
     * The Twilio link stopped working. `$reason` is a sentence somebody reads.
     */
    public function markBroken(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_BROKEN,
            'status_reason' => $reason,
        ]);
    }

    public function numbers(): HasMany
    {
        return $this->hasMany(PhoneNumber::class, 'connection_id');
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

    public function sensitiveAttributes(): array
    {
        return ['credentials', 'auth_token', 'previous_auth_token'];
    }

    /**
     * Nobody — same argument as Mailbox. Re-enter the secret; do not read it back.
     */
    public function mayReveal(User $actor): bool
    {
        return false;
    }
}
