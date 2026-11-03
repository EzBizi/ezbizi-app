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
            .'shown on screen, the Google login it was signed in with, and the key that posts as it. One row per connected account '
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
            'is_manager'      => 'boolean',
        ];
    }

    /** The Business Profile listing a Local Services account serves from (AccountProfileLink). */
    public function businessLocation(): BelongsTo
    {
        return $this->belongsTo(BusinessLocation::class);
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
     * ── SEVERAL ROWS MAY CARRY THE SAME GRANT, and nothing here says so ──
     *
     * One consent can produce many rows. `identify()` returns an account per
     * BUSINESS or per PAGE, so a single Thumbtack login writes one row per
     * business — twelve, for A-1 Chimney — each holding an identical token
     * pair. Google is the same shape: forty-six connections, most of them from
     * one consent.
     *
     * There is NO column saying which rows share a grant. The only way to know
     * is to decrypt `credentials` and compare, because the cast encrypts per
     * row and identical values give different ciphertext — so no query can
     * find them.
     *
     * WHY THAT IS SAFE, and only because of one reader: when a provider ROTATES
     * its refresh token on use, the rotated pair must reach every row holding
     * the spent one, or the others replay a dead token. Eleven such replays on
     * 2026-09-09 read to Thumbtack as a stolen token and it revoked the whole
     * family — every connection dead an hour after connecting, repairable only
     * by reconnecting. `ThumbtackClient::refreshAccessToken()` is what fans the
     * new pair out, and it is the ONLY thing standing between duplicate rows
     * and that outage.
     *
     * Google survives the same shape for a different reason, not a better one:
     * `GoogleToken` never writes a refresh token back at all, because Google's
     * do not rotate. That is a property of Google, not a property of this
     * model.
     *
     * SO: if you add a provider that rotates refresh tokens, or any new code
     * that writes a token onto a connection, it must fan out the same way. The
     * failure is silent, arrives an hour late, and cannot be undone.
     *
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
