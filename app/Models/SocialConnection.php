<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Privacy\HoldsSensitiveValues;
use App\Support\Privacy\MasksSensitiveValues;
use App\Support\Privacy\RetentionPolicy;
use App\Support\Privacy\StatesRetention;
use App\Support\Social\SocialProvider;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A connected page or profile a tenant posts as.
 *
 * XOR-scoped (org or company or franchise), same as a mailbox. Tokens are
 * encrypted, masked on every serialisation, and unrevealable through any API
 * path. The row is the connection, not a copy of the remote feed.
 */
class SocialConnection extends Model implements HoldsSensitiveValues, StatesRetention
{
    use BelongsToTenant;
    use HasUuids;
    use MasksSensitiveValues;

    public const SCOPE_ORGANIZATION = 'organization';
    public const SCOPE_COMPANY      = 'company';
    public const SCOPE_FRANCHISE    = 'franchise';

    public const STATUS_CONNECTED = 'connected';
    public const STATUS_BROKEN    = 'broken';
    public const STATUS_REVOKED   = 'revoked';

    public static function retention(): RetentionPolicy
    {
        return RetentionPolicy::forever(
            'The connection to a social page or profile — which network it is on, the name '
            .'shown on screen, and the key that posts as it. One row per connected account '
            .'the organization has, held for as long as it is connected and deleted when '
            .'somebody disconnects it. EzBizi does not store a copy of the remote feed. Who '
            .'connected it and when is in the audit log, which does expire.'
        );
    }

    protected $fillable = [
        'organization_id', 'scope_type', 'scope_id',
        'provider', 'remote_id', 'display_name', 'handle', 'label',
        'leads_franchise_id',
    ];

    protected function casts(): array
    {
        return [
            'credentials'     => 'encrypted:array',
            'last_reached_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function providerEnum(): ?SocialProvider
    {
        return SocialProvider::tryFrom((string) $this->provider);
    }

    public function providerLabel(): string
    {
        return $this->providerEnum()?->label() ?? (string) $this->provider;
    }

    /**
     * @param  array{refresh_token: string, access_token?: string, scopes?: string[], expires_at?: string|null, refresh_expires_at?: string|null}  $tokens
     */
    public function connect(array $tokens): void
    {
        $this->forceFill([
            'credentials'     => $tokens,
            'status'          => self::STATUS_CONNECTED,
            'status_reason'   => null,
            'last_reached_at' => now(),
        ]);
    }

    public function refreshToken(): ?string
    {
        $credentials = $this->getAttribute('credentials');
        $token       = is_array($credentials) ? ($credentials['refresh_token'] ?? null) : null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    public function sensitiveAttributes(): array
    {
        return ['credentials'];
    }

    public function mayReveal(\App\Models\User $actor): bool
    {
        return false;
    }
}
